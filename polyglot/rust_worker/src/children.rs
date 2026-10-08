use durable_workflow::{json, ChildWorkflowOptions, Error, Value, Worker};

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
                "wait" => {
                    context.wait_signal("child-finish").await?;
                }
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
                match context.start_child_workflow(child_type, ChildWorkflowOptions::new(child_queue),
                                                  json!([value, behavior])).await {
                    Ok(child) => Ok(json!({"parent_runtime": "rust", "child_result": child.result})),
                    Err(Error::ChildWorkflowFailed(failure)) => Ok(json!({"parent_runtime": "rust", "child_failure": {
                        "type": "ChildWorkflowFailed", "message": failure.message, "child_type": failure.child_workflow_type,
                    }})),
                    Err(error) => Err(error),
                }
            }
        });
    }
}
