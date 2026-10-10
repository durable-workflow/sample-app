use durable_workflow::{json, Client, CooperativeCancellationOptions, Error, Result, Value};

pub async fn call(client: Client) -> Result<()> {
    let phase = super::required_env("DURABLE_WORKFLOW_CANCELLATION_PHASE");
    let runs: Vec<Value> =
        serde_json::from_str(&super::required_env("DURABLE_WORKFLOW_CANCELLATION_RUNS"))?;
    for run in runs {
        let caller = if phase == "duplicate" {
            &run["child"]
        } else {
            &run["parent"]
        };
        if caller != "rust" {
            continue;
        }
        let workflow_id = run["parent_workflow_id"]
            .as_str()
            .ok_or_else(|| Error::Codec("missing workflow id".into()))?;
        let run_id = run["parent_run_id"]
            .as_str()
            .ok_or_else(|| Error::Codec("missing run id".into()))?;
        let result = client
            .request_workflow_run_cancellation(
                workflow_id,
                run_id,
                CooperativeCancellationOptions {
                    reason: Some(
                        if phase == "duplicate" {
                            "duplicate must not replace original"
                        } else {
                            "SDK child cancellation conformance"
                        }
                        .into(),
                    ),
                    cleanup_timeout_seconds: Some(if phase == "duplicate" { 60 } else { 30 }),
                },
            )
            .await;
        let mut record = json!({"caller": "rust", "phase": phase, "workflow_id": workflow_id, "run_id": run_id});
        match result {
            Ok(response) if !phase.starts_with("deny-") => {
                record["response"] = json!({"workflow_id": response.workflow_id, "run_id": response.run_id,
                    "duplicate": response.duplicate, "cancellation_request": {
                        "request_id": response.cancellation_request.request_id,
                        "requested_at": response.cancellation_request.requested_at,
                        "cleanup_deadline_at": response.cancellation_request.cleanup_deadline_at}});
            }
            Ok(_) => return Err(Error::Codec("an unauthorized cancellation succeeded".into())),
            Err(error) if phase.starts_with("deny-") => {
                let (status, reason) = match error {
                    Error::Http { status, body } => (
                        status.as_u16(),
                        serde_json::from_str::<Value>(&body)?["reason"].clone(),
                    ),
                    Error::Protocol(failure) => (failure.status, json!(failure.reason)),
                    other => return Err(other),
                };
                if status != if phase == "deny-worker" { 403 } else { 401 } {
                    return Err(Error::Codec("unexpected cancellation authorization status".into()));
                }
                record["refusal"] = json!({"status": status, "reason": reason});
            }
            Err(error) => return Err(error),
        }
        println!("{record}");
    }
    Ok(())
}
