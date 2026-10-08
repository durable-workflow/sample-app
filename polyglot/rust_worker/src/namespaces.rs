use std::time::Duration;

use durable_workflow::{json, Client, Error, Result, Value, Worker};

const WORKFLOW: &str = "sample-app.rust.namespace";
const ACTIVITY: &str = "sample-app.rust.namespace.echo";
const SIGNAL: &str = "namespace-finish";
const QUEUE: &str = "shared-namespace-queue";

fn required(name: &str) -> String {
    super::required_env(name)
}

fn check(condition: bool, message: &str) -> Result<()> {
    if condition {
        Ok(())
    } else {
        Err(Error::Codec(message.into()))
    }
}

pub async fn worker(client: Client) -> Result<()> {
    let namespace = required("DURABLE_WORKFLOW_NAMESPACE");
    let worker_id = format!("rust-namespace-{namespace}");
    let mut worker = Worker::new(client, QUEUE)
        .worker_id(worker_id.clone())
        .poll_timeout(Duration::from_secs(3));
    let activity_namespace = namespace.clone();
    worker.register_activity(ACTIVITY, move |ctx, args| {
        let namespace = activity_namespace.clone();
        async move {
            Ok(json!({"namespace": namespace, "worker_id": ctx.worker_id,
                      "request": super::first_argument(&args)}))
        }
    });
    worker.register_workflow(WORKFLOW, move |ctx, input| {
        let namespace = namespace.clone();
        let worker_id = worker_id.clone();
        async move {
            let request = super::first_argument(&input);
            let activity = ctx.activity(ACTIVITY, json!([request.clone()])).await?;
            let signals = ctx.wait_signal(SIGNAL).await?;
            Ok(json!({"namespace": namespace, "worker_id": worker_id,
                      "request": request, "activity": activity,
                      "signal": signals.first().cloned().unwrap_or(Value::Null)}))
        }
    });
    worker.declare_workflow_signals(WORKFLOW, &[SIGNAL])?;
    worker.run().await
}

fn refused<T>(result: Result<T>, status: u16, reason: &str) -> Result<Value> {
    let (actual_status, actual_reason) = match result {
        Err(Error::Http { status, body }) => {
            let body: Value = serde_json::from_str(&body)?;
            (
                status.as_u16(),
                body["reason"].as_str().unwrap_or_default().to_string(),
            )
        }
        Err(Error::Protocol(failure)) => (failure.status, failure.reason),
        Err(error) => return Err(error),
        Ok(_) => {
            return Err(Error::Codec(
                "unauthorized namespace operation succeeded".into(),
            ))
        }
    };
    check(
        actual_status == status && actual_reason == reason,
        "unexpected authorization diagnostic",
    )?;
    Ok(json!({"status": actual_status, "reason": actual_reason}))
}

