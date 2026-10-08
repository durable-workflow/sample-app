use std::time::Duration;

use durable_workflow::{
    json, CancellationPolicy, ChildWorkflowOptions, Error, ParentClosePolicy, Result, Value,
    Worker, WorkflowContext,
};

async fn cleanup(context: &WorkflowContext, role: &str) -> Result<()> {
    let cancellation = context.cancellation_context()?.ok_or_else(|| {
        Error::Codec("Child cancellation lacks its committed workflow context.".into())
    })?;
    let _shield = context.cancellation_shield()?;
    let entry = json!({"role": role, "stage": "entry", "runtime": "rust",
        "context": cancellation.to_value(), "remaining": cancellation.remaining()?.as_secs_f64()});
    let _: Value = context.side_effect(|| entry)?;
    context
        .sleep(Duration::from_secs(if role == "child" { 10 } else { 1 }))
        .await?;
    let finished = json!({"role": role, "stage": "finished", "runtime": "rust",
        "context": cancellation.to_value(), "remaining": cancellation.remaining()?.as_secs_f64()});
    let _: Value = context.side_effect(|| finished)?;
    Ok(())
}

pub fn register(worker: &mut Worker, common_queue: Option<String>) {
    worker.register_workflow(
        "sample-app.child-matrix.rust.child",
        |context, input| async move {
            let value = input.get(0).cloned().unwrap_or(Value::Null);
            let behavior = input.get(1).and_then(Value::as_str).unwrap_or("complete");
            match behavior {
                "fail" => {
                    return Err(Error::Codec(format!(
                        "child-probe-failure: {}",
                        value.as_str().unwrap_or_default()
                    )))
                }
                "wait" | "cancel" => match context.wait_signal("child-finish").await {
                    Err(Error::CooperativeCancellationRequested(_)) => {
                        cleanup(&context, "child").await?;
                        return Ok(json!({"cleanup": "finished"}));
                    }
                    outcome => {
                        outcome?;
                    }
                },
                "complete" => {}
                _ => return Err(Error::Codec("Unknown child behavior.".into())),
            }
            Ok(json!({"value": value, "runtime": "rust"}))
        },
    );
    worker
        .declare_workflow_signals("sample-app.child-matrix.rust.child", &["child-finish"])
        .expect("declare the child completion signal");

    for child_runtime in ["php", "python", "rust"] {
        let workflow_type = format!("sample-app.child-matrix.rust.parent-{child_runtime}");
        let child_type = format!("sample-app.child-matrix.{child_runtime}.child");
        let child_queue = common_queue
            .clone()
            .unwrap_or_else(|| format!("polyglot-{child_runtime}"));
        worker.register_workflow(workflow_type, move |context, input| {
            let child_type = child_type.clone();
            let child_queue = child_queue.clone();
            async move {
                let value = input.get(0).cloned().unwrap_or(Value::Null);
                let behavior = input.get(1).cloned().unwrap_or_else(|| json!("complete"));
                let mut options = ChildWorkflowOptions::new(child_queue);
                if behavior == "cancel" {
                    options = options.cancellation_policy(CancellationPolicy::WaitCancellationCompleted)
                        .parent_close_policy(ParentClosePolicy::RequestCancellation);
                }
                match context.start_child_workflow(child_type, options,
                                                  json!([value, behavior])).await {
                    Ok(child) => Ok(json!({"parent_runtime": "rust", "child_result": child.result})),
                    Err(Error::CooperativeCancellationRequested(_)) => {
                        cleanup(&context, "parent").await?;
                        Ok(json!({"cleanup": "finished"}))
                    }
                    Err(Error::ChildWorkflowFailed(failure)) => Ok(json!({"parent_runtime": "rust", "child_failure": {
                        "type": "ChildWorkflowFailed", "message": failure.message, "child_type": failure.child_workflow_type,
                    }})),
                    Err(error) => Err(error),
                }
            }
        });
    }
}
