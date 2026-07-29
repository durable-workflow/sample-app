use std::{collections::BTreeMap, env, time::Duration};

use apache_avro::{from_avro_datum, to_avro_datum, Schema};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use durable_workflow::{json, ActivityOptions, AvroValue, Client, Error, Result, Value, Worker};

const RUST_SAME_WORKFLOW: &str = "polyglot.rust.greeter";
const RUST_TO_PYTHON_WORKFLOW: &str = "polyglot.rust-to-python.greeter";
const RUST_TO_PHP_WORKFLOW: &str = "polyglot.rust-to-php.greeter";
const RUST_TO_PYTHON_TYPES: &str = "polyglot.rust-to-python.type-roundtrip";
const RUST_TO_PHP_TYPES: &str = "polyglot.rust-to-php.type-roundtrip";
const RUST_SIGNAL_QUERY: &str = "polyglot.rust.signal-query";
const SIGNAL_NAME: &str = "polyglot-signal";

#[derive(Clone, Default)]
struct SignalState {
    request: Value,
    signals: Vec<Value>,
    stage: String,
}

#[tokio::main]
async fn main() -> Result<()> {
    verify_official_avro_runtime()?;

    let server_url = required_env("DURABLE_WORKFLOW_SERVER_URL");
    let token = env::var("DURABLE_WORKFLOW_AUTH_TOKEN").ok();
    let namespace = env::var("DURABLE_WORKFLOW_NAMESPACE").unwrap_or_else(|_| "default".into());
    let mode = env::var("POLYGLOT_RUST_MODE").unwrap_or_else(|_| "workflow".into());

    let client = Client::builder(server_url)
        .token(token)
        .namespace(namespace)
        .build()?;

    match mode.as_str() {
        "workflow" => run_workflow_worker(client).await,
        "activity" => run_activity_worker(client).await,
        other => panic!("unsupported POLYGLOT_RUST_MODE {other:?}; expected workflow or activity"),
    }
}

