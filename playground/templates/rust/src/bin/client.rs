use std::time::Duration;

use durable_workflow::{json, Client, Result, Uuid, WorkflowResultOptions};
use serde_json::Value;

fn required(name: &str) -> String {
    std::env::var(name)
        .ok()
        .filter(|value| !value.trim().is_empty())
        .unwrap_or_else(|| panic!("Set {name} before starting the client."))
}

#[tokio::main]
async fn main() -> Result<()> {
    let scenario: Value = serde_json::from_str(&required("SAMPLE_APP_PLAYGROUND_SCENARIO"))?;
    let workflow_type = scenario["workflow_type"]
        .as_str()
        .expect("scenario workflow_type");
    let task_queue = scenario["task_queue"]
        .as_str()
        .expect("scenario task_queue");
    let prefix = scenario["workflow_id_prefix"]
        .as_str()
        .expect("scenario workflow_id_prefix");
    let workflow_id = format!("{prefix}-{}", Uuid::new_v4());

    let client = Client::builder(required("DURABLE_WORKFLOW_RUNTIME_URL"))
        .namespace(required("DURABLE_WORKFLOW_NAMESPACE"))
        .control_token(Some(required("DURABLE_WORKFLOW_CLIENT_TOKEN")))
        .build()?;
    let handle = client
        .start_workflow(workflow_type, task_queue, &workflow_id, json!([]))
        .await?;
    let run_id = handle.run_id.clone();
    let result = handle
        .result(WorkflowResultOptions {
            poll_interval: Duration::from_millis(500),
            timeout: Duration::from_secs(90),
        })
        .await?;

    println!(
        "{}",
        json!({
            "workflow_id": workflow_id,
            "run_id": run_id,
            "result": result,
        })
    );
    Ok(())
}