pub async fn call(client: Client) -> Result<()> {
    let phase = required("DURABLE_WORKFLOW_NAMESPACE_PHASE");
    let namespace = required("DURABLE_WORKFLOW_NAMESPACE");
    let id = required("DURABLE_WORKFLOW_NAMESPACE_ID");
    let request = json!({"namespace": namespace, "workflow_id": id});
    let mut record = json!({"scenario": format!("rust-namespace-{phase}"),
                           "namespace": namespace, "workflow_id": id});
    match phase.as_str() {
        "start" => {
            let handle = client
                .start_workflow(WORKFLOW, QUEUE, &id, json!([request]))
                .await?;
            check(
                handle.workflow_id == id && handle.run_id.is_some(),
                "start lost workflow/run identity",
            )?;
            record["run_id"] = json!(handle.run_id);
        }
        "release" => {
            record["acknowledgment"] = client
                .signal_workflow(&id, SIGNAL, json!([request]))
                .await?;
        }
        "verify" => {
            let deadline = tokio::time::Instant::now() + Duration::from_secs(90);
            let description = loop {
                let description = client.describe_workflow(&id).await?;
                if description.is_terminal() {
                    break description;
                }
                check(
                    tokio::time::Instant::now() < deadline,
                    "workflow did not complete",
                )?;
                tokio::time::sleep(Duration::from_millis(250)).await;
            };
            let worker_id = format!("rust-namespace-{namespace}");
            let expected = json!({"namespace": namespace, "worker_id": worker_id,
                "request": request, "activity": {"namespace": namespace, "worker_id": worker_id, "request": request},
                "signal": request});
            check(
                description.is_completed() && description.output.as_ref() == Some(&expected),
                "namespace workflow/activity/signal result differs",
            )?;
            record["run_id"] = json!(description.run_id);
            record["result"] = expected;
        }
        "routed" => {
            let description = client.describe_workflow(&id).await?;
            check(
                description.workflow_id.as_deref() == Some(&id),
                "control credential did not describe selected namespace",
            )?;
            let registration = client
                .register_worker(
                    "rust-namespace-role-probe",
                    "namespace-empty-probe",
                    vec![WORKFLOW.into()],
                    vec![],
                    1,
                    1,
                )
                .await?;
            check(
                registration.registered,
                "worker credential did not register",
            )?;
            check(
                client
                    .poll_workflow_task(
                        "rust-namespace-role-probe",
                        "namespace-empty-probe",
                        Duration::from_secs(1),
                    )
                    .await?
                    .is_none(),
                "empty probe queue had work",
            )?;
            record["run_id"] = json!(description.run_id);
            record["separate_control_and_worker_credentials"] = json!(true);
        }
        "deny-describe" | "missing-describe" => {
            let status = if phase == "missing-describe" {
                404
            } else {
                403
            };
            let reason = if status == 404 {
                "instance_not_found"
            } else {
                "forbidden"
            };
            record["diagnostic"] = refused(client.describe_workflow(&id).await, status, reason)?;
        }
        "deny-signal" | "missing-signal" => {
            let status = if phase == "missing-signal" { 404 } else { 403 };
            let reason = if status == 404 {
                "instance_not_found"
            } else {
                "forbidden"
            };
            record["diagnostic"] = refused(
                client.signal_workflow(&id, SIGNAL, json!([request])).await,
                status,
                reason,
            )?;
        }
        "deny-start" => {
            record["diagnostic"] = refused(
                client
                    .start_workflow(WORKFLOW, QUEUE, &id, json!([request]))
                    .await,
                403,
                "forbidden",
            )?;
        }
        "deny-collision" => {
            record["diagnostic"] = refused(
                client
                    .start_workflow(WORKFLOW, QUEUE, &id, json!([request]))
                    .await,
                409,
                "workflow_id_reserved_in_namespace",
            )?;
        }
        "deny-register" => {
            record["diagnostic"] = refused(
                client
                    .register_worker(
                        "rust-namespace-denied",
                        QUEUE,
                        vec![WORKFLOW.into()],
                        vec![ACTIVITY.into()],
                        1,
                        1,
                    )
                    .await,
                403,
                "forbidden",
            )?;
        }
        "deny-poll" => {
            record["diagnostic"] = refused(
                client
                    .poll_workflow_task("rust-namespace-denied", QUEUE, Duration::from_secs(1))
                    .await,
                403,
                "forbidden",
            )?;
        }
        "missing-control" => match client.describe_workflow(&id).await {
            Err(Error::MissingRoleCredentials {
                role: "control",
                opposite_role: "worker",
            }) => {
                record["diagnostic"] = json!({"reason": "missing_role_credentials", "role": "control", "opposite_role": "worker"});
            }
            Err(error) => return Err(error),
            Ok(_) => {
                return Err(Error::Codec(
                    "worker-only client accessed the control plane".into(),
                ))
            }
        },
        "missing-worker" => {
            match client
                .poll_workflow_task("rust-namespace-denied", QUEUE, Duration::from_secs(1))
                .await
            {
                Err(Error::MissingRoleCredentials {
                    role: "worker",
                    opposite_role: "control",
                }) => {
                    record["diagnostic"] = json!({"reason": "missing_role_credentials", "role": "worker", "opposite_role": "control"});
                }
                Err(error) => return Err(error),
                Ok(_) => {
                    return Err(Error::Codec(
                        "control-only client accessed the worker plane".into(),
                    ))
                }
            }
        }
        _ => return Err(Error::Codec(format!("unknown namespace phase {phase}"))),
    }
    println!("{record}");
    Ok(())
}