async fn run_workflow_worker(client: Client) -> Result<()> {
    let rust_queue = env_value("POLYGLOT_RUST_TASK_QUEUE", "polyglot-rust");
    let python_queue = env_value("POLYGLOT_PHP2PY_TASK_QUEUE", "polyglot-php-to-python");
    let php_queue = env_value("POLYGLOT_PY2PHP_TASK_QUEUE", "polyglot-python-to-php");
    let mut worker = Worker::new(client, rust_queue.clone())
        .worker_id("rust-workflow-worker")
        .poll_timeout(Duration::from_secs(5));

    worker.register_activity("polyglot.rust.echo", |_ctx, args| async move {
        Ok(runtime_echo(first_argument(&args)))
    });

    worker.register_workflow(RUST_SAME_WORKFLOW, |ctx, input| async move {
        let request = first_argument(&input);
        let echo = ctx
            .activity("polyglot.rust.echo", json!([request.clone()]))
            .await?;
        Ok(workflow_observation("rust", "rust", request, echo))
    });

    let rust_to_python_queue = python_queue.clone();
    worker.register_workflow(RUST_TO_PYTHON_WORKFLOW, move |ctx, input| {
        let task_queue = rust_to_python_queue.clone();
        async move {
            let request = first_argument(&input);
            let echo = ctx
                .activity_on_queue(
                    "polyglot.rust-to-python.echo",
                    Some(task_queue),
                    json!([request.clone()]),
                )
                .await?;
            Ok(workflow_observation("rust", "python", request, echo))
        }
    });

    let rust_to_php_queue = php_queue.clone();
    worker.register_workflow(RUST_TO_PHP_WORKFLOW, move |ctx, input| {
        let task_queue = rust_to_php_queue.clone();
        async move {
            let request = first_argument(&input);
            let echo = ctx
                .activity_on_queue(
                    "polyglot.rust-to-php.echo",
                    Some(task_queue),
                    json!([request.clone()]),
                )
                .await?;
            Ok(workflow_observation("rust", "php", request, echo))
        }
    });

    let types_to_python_queue = python_queue;
    worker.register_workflow(RUST_TO_PYTHON_TYPES, move |ctx, input| {
        let task_queue = types_to_python_queue.clone();
        async move {
            let payload = first_argument(&input);
            let wire_payload = native_binary_payload(&payload)?;
            let echo = ctx
                .activity_avro_value_with_options(
                    "polyglot.rust-to-python.binary-echo",
                    ActivityOptions::new().task_queue(task_queue),
                    AvroValue::Array(vec![wire_payload]),
                )
                .await?;
            type_observation("rust", "python", payload, echo)
        }
    });

    let types_to_php_queue = php_queue;
    worker.register_workflow(RUST_TO_PHP_TYPES, move |ctx, input| {
        let task_queue = types_to_php_queue.clone();
        async move {
            let payload = first_argument(&input);
            let wire_payload = native_binary_payload(&payload)?;
            let echo = ctx
                .activity_avro_value_with_options(
                    "polyglot.rust-to-php.binary-echo",
                    ActivityOptions::new().task_queue(task_queue),
                    AvroValue::Array(vec![wire_payload]),
                )
                .await?;
            type_observation("rust", "php", payload, echo)
        }
    });

    worker.register_replayed_workflow(
        RUST_SIGNAL_QUERY,
        SignalState::default,
        |ctx, input, state| async move {
            let request = first_argument(&input);
            state.update(|current| {
                current.request = request.clone();
                current.stage = "waiting".into();
            })?;

            let first = ctx.wait_signal(SIGNAL_NAME).await?;
            let first = first.into_iter().next().unwrap_or(Value::Null);
            state.update(|current| {
                current.signals.push(first.clone());
                current.stage = "signaled".into();
            })?;

            let second = ctx.wait_signal(SIGNAL_NAME).await?;
            let second = second.into_iter().next().unwrap_or(Value::Null);
            state.update(|current| current.signals.push(second))?;

            Ok(json!({
                "workflow_runtime": "rust",
                "request": request,
                "signal": first,
                "codec": avro_observation(),
            }))
        },
    );
    worker.register_replayed_query::<SignalState, _, _>(
        RUST_SIGNAL_QUERY,
        "state",
        |_ctx, state, _args| async move {
            Ok(json!({
                "workflow_runtime": "rust",
                "stage": state.stage,
                "signal_count": state.signals.len(),
                "signals": state.signals,
                "request": state.request,
                "codec": avro_observation(),
            }))
        },
    );

    println!(
        "polyglot rust workflow worker starting: id=rust-workflow-worker queue={rust_queue} sdk={} avro={}",
        required_env("DURABLE_WORKFLOW_RUST_SDK_VERSION"),
        required_env("APACHE_AVRO_RUST_VERSION"),
    );
    worker.run().await
}

async fn run_activity_worker(client: Client) -> Result<()> {
    let task_queue = env_value("POLYGLOT_TO_RUST_TASK_QUEUE", "polyglot-to-rust");
    let mut worker = Worker::new(client, task_queue.clone())
        .worker_id("rust-activity-worker")
        .poll_timeout(Duration::from_secs(5));

    for activity_type in ["polyglot.php-to-rust.echo", "polyglot.python-to-rust.echo"] {
        worker.register_activity_avro_value(activity_type, |_ctx, args| async move {
            native_runtime_echo(first_avro_argument(args))
        });
    }
    for activity_type in [
        "polyglot.php-to-rust.binary-echo",
        "polyglot.python-to-rust.binary-echo",
    ] {
        worker.register_activity_avro_value(activity_type, |_ctx, args| async move {
            native_binary_runtime_echo(first_avro_argument(args))
        });
    }

    println!(
        "polyglot rust activity worker starting: id=rust-activity-worker queue={task_queue} sdk={} avro={}",
        required_env("DURABLE_WORKFLOW_RUST_SDK_VERSION"),
        required_env("APACHE_AVRO_RUST_VERSION"),
    );
    worker.run().await
}

fn workflow_observation(
    workflow_runtime: &str,
    activity_runtime: &str,
    request: Value,
    echo: Value,
) -> Value {
    json!({
        "workflow_runtime": workflow_runtime,
        "activity_runtime": activity_runtime,
        "request": request,
        "echo": echo,
        "codec": avro_observation(),
    })
}

