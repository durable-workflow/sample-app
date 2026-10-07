use durable_workflow::{json, Client, Error, Result, Worker};

pub fn register(worker: &mut Worker) {
    worker.register_workflow("polyglot.rust.updates", |ctx, input| async move {
        let request = super::first_argument(&input);
        ctx.wait_signal("updates-finish").await?;
        Ok(json!({"workflow_runtime": "rust", "request": request}))
    });
    worker.register_update("polyglot.rust.updates", "echo", |_ctx, input| async move {
        Ok(json!({"handler_runtime": "rust", "request": super::first_argument(&input)}))
    });
    worker.register_update("polyglot.rust.updates", "fail", |_ctx, _input| async move {
        Err(Error::Codec("update-probe-failure".into()))
    });
}

pub async fn call(client: Client) -> Result<()> {
    let args = std::env::args().skip(1).collect::<Vec<_>>();
    if args.len() != 3 {
        return Err(Error::Codec("expected workflow ID, request ID and update name".into()));
    }
    let request = json!({"caller": "rust", "request_id": args[1], "value": "hello", "nested": {"enabled": true, "count": 42}});
    let result = client.update_workflow(&args[0], &args[2], json!([request]), Some(&args[1])).await?;
    println!("{}", json!({"caller": "rust", "request_id": args[1], "result": result}));
    Ok(())
}

pub async fn validator_refusal(client: Client) -> Result<()> {
    let result = client.register_worker_with_command_contracts(
        "rust-unsupported-validator", "polyglot-rust", vec!["polyglot.rust.updates".into()],
        vec![], 1, 0, vec!["workflow_updates".into()],
        json!({"polyglot.rust.updates": {"updates": ["echo"], "update_validators": ["echo"]}}),
    ).await;
    match result {
        Err(Error::UnsupportedUpdateValidators { workflow_type }) if workflow_type == "polyglot.rust.updates" => {
            println!("{}", json!({"scenario": "rust-validator-refusal", "error": "UnsupportedUpdateValidators", "workflow_type": workflow_type}));
            Ok(())
        }
        other => Err(Error::Codec(format!("expected unsupported-validator refusal, got {other:?}"))),
    }
}
