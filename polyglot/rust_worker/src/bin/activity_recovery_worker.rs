//! Published SDK workers for remote activity retry and attempt recovery.
use durable_workflow::{
    json, ActivityOptions, ActivityRetryPolicy, Client, Error, Result, Value, Worker, SDK_VERSION,
};
use std::{env, fs, io::Write, path::PathBuf, time::Duration};

fn emit(value: Value) {
    println!("{value}");
    std::io::stdout().flush().expect("flush observation");
}

async fn gate(case_id: &str, suffix: &str) -> Result<()> {
    let path = PathBuf::from(env::var("ACTIVITY_RECOVERY_PROOF").expect("proof"))
        .join(format!("{case_id}{suffix}"));
    tokio::time::timeout(Duration::from_secs(180), async {
        while !path.exists() {
            tokio::time::sleep(Duration::from_millis(100)).await;
        }
    })
    .await
    .map_err(|_| Error::WorkerLoop("fixture gate was not released".into()))
}

#[tokio::main]
async fn main() -> Result<()> {
    let client = Client::builder(env::var("DURABLE_WORKFLOW_SERVER_URL").expect("server"))
        .token(Some(
            env::var("DURABLE_WORKFLOW_AUTH_TOKEN").expect("token"),
        ))
        .namespace("default")
        .build()?;
    let mode = env::var("ACTIVITY_RECOVERY_MODE").expect("mode");
    if mode == "stale" || mode == "stale-heartbeat" {
        let claim: Value =
            serde_json::from_str(&env::var("ACTIVITY_RECOVERY_CLAIM").expect("claim"))?;
        if mode == "stale-heartbeat" {
            let result = client
                .heartbeat_activity_task(
                    claim["task_id"].as_str().expect("task"),
                    claim["activity_attempt_id"].as_str().expect("attempt"),
                    claim["lease_owner"].as_str().expect("lease"),
                    json!({"note":"obsolete heartbeat"}),
                )
                .await;
            return match result {
                Ok(reply) if !reply.heartbeat_recorded && reply.can_continue == Some(false) => {
                    emit(
                        json!({"event":"late-heartbeat-rejected","status":200,"reason":reply.reason,
                        "heartbeat_recorded":reply.heartbeat_recorded,"can_continue":reply.can_continue}),
                    );
                    Ok(())
                }
                Err(Error::ActivityTaskRejected(rejection)) => {
                    emit(
                        json!({"event":"late-heartbeat-rejected","status":rejection.status,
                        "reason":rejection.reason,"heartbeat_recorded":false,"can_continue":false}),
                    );
                    Ok(())
                }
                Err(error) => Err(error),
                Ok(_) => Err(Error::WorkerLoop(
                    "obsolete heartbeat retained authority".into(),
                )),
            };
        }
        let result = client
            .complete_activity_task(
                claim["task_id"].as_str().expect("task"),
                claim["activity_attempt_id"].as_str().expect("attempt"),
                claim["lease_owner"].as_str().expect("lease"),
                json!({"stale":true}),
                "avro",
            )
            .await;
        return match result {
            Err(Error::ActivityTaskRejected(rejection)) => {
                emit(json!({"event":"stale-rejected","sdk_version":SDK_VERSION,
                    "reason":rejection.reason,"status":rejection.status,"task_id":rejection.task_id,
                    "activity_attempt_id":rejection.activity_attempt_id}));
                Ok(())
            }
            Err(error) => Err(error),
            Ok(_) => Err(Error::WorkerLoop(
                "stale activity attempt was accepted".into(),
            )),
        };
    }
    let queue = format!("activity-recovery-{mode}-rust");
    let worker_id = format!(
        "{queue}-{}",
        env::var("HOSTNAME").expect("container hostname")
    );
    let mut worker = Worker::new(client, queue)
        .worker_id(worker_id)
        .poll_timeout(Duration::from_secs(2));
    if mode == "activity"
        && env::var("ACTIVITY_RECOVERY_COOPERATIVE_CANCELLATION")
            .ok()
            .as_deref()
            == Some("1")
    {
        worker = worker.cooperative_cancellation(true);
    }
    if mode == "workflow" {
        worker.register_workflow(
            "sample-app.activity-recovery.rust",
            |ctx, args| async move {
                let request = args[0].clone();
                let runtime = request["activity_runtime"]
                    .as_str()
                    .expect("activity runtime");
                let total_deadline = request["scenario"] == "total-deadline";
                let progress = request["scenario"] == "progress-heartbeat";
                let mut options = ActivityOptions::new()
                    .task_queue(format!("activity-recovery-activity-{runtime}"))
                    .retry_policy(
                        ActivityRetryPolicy::new(2).backoff_intervals([Duration::from_secs(2)]),
                    )
                    .start_to_close_timeout(Duration::from_secs(if progress {
                        60
                    } else if total_deadline {
                        30
                    } else {
                        20
                    }))
                    .schedule_to_close_timeout(Duration::from_secs(if total_deadline {
                        30
                    } else {
                        120
                    }));
                if progress {
                    options = options.heartbeat_timeout(Duration::from_secs(10));
                }
                let result = ctx
                    .activity_with_options(
                        format!("sample-app.activity-recovery.{runtime}.work"),
                        options,
                        json!([request]),
                    )
                    .await?;
                Ok(json!({"workflow_runtime":"rust","activity":result}))
            },
        );
        worker.set_workflow_definition_sources(
            "sample-app.activity-recovery.rust",
            &[include_str!("activity_recovery_worker.rs")],
        )?;
    } else if mode == "activity" {
        worker.register_activity("sample-app.activity-recovery.rust.work", |ctx, args| async move {
            let request = args[0].clone();
            let receipt = json!({"case_id":request["case_id"],"runtime":"rust","sdk_version":SDK_VERSION,
                "pid":std::process::id(),"task_id":ctx.task_id,"activity_attempt_id":ctx.activity_attempt_id,
                "lease_owner":ctx.lease_owner,"attempt_number":ctx.attempt_number});
            emit(json!({"event":"activity-started","claim":receipt}));
            let case_id = request["case_id"].as_str().expect("case");
            let path = PathBuf::from(env::var("ACTIVITY_RECOVERY_PROOF").expect("proof"))
                .join(format!("{case_id}.attempt-{}.json", ctx.attempt_number));
            let temporary = path.with_extension("pending");
            fs::write(&temporary, receipt.to_string()).expect("write claim");
            fs::rename(temporary, path).expect("publish claim");
            if request["scenario"] == "progress-heartbeat" {
                if ctx.attempt_number == 1 {
                    gate(case_id, ".first-release").await?;
                }
                let mut step = 0;
                loop {
                    step += 1;
                    let reply = ctx.heartbeat(json!({"case_id":case_id,"runtime":"rust",
                        "attempt":ctx.attempt_number,"step":step,"fraction":0.5,
                        "ready":true,"optional":null,"note":"café ✓"})).await?;
                    if !reply.heartbeat_recorded || reply.should_stop() {
                        return Err(Error::WorkerLoop("application heartbeat was not accepted".into()));
                    }
                    let root = PathBuf::from(env::var("ACTIVITY_RECOVERY_PROOF").expect("proof"));
                    if ctx.attempt_number == 1 && step == 5 {
                        fs::write(root.join(format!("{case_id}.progress-ready")), "ready").expect("publish progress");
                        gate(case_id, ".release").await?;
                        fs::write(root.join(format!("{case_id}.expired-resumed")), "expired callback resumed").expect("publish forbidden resumption");
                        return Err(Error::WorkerLoop("expired first attempt returned to application code".into()));
                    }
                    if root.join(format!("{case_id}.release")).exists() {
                        return Ok(receipt);
                    }
                    tokio::time::sleep(Duration::from_secs(3)).await;
                }
            }
            if ctx.attempt_number == 1 {
                gate(case_id, ".first-release").await?;
                if request["scenario"] != "worker-loss" {
                    return Err(Error::WorkerLoop("injected first-attempt failure".into()));
                }
                return Err(Error::WorkerLoop("first attempt was not killed".into()));
            }
            gate(case_id, ".release").await?;
            if request["scenario"] == "retry-exhaustion" {
                return Err(Error::WorkerLoop("injected second-attempt failure".into()));
            }
            Ok(receipt)
        });
    } else {
        return Err(Error::WorkerLoop("unknown recovery worker mode".into()));
    }
    emit(
        json!({"event":"worker-starting","pid":std::process::id(),"sdk_version":SDK_VERSION,"mode":mode}),
    );
    worker.run().await
}