fn type_observation(
    workflow_runtime: &str,
    activity_runtime: &str,
    payload: Value,
    echo: AvroValue,
) -> Result<Value> {
    let (echo, activity_evidence, workflow_evidence) =
        complete_native_binary_roundtrip(&payload, echo, workflow_runtime)?;

    Ok(json!({
        "workflow_runtime": workflow_runtime,
        "activity_runtime": activity_runtime,
        "input": payload,
        "echo": echo,
        "binary_evidence": {
            "activity": activity_evidence,
            "workflow": workflow_evidence,
        },
        "codec": avro_observation(),
    }))
}

fn runtime_echo(value: Value) -> Value {
    json!({
        "runtime": "rust",
        "value": value,
        "codec": avro_observation(),
    })
}

fn native_runtime_echo(value: AvroValue) -> Result<AvroValue> {
    Ok(AvroValue::Map(BTreeMap::from([
        ("runtime".into(), AvroValue::String("rust".into())),
        ("value".into(), value),
        ("codec".into(), json_to_avro_value(&avro_observation())?),
    ])))
}

fn native_binary_runtime_echo(value: AvroValue) -> Result<AvroValue> {
    let evidence = native_binary_evidence(&value, "rust")?;
    let AvroValue::Map(mut result) = native_runtime_echo(value)? else {
        unreachable!("native runtime echo always returns a map");
    };
    result.insert("binary_evidence".into(), evidence);

    Ok(AvroValue::Map(result))
}

fn avro_observation() -> Value {
    json!({
        "codec": "avro",
        "implementation": "apache-avro",
        "package": "apache-avro",
        "version": required_env("APACHE_AVRO_RUST_VERSION"),
        "schema": "durable_workflow.protocol.Value",
        "fingerprint": durable_workflow::AVRO_VALUE_SCHEMA_FINGERPRINT_HEX,
        "framing": "single_object",
    })
}

fn first_argument(value: &Value) -> Value {
    value
        .as_array()
        .and_then(|items| items.first())
        .cloned()
        .unwrap_or(Value::Null)
}

fn first_avro_argument(value: AvroValue) -> AvroValue {
    match value {
        AvroValue::Array(items) => items.into_iter().next().unwrap_or(AvroValue::Null),
        other => other,
    }
}

fn native_binary_payload(payload: &Value) -> Result<AvroValue> {
    let AvroValue::Map(mut payload) = json_to_avro_value(payload)? else {
        return Err(Error::Codec(
            "native binary type-matrix payload must be a map".into(),
        ));
    };
    let encoded = avro_map_string(&payload, "binary_base64")?;
    let text = avro_map_string(&payload, "binary_text")?;
    let bytes = BASE64.decode(encoded).map_err(|error| {
        Error::Codec(format!(
            "native binary type-matrix fixture must contain strict base64: {error}"
        ))
    })?;
    if bytes == text.as_bytes() {
        return Err(Error::Codec(
            "native binary fixture must be distinct from its UTF-8 text companion".into(),
        ));
    }
    payload.insert("binary_native".into(), AvroValue::Bytes(bytes));

    Ok(AvroValue::Map(payload))
}

fn native_binary_evidence(value: &AvroValue, runtime: &str) -> Result<AvroValue> {
    let AvroValue::Map(value) = value else {
        return Err(Error::Codec(
            "native binary worker payload must be a map".into(),
        ));
    };
    let encoded = avro_map_string(value, "binary_base64")?;
    let text = avro_map_string(value, "binary_text")?;
    let binary = match value.get("binary_native") {
        Some(AvroValue::Bytes(binary)) => binary,
        Some(other) => {
            return Err(Error::Codec(format!(
                "expected native Rust AvroValue::Bytes, received {other:?}"
            )))
        }
        None => {
            return Err(Error::Codec(
                "native Rust bytes are missing from the worker payload".into(),
            ))
        }
    };
    let expected = BASE64.decode(encoded).map_err(|error| {
        Error::Codec(format!(
            "native binary type-matrix fixture must contain strict base64: {error}"
        ))
    })?;
    if binary != &expected {
        return Err(Error::Codec(
            "native Rust bytes changed across the worker boundary".into(),
        ));
    }
    if binary == text.as_bytes() {
        return Err(Error::Codec(
            "native Rust bytes collapsed into the UTF-8 text value".into(),
        ));
    }
    let byte_length = i64::try_from(binary.len())
        .map_err(|_| Error::Codec("native binary fixture is too large".into()))?;

    Ok(AvroValue::Map(BTreeMap::from([
        ("runtime".into(), AvroValue::String(runtime.into())),
        (
            "native_type".into(),
            AvroValue::String("AvroValue::Bytes".into()),
        ),
        ("base64".into(), AvroValue::String(BASE64.encode(binary))),
        ("byte_length".into(), AvroValue::Long(byte_length)),
        ("matches_expected".into(), AvroValue::Boolean(true)),
        ("text_type".into(), AvroValue::String("String".into())),
        ("text_value".into(), AvroValue::String(text.into())),
        ("text_and_bytes_distinct".into(), AvroValue::Boolean(true)),
    ])))
}

