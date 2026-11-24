use std::time::Duration;

use durable_workflow::{
    json, ActivityOptions, ActivityRetryPolicy, Client, Error, Result, Worker,
};

const RESTART_SIGNAL: &str = "sample-app.saga.restart-continue";

fn required(name: &str) -> String {
    std::env::var(name)
        .ok()
        .filter(|value| !value.trim().is_empty())
        .unwrap_or_else(|| panic!("Set {name} before starting the worker."))
}

#[tokio::main]
async fn main() -> Result<()> {
    let queue = required("DURABLE_WORKFLOW_TASK_QUEUE");
    let client = Client::builder(required("DURABLE_WORKFLOW_RUNTIME_URL"))
        .namespace(required("DURABLE_WORKFLOW_NAMESPACE"))
        .worker_token(Some(required("DURABLE_WORKFLOW_WORKER_TOKEN")))
        .build()?;
    let mut worker = Worker::new(client, queue)
        .worker_id(format!(
            "sample-saga-rust-{}-{}",
            std::env::var("HOSTNAME").unwrap_or_default(),
            std::process::id(),
        ))
        .poll_timeout(Duration::from_secs(3));

    for step in ["first", "second"] {
        worker.register_activity(format!("sample-app.saga.reserve-{step}"), move |_ctx, args| async move {
            Ok(json!({"step": step, "marker": args.get(0)}))
        });
        worker.register_activity(format!("sample-app.saga.rust.undo-{step}"), move |ctx, args| async move {
            if step == "second"
                && args.get(0).and_then(|value| value.as_str())
                    .is_some_and(|marker| marker.starts_with("saga-fail-compensation-"))
            {
                return Err(Error::WorkerLoop("planned undo-second compensation failure".to_string()));
            }
            if step == "second" && args.get(1).and_then(|value| value.as_bool()) == Some(true) {
                let marker = args[0].as_str().ok_or_else(|| Error::WorkerLoop("Missing saga marker".into()))?;
                let receipt: serde_json::Value = reqwest::Client::builder()
                    .timeout(Duration::from_secs(5))
                    .build()
                    .map_err(|error| Error::WorkerLoop(error.to_string()))?
                    .post(format!("{}/effects", required("ACTIVITY_EFFECTS_URL")))
                    .json(&json!({
                        "operation_key": format!("{marker}:undo-second"),
                        "attempt_id": ctx.activity_attempt_id,
                        "input": {"operation": "undo-second", "marker": marker},
                    }))
                    .send().await.map_err(|error| Error::WorkerLoop(error.to_string()))?
                    .error_for_status().map_err(|error| Error::WorkerLoop(error.to_string()))?
                    .json().await.map_err(|error| Error::WorkerLoop(error.to_string()))?;
                if receipt["reused"].as_bool() != Some(ctx.attempt_number == 2) {
                    return Err(Error::WorkerLoop("Unexpected downstream compensation receipt.".into()));
                }
                if ctx.attempt_number == 1 {
                    tokio::time::sleep(Duration::from_secs(45)).await;
                    return Err(Error::WorkerLoop("Fixture did not stop the first compensation worker.".into()));
                }
            }
            Ok(json!({"step": step, "marker": args.get(0), "runtime": "rust"}))
        });
    }
    worker.register_activity("sample-app.saga.decline", |_ctx, _args| async move {
        Err(Error::WorkerLoop("planned saga failure".to_string()))
    });

    for language in ["rust", "php", "python"] {
        let workflow_type = format!("sample-app.saga.rust.compensate-{language}");
        worker.register_workflow(
            workflow_type.clone(),
            move |context, input| async move {
                let marker = input.get(0).cloned().unwrap_or_else(|| json!(null));
                let scenario = input.get(1).and_then(|value| value.as_str());
                let mut saga = context.saga();
                let outcome = async {
                    for step in ["first", "second"] {
                        context
                            .activity(format!("sample-app.saga.reserve-{step}"), json!([marker]))
                            .await?;
                        let recover_step = scenario == Some("recover-effect") && step == "second";
                        let mut options = ActivityOptions::new().retry_policy(ActivityRetryPolicy::new(1));
                        if recover_step {
                            options = options
                                .start_to_close_timeout(Duration::from_secs(20))
                                .retry_policy(ActivityRetryPolicy::new(2).backoff_intervals([Duration::from_secs(1)]));
                        }
                        saga.add_compensation_with_options(
                            format!("sample-app.saga.{language}.undo-{step}"),
                            options,
                            if recover_step { json!([marker, true]) } else { json!([marker]) },
                        )?;
                        if scenario == Some("restart-check") && step == "first" {
                            context.wait_signal(RESTART_SIGNAL).await?;
                        }
                    }
                    context
                        .activity_with_options(
                            "sample-app.saga.decline",
                            ActivityOptions::new().retry_policy(ActivityRetryPolicy::new(1)),
                            json!([]),
                        )
                        .await?;
                    Ok(json!({"unexpected_success": true}))
                }
                .await;

                match saga.finish(outcome).await {
                    Err(Error::ActivityFailed(failure)) => Ok(json!({
                        "status": "compensated",
                        "workflow_runtime": "rust",
                        "compensation_runtime": language,
                        "marker": marker,
                        "initiating_failure": failure.reason,
                    })),
                    other => other,
                }
            },
        );
        worker.declare_workflow_signals(&workflow_type, &[RESTART_SIGNAL])?;
    }

    worker.run().await
}
