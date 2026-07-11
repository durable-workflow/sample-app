use std::{env, time::Duration};

use apache_avro::{from_avro_datum, to_avro_datum, Schema};
use durable_workflow::{json, Client, Result, Value, Worker};

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
            let echo = ctx
                .activity_on_queue(
                    "polyglot.rust-to-python.echo",
                    Some(task_queue),
                    json!([payload.clone()]),
                )
                .await?;
            Ok(type_observation("rust", "python", payload, echo))
        }
    });

    let types_to_php_queue = php_queue;
    worker.register_workflow(RUST_TO_PHP_TYPES, move |ctx, input| {
        let task_queue = types_to_php_queue.clone();
        async move {
            let payload = first_argument(&input);
            let echo = ctx
                .activity_on_queue(
                    "polyglot.rust-to-php.echo",
                    Some(task_queue),
                    json!([payload.clone()]),
                )
                .await?;
            Ok(type_observation("rust", "php", payload, echo))
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
        worker.register_activity(activity_type, |_ctx, args| async move {
            Ok(runtime_echo(first_argument(&args)))
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
    echo: Value,
) -> Value {
    json!({
        "workflow_runtime": workflow_runtime,
        "activity_runtime": activity_runtime,
        "input": payload,
        "echo": echo,
        "codec": avro_observation(),
    })
}

fn runtime_echo(value: Value) -> Value {
    json!({
        "runtime": "rust",
        "value": value,
        "codec": avro_observation(),
    })
}

fn avro_observation() -> Value {
    json!({
        "codec": "avro",
        "implementation": "apache-avro",
        "package": "apache-avro",
        "version": required_env("APACHE_AVRO_RUST_VERSION"),
    })
}

fn first_argument(value: &Value) -> Value {
    value
        .as_array()
        .and_then(|items| items.first())
        .cloned()
        .unwrap_or(Value::Null)
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
}
