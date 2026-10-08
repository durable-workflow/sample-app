use durable_workflow::{
    decode_payload, json, Client, Error, PayloadEnvelope, QueryContext, Result, Value, Worker,
};

#[derive(Clone, Default)]
struct Counter {
    counter: i64,
    mutations: Vec<String>,
}

impl Counter {
    fn apply(&mut self, arguments: &[Value]) -> Result<()> {
        let request = arguments
            .first()
            .ok_or_else(|| Error::Codec("increment arguments missing".into()))?;
        let delta = request["delta"]
            .as_i64()
            .ok_or_else(|| Error::Codec("increment delta must be an integer".into()))?;
        let request_id = request["request_id"]
            .as_str()
            .ok_or_else(|| Error::Codec("increment request identity missing".into()))?;
        self.counter += delta;
        self.mutations.push(request_id.into());
        Ok(())
    }

    fn value(&self) -> Value {
        json!({"counter": self.counter, "mutations": self.mutations})
    }
}

fn applied_counter(ctx: &QueryContext) -> Result<Counter> {
    let mut counter = Counter::default();
    let mut seen = std::collections::HashSet::new();
    for event in ctx.history_events() {
        if event.event_type != "UpdateApplied" || event.payload["update_name"] != "increment" {
            continue;
        }
        let id = event.payload["update_id"]
            .as_str()
            .ok_or_else(|| Error::Codec("applied update identity missing".into()))?;
        if !seen.insert(id) {
            continue;
        }
        let arguments = &event.payload["arguments"];
        let envelope = PayloadEnvelope {
            codec: arguments["codec"].as_str().unwrap_or_default().into(),
            blob: arguments["blob"].as_str().unwrap_or_default().into(),
        };
        counter.apply(&decode_payload::<Vec<Value>>(&envelope)?)?;
    }
    Ok(counter)
}

fn snapshot(ctx: QueryContext) -> Value {
    json!({
        "workflow_id": ctx.workflow_id,
        "run_id": ctx.run_id,
        "workflow_input": ctx.workflow_input(),
        "signals": ctx.signals("updates-touch"),
    })
}

pub fn register(worker: &mut Worker) {
    worker.register_replayed_workflow("polyglot.rust.updates", Counter::default, |ctx, input, state| async move {
        let request = super::first_argument(&input);
        let mut counter = Counter::default();
        for arguments in ctx.updates("increment")? {
            counter.apply(&arguments)?;
        }
        state.update(|state| *state = counter)?;
        for _ in 0..3 {
            ctx.wait_signal("updates-touch").await?;
        }
        ctx.wait_signal("updates-finish").await?;
        Ok(json!({"workflow_runtime": "rust", "request": request, "state": state.read(Counter::value)?}))
    });
    worker
        .declare_workflow_signals(
            "polyglot.rust.updates",
            &["updates-finish", "updates-touch"],
        )
        .expect("declare the update workflow's completion signal");
    worker.register_update("polyglot.rust.updates", "echo", |_ctx, input| async move {
        Ok(json!({"handler_runtime": "rust", "request": super::first_argument(&input)}))
    });
    worker.register_update("polyglot.rust.updates", "increment", |ctx, input| async move {
        Ok(json!({"handler_runtime": "rust", "request": super::first_argument(&input), "state": applied_counter(&ctx)?.value()}))
    });
    worker.register_replayed_query::<Counter, _, _>(
        "polyglot.rust.updates",
        "counter",
        |_ctx, state, _input| async move { Ok(state.value()) },
    );
    worker.register_update("polyglot.rust.updates", "fail", |_ctx, _input| async move {
        Err(Error::Codec("update-probe-failure".into()))
    });
    worker.register_query(
        "polyglot.rust.updates",
        "snapshot",
        |ctx, _input| async move { Ok(snapshot(ctx)) },
    );
    worker.register_update(
        "polyglot.rust.updates",
        "snapshot",
        |ctx, _input| async move { Ok(snapshot(ctx)) },
    );
}

pub async fn call(client: Client) -> Result<()> {
    let args = std::env::args().skip(1).collect::<Vec<_>>();
    if args.len() != 3 && args.len() != 4 {
        return Err(Error::Codec(
            "expected workflow ID, request ID and update name".into(),
        ));
    }
    let mut request = json!({"caller": "rust", "request_id": args[1], "value": "hello", "nested": {"enabled": true, "count": 42}});
    if args[2] == "increment" {
        let delta = args
            .get(3)
            .ok_or_else(|| Error::Codec("increment delta missing".into()))?
            .parse::<i64>()
            .map_err(|error| Error::Codec(error.to_string()))?;
        request["delta"] = json!(delta);
    }
    let result = client
        .update_workflow(&args[0], &args[2], json!([request]), Some(&args[1]))
        .await?;
    println!(
        "{}",
        json!({"caller": "rust", "request_id": args[1], "result": result})
    );
    Ok(())
}

pub async fn validator_refusal(client: Client) -> Result<()> {
    let result = client
        .register_worker_with_command_contracts(
            "rust-unsupported-validator",
            "polyglot-rust",
            vec!["polyglot.rust.updates".into()],
            vec![],
            1,
            0,
            vec!["workflow_updates".into()],
            json!({"polyglot.rust.updates": {"updates": ["echo"], "update_validators": ["echo"]}}),
        )
        .await;
    match result {
        Err(Error::UnsupportedUpdateValidators { workflow_type })
            if workflow_type == "polyglot.rust.updates" =>
        {
            println!(
                "{}",
                json!({"scenario": "rust-validator-refusal", "error": "UnsupportedUpdateValidators", "workflow_type": workflow_type})
            );
            Ok(())
        }
        other => Err(Error::Codec(format!(
            "expected unsupported-validator refusal, got {other:?}"
        ))),
    }
}
