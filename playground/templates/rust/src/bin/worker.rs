use std::time::Duration;

use durable_workflow::{json, Client, Result, Worker};
use serde_json::Value;

fn required(name: &str) -> String {
    std::env::var(name)
        .ok()
        .filter(|value| !value.trim().is_empty())
        .unwrap_or_else(|| panic!("Set {name} before starting the worker."))
}

#[tokio::main]
async fn main() -> Result<()> {
    let scenario: Value = serde_json::from_str(&required("SAMPLE_APP_PLAYGROUND_SCENARIO"))?;
    let workflow_type = scenario["workflow_type"]
        .as_str()
        .expect("scenario workflow_type")
        .to_owned();
    let activity_type = scenario["activity_type"]
        .as_str()
        .expect("scenario activity_type")
        .to_owned();
    let expected = scenario["expected_result"].clone();

    let client = Client::builder(required("DURABLE_WORKFLOW_RUNTIME_URL"))
        .namespace(required("DURABLE_WORKFLOW_NAMESPACE"))
        .worker_token(Some(required("DURABLE_WORKFLOW_WORKER_TOKEN")))
        .build()?;
    let mut worker = Worker::new(client, required("DURABLE_WORKFLOW_TASK_QUEUE"))
        .worker_id(required("DURABLE_WORKFLOW_WORKER_ID"))
        .poll_timeout(Duration::from_secs(5));

    worker.register_activity(activity_type.clone(), move |_context, arguments| {
        let expected = expected.clone();
        let authored_input = arguments.get(0).cloned().unwrap_or(Value::Null);
        async move {
            Ok(json!({
                "greeting": expected["greeting"],
                "input": authored_input,
                "activity_runtime": "rust"
            }))
        }
    });
    worker.register_workflow(workflow_type, move |context, input| {
        let activity_type = activity_type.clone();
        async move {
            let authored_input = input.get(0).cloned().unwrap_or(Value::Null);
            let activity = context
                .activity(activity_type, json!([authored_input]))
                .await?;
            Ok(json!({
                "greeting": activity["greeting"],
                "input": activity["input"],
                "activity_runtime": activity["activity_runtime"],
                "workflow_runtime": "rust"
            }))
        }
    });

    worker.run().await
}
