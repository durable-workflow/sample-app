//! Published SDK workers for remote activity retry and attempt recovery.
use durable_workflow::{json, ActivityOptions, ActivityRetryPolicy, Client, Error, Result, Value, Worker, SDK_VERSION};
use std::{env, io::Write, time::Duration};

fn emit(value: Value) {
    println!("{value}");
    std::io::stdout().flush().expect("flush observation");
}

#[tokio::main]
async fn main() -> Result<()> {
    let client = Client::builder(env::var("DURABLE_WORKFLOW_SERVER_URL").expect("server"))
        .token(Some(env::var("DURABLE_WORKFLOW_AUTH_TOKEN").expect("token")))
        .namespace("default")
        .build()?;
    let mode = env::var("ACTIVITY_RECOVERY_MODE").expect("mode");
    if mode == "stale" {
        let claim: Value = serde_json::from_str(&env::var("ACTIVITY_RECOVERY_CLAIM").expect("claim"))?;
        let result = client.complete_activity_task(
            claim["task_id"].as_str().expect("task"),
            claim["activity_attempt_id"].as_str().expect("attempt"),
            claim["lease_owner"].as_str().expect("lease"),
            json!({"stale":true}), "avro").await;
        return match result {
            Err(Error::ActivityTaskRejected(rejection)) => {
                emit(json!({"event":"stale-rejected","sdk_version":SDK_VERSION,
                    "reason":rejection.reason,"task_id":rejection.task_id,
                    "activity_attempt_id":rejection.activity_attempt_id}));
                Ok(())
            }
            Err(error) => Err(error),
            Ok(_) => Err(Error::WorkerLoop("stale activity attempt was accepted".into())),
        };
    }
    let queue = format!("activity-recovery-{mode}-rust");
    let worker_id = queue.clone();
    let mut worker = Worker::new(client, queue).worker_id(worker_id).poll_timeout(Duration::from_secs(2));
    if mode == "workflow" {
        worker.register_workflow("sample-app.activity-recovery.rust", |ctx, args| async move {
            let request = args[0].clone();
            let runtime = request["activity_runtime"].as_str().expect("activity runtime");
            let options = ActivityOptions::new()
                .task_queue(format!("activity-recovery-activity-{runtime}"))
                .retry_policy(ActivityRetryPolicy::new(2).backoff_intervals([Duration::from_secs(2)]))
                .start_to_close_timeout(Duration::from_secs(8))
                .schedule_to_close_timeout(Duration::from_secs(60));
            let result = ctx.activity_with_options(format!("sample-app.activity-recovery.{runtime}.work"),
                options, json!([request])).await?;
            Ok(json!({"workflow_runtime":"rust","activity":result}))
        });
        worker.set_workflow_definition_sources("sample-app.activity-recovery.rust", &[include_str!("activity_recovery_worker.rs")])?;
    } else if mode == "activity" {
        worker.register_activity("sample-app.activity-recovery.rust.work", |ctx, args| async move {
            let request = args[0].clone();
            let receipt = json!({"case_id":request["case_id"],"runtime":"rust","sdk_version":SDK_VERSION,
                "pid":std::process::id(),"task_id":ctx.task_id,"activity_attempt_id":ctx.activity_attempt_id,
                "lease_owner":ctx.lease_owner,"attempt_number":ctx.attempt_number});
            emit(json!({"event":"activity-started","claim":receipt}));
            if ctx.attempt_number == 1 {
                if request["scenario"] == "retry" {
                    return Err(Error::WorkerLoop("injected first-attempt failure".into()));
                }
                tokio::time::sleep(Duration::from_secs(120)).await;
                return Err(Error::WorkerLoop("first attempt was not killed".into()));
            }
            Ok(receipt)
        });
    } else {
        return Err(Error::WorkerLoop("unknown recovery worker mode".into()));
    }
    emit(json!({"event":"worker-starting","pid":std::process::id(),"sdk_version":SDK_VERSION,"mode":mode}));
    worker.run().await
}
