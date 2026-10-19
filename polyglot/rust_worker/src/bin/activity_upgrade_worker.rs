use durable_workflow::{json, ActivityOptions, ActivityRetryPolicy, Client, Error, Result, Worker};
use std::{env, time::Duration};

#[tokio::main]
async fn main() -> Result<()> {
    if env::var("POLYGLOT_RUST_MODE").ok().as_deref() == Some("sdk-metadata") {
        println!("{}", json!({"sdk_identity": durable_workflow::SDK_VERSION}));
        return Ok(());
    }
    let revision = match env::var("POLYGLOT_TIMER_APPLICATION").ok().as_deref() {
        Some("v1") => "v1",
        Some("v2") => "v2",
        _ => return Err(Error::WorkerLoop("select activity application v1 or v2".into())),
    };
    let client = Client::builder(env::var("DURABLE_WORKFLOW_SERVER_URL").expect("server URL"))
        .token(Some(env::var("DURABLE_WORKFLOW_AUTH_TOKEN").expect("token")))
        .namespace("default")
        .build()?;
    let mut worker = Worker::new(client, "polyglot-rust")
        .worker_id(format!("activity-upgrade-rust-{}", env::var("HOSTNAME").expect("hostname")))
        .poll_timeout(Duration::from_secs(2));
    worker.register_workflow("polyglot.rust.activity-upgrade", move |context, args| async move {
        let request = args[0].clone();
        let runtime = request["activity_runtime"].as_str().expect("activity runtime");
        let version = context.get_version("sample-activity-application", 1, if revision == "v1" { 1 } else { 2 })?;
        let backoff = if version == 1 { vec![Duration::from_secs(1)] }
            else { vec![Duration::from_secs(1), Duration::from_secs(2)] };
        let options = ActivityOptions::new()
            .task_queue(format!("polyglot-{runtime}"))
            .retry_policy(ActivityRetryPolicy::new((version + 1) as u32).backoff_intervals(backoff))
            .start_to_close_timeout(Duration::from_secs(30))
            .schedule_to_close_timeout(Duration::from_secs(if version == 1 { 120 } else { 180 }));
        let activity = context.activity_with_options(format!("polyglot.{runtime}.upgrade-activity"), options,
            json!([{"case_id":request["case_id"],"version":version,"value":request["value"]}])).await?;
        Ok(json!({"workflow_runtime":"rust","application_revision":revision,"version":version,"activity":activity}))
    });
    if revision == "v2" {
        worker.register_activity("polyglot.rust.upgrade-activity", move |context, args| async move {
            Ok(json!({"activity_runtime":"rust","application_revision":revision,
                "sdk_version":durable_workflow::SDK_VERSION,"attempt_number":context.attempt_number,
                "request":args[0]}))
        });
    }
    worker.run().await
}
