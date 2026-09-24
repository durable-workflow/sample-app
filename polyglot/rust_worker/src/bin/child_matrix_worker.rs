use std::time::Duration;

use durable_workflow::{json, ChildWorkflowOptions, Client, Result, Worker};

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
    let mut worker = Worker::new(client, queue.clone())
        .worker_id(format!("sample-child-matrix-rust-{}", std::process::id()))
        .poll_timeout(Duration::from_secs(3));

    worker.register_workflow("sample-app.child-matrix.rust.child", |_context, input| async move {
        let value = input.get(0).cloned().unwrap_or_else(|| json!(null));
        Ok(json!({"value": value, "runtime": "rust"}))
    });

    for child in ["php", "python", "rust"] {
        let workflow_type = format!("sample-app.child-matrix.rust.parent-{child}");
        let child_type = format!("sample-app.child-matrix.{child}.child");
        let child_queue = queue.clone();
        worker.register_workflow(workflow_type, move |context, input| {
            let child_type = child_type.clone();
            let child_queue = child_queue.clone();
            async move {
                let value = input.get(0).cloned().unwrap_or_else(|| json!(null));
                let child = context
                    .start_child_workflow(
                        child_type,
                        ChildWorkflowOptions::new(child_queue),
                        json!([value]),
                    )
                    .await?;
                Ok(json!({"parent_runtime": "rust", "child_result": child.result}))
            }
        });
    }

    worker.run().await
}