fn complete_native_binary_roundtrip(
    payload: &Value,
    echo: AvroValue,
    workflow_runtime: &str,
) -> Result<(Value, Value, Value)> {
    let AvroValue::Map(mut echo) = echo else {
        return Err(Error::Codec(
            "activity did not return a native binary map".into(),
        ));
    };
    let echoed_value = echo
        .get("value")
        .ok_or_else(|| Error::Codec("activity did not echo the native binary payload".into()))?;
    let workflow_evidence = native_binary_evidence(echoed_value, workflow_runtime)?;
    let activity_evidence = echo.get("binary_evidence").cloned().ok_or_else(|| {
        Error::Codec("activity did not return executable native binary evidence".into())
    })?;
    echo.insert("value".into(), json_to_avro_value(payload)?);

    Ok((
        avro_to_json(AvroValue::Map(echo))?,
        avro_to_json(activity_evidence)?,
        avro_to_json(workflow_evidence)?,
    ))
}

fn avro_map_string<'a>(value: &'a BTreeMap<String, AvroValue>, key: &str) -> Result<&'a str> {
    match value.get(key) {
        Some(AvroValue::String(value)) => Ok(value),
        _ => Err(Error::Codec(format!(
            "native binary type-matrix field {key:?} must be a string"
        ))),
    }
}

fn json_to_avro_value(value: &Value) -> Result<AvroValue> {
    match value {
        Value::Null => Ok(AvroValue::Null),
        Value::Bool(value) => Ok(AvroValue::Boolean(*value)),
        Value::Number(value) => {
            if let Some(value) = value.as_i64() {
                Ok(AvroValue::Long(value))
            } else if let Some(value) = value.as_f64().filter(|value| value.is_finite()) {
                Ok(AvroValue::Double(value))
            } else {
                Err(Error::Codec(
                    "type-matrix number must fit int64 or a finite double".into(),
                ))
            }
        }
        Value::String(value) => Ok(AvroValue::String(value.clone())),
        Value::Array(values) => values
            .iter()
            .map(json_to_avro_value)
            .collect::<Result<Vec<_>>>()
            .map(AvroValue::Array),
        Value::Object(values) => values
            .iter()
            .map(|(key, value)| Ok((key.clone(), json_to_avro_value(value)?)))
            .collect::<Result<BTreeMap<_, _>>>()
            .map(AvroValue::Map),
    }
}

fn avro_to_json(value: AvroValue) -> Result<Value> {
    match value {
        AvroValue::Null => Ok(Value::Null),
        AvroValue::Boolean(value) => Ok(json!(value)),
        AvroValue::Long(value) => Ok(json!(value)),
        AvroValue::Double(value) if value.is_finite() => Ok(json!(value)),
        AvroValue::Double(_) => Err(Error::Codec(
            "type-matrix evidence contains a non-finite double".into(),
        )),
        AvroValue::Bytes(_) => Err(Error::Codec(
            "native bytes must be verified before producing JSON-safe smoke evidence".into(),
        )),
        AvroValue::String(value) => Ok(Value::String(value)),
        AvroValue::Array(values) => values
            .into_iter()
            .map(avro_to_json)
            .collect::<Result<Vec<_>>>()
            .map(Value::Array),
        AvroValue::Map(values) => values
            .into_iter()
            .map(|(key, value)| Ok((key, avro_to_json(value)?)))
            .collect::<Result<_>>()
            .map(Value::Object),
    }
}

