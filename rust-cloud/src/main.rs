use std::time::Duration;

use durable_workflow::{json, Client, Result, Worker};

const WORKFLOW_TYPE: &str = "sample.rust-cloud.greeter";
const ACTIVITY_TYPE: &str = "sample.rust-cloud.greet";

#[tokio::main]
async fn main() -> Result<()> {
    let runtime_url = required_env("DURABLE_WORKFLOW_RUNTIME_URL");
    let runtime_namespace = required_env("DURABLE_WORKFLOW_RUNTIME_NAMESPACE");
    let worker_token = required_env("DURABLE_WORKFLOW_WORKER_TOKEN");
    let task_queue = required_env("DURABLE_WORKFLOW_TASK_QUEUE");
    let worker_id = format!("rust-cloud-quickstart-{}", std::process::id());

    let client = Client::builder(runtime_url)
        .namespace(runtime_namespace)
        .worker_token(Some(worker_token))
        .build()?;
    let mut worker = Worker::new(client, task_queue.clone())
        .worker_id(worker_id.clone())
        .poll_timeout(Duration::from_secs(5));

    worker.register_activity(ACTIVITY_TYPE, |_context, arguments| async move {
        let name = arguments
            .get(0)
            .and_then(|value| value.as_str())
            .unwrap_or("Cloud");

        Ok(json!({
            "greeting": format!("Hello, {name}!"),
            "activity": ACTIVITY_TYPE,
            "activity_runtime": "rust"
        }))
    });

    worker.register_workflow(WORKFLOW_TYPE, |context, input| async move {
        let name = input
            .get(0)
            .and_then(|value| value.as_str())
            .unwrap_or("Cloud");
        let activity = context.activity(ACTIVITY_TYPE, json!([name])).await?;

        Ok(json!({
            "workflow_runtime": "rust",
            "workflow_type": WORKFLOW_TYPE,
            "activity": activity
        }))
    });

    println!(
        "starting worker_id={worker_id} task_queue={task_queue} workflow_type={WORKFLOW_TYPE} activity_type={ACTIVITY_TYPE}"
    );
    worker.run_until(shutdown_signal()).await?;
    println!("worker_id={worker_id} shutdown=clean");

    Ok(())
}

async fn shutdown_signal() {
    if let Err(error) = tokio::signal::ctrl_c().await {
        eprintln!("could not listen for Ctrl+C: {error}");
    }
}

fn required_env(name: &str) -> String {
    std::env::var(name).unwrap_or_else(|_| panic!("{name} must be set"))
}
