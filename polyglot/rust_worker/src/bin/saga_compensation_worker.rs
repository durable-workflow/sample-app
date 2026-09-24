use std::time::Duration;

use durable_workflow::{
    json, ActivityOptions, ActivityRetryPolicy, Client, Error, Result, Worker,
};

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
        .worker_id(format!("sample-saga-rust-{}", std::process::id()))
        .poll_timeout(Duration::from_secs(3));

    for step in ["first", "second"] {
        worker.register_activity(format!("sample-app.saga.reserve-{step}"), move |_ctx, args| async move {
            Ok(json!({"step": step, "marker": args.get(0)}))
        });
        worker.register_activity(format!("sample-app.saga.rust.undo-{step}"), move |_ctx, args| async move {
            Ok(json!({"step": step, "marker": args.get(0), "runtime": "rust"}))
        });
    }
    worker.register_activity("sample-app.saga.decline", |_ctx, _args| async move {
        Err(Error::WorkerLoop("planned saga failure".to_string()))
    });

    for language in ["rust", "php", "python"] {
        worker.register_workflow(
            format!("sample-app.saga.rust.compensate-{language}"),
            move |context, input| async move {
                let marker = input.get(0).cloned().unwrap_or_else(|| json!(null));
                let restart_check =
                    input.get(1).and_then(|value| value.as_str()) == Some("restart-check");
                let mut saga = context.saga();
                let outcome = async {
                    for step in ["first", "second"] {
                        context
                            .activity(format!("sample-app.saga.reserve-{step}"), json!([marker]))
                            .await?;
                        saga.add_compensation(
                            format!("sample-app.saga.{language}.undo-{step}"),
                            json!([marker]),
                        )?;
                        if restart_check && step == "first" {
                            context
                                .wait_signal("sample-app.saga.restart-continue")
                                .await?;
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
    }

    worker.run().await
}