fn verify_official_avro_runtime() -> Result<()> {
    let schema = Schema::parse_str(
        r#"{"type":"record","name":"PolyglotProbe","fields":[{"name":"runtime","type":"string"}]}"#,
    )
    .map_err(|error| durable_workflow::Error::Codec(error.to_string()))?;
    let datum = to_avro_datum(
        &schema,
        apache_avro::types::Value::Record(vec![(
            "runtime".into(),
            apache_avro::types::Value::String("rust".into()),
        )]),
    )
    .map_err(|error| durable_workflow::Error::Codec(error.to_string()))?;
    let decoded = from_avro_datum(&schema, &mut datum.as_slice(), None)
        .map_err(|error| durable_workflow::Error::Codec(error.to_string()))?;
    if !matches!(decoded, apache_avro::types::Value::Record(_)) {
        return Err(durable_workflow::Error::Codec(
            "official Apache Avro probe returned the wrong datum type".into(),
        ));
    }
    Ok(())
}

fn env_value(name: &str, fallback: &str) -> String {
    env::var(name).unwrap_or_else(|_| fallback.into())
}

fn required_env(name: &str) -> String {
    env::var(name).unwrap_or_else(|_| panic!("{name} must be set"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn official_apache_avro_probe_round_trips_a_typed_record() {
        verify_official_avro_runtime().expect("official Apache Avro probe");
    }

    #[test]
    fn worker_handlers_take_the_first_wire_argument() {
        assert_eq!(
            first_argument(&json!([{"typed": true}])),
            json!({"typed": true})
        );
        assert_eq!(first_argument(&json!([])), Value::Null);
    }

    #[test]
    fn type_matrix_constructs_and_checks_native_binary_values() {
        let payload = json!({
            "binary_base64": "cG9seWdsb3QtYmluYXJ5AP8B",
            "binary_text": "polyglot-binary",
        });
        let wire_payload = native_binary_payload(&payload).expect("native binary payload");
        let AvroValue::Map(values) = &wire_payload else {
            panic!("wire payload must be a map");
        };
        assert_eq!(
            values.get("binary_native"),
            Some(&AvroValue::Bytes(b"polyglot-binary\x00\xff\x01".to_vec()))
        );

        let evidence =
            avro_to_json(native_binary_evidence(&wire_payload, "rust").expect("evidence"))
                .expect("JSON-safe evidence");
        assert_eq!(evidence["native_type"], "AvroValue::Bytes");
        assert_eq!(evidence["base64"], payload["binary_base64"]);
        assert_eq!(evidence["matches_expected"], true);
        assert_eq!(evidence["text_and_bytes_distinct"], true);
    }

    #[test]
    fn shared_echo_round_trips_fixture_like_keys_as_ordinary_map_fields() {
        env::set_var("APACHE_AVRO_RUST_VERSION", "0.21.0");

        for input in [
            BTreeMap::from([(
                "binary_native".into(),
                AvroValue::String("ordinary native field".into()),
            )]),
            BTreeMap::from([(
                "binary_base64".into(),
                AvroValue::String("ordinary base64 field".into()),
            )]),
            BTreeMap::from([(
                "binary_text".into(),
                AvroValue::String("ordinary text field".into()),
            )]),
            BTreeMap::from([
                (
                    "binary_native".into(),
                    AvroValue::String("ordinary native field".into()),
                ),
                (
                    "binary_base64".into(),
                    AvroValue::String("ordinary base64 field".into()),
                ),
                (
                    "binary_text".into(),
                    AvroValue::String("ordinary text field".into()),
                ),
            ]),
        ] {
            let echo = native_runtime_echo(AvroValue::Map(input.clone())).expect("plain echo");
            let AvroValue::Map(echo) = echo else {
                panic!("echo must be a map");
            };
            assert_eq!(echo.get("value"), Some(&AvroValue::Map(input)));
            assert!(!echo.contains_key("binary_evidence"));
        }
    }

    #[test]
    fn native_binary_echo_rejects_a_partial_binary_fixture() {
        let input = AvroValue::Map(BTreeMap::from([(
            "binary_base64".into(),
            AvroValue::String("AA==".into()),
        )]));

        assert!(native_binary_runtime_echo(input).is_err());
    }
}
