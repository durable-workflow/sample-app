use std::time::Duration;

use durable_workflow::{Client, Result, Worker};

#[path = "../children.rs"]
mod children;

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

    children::register(&mut worker, Some(queue));

    worker.run().await
}
