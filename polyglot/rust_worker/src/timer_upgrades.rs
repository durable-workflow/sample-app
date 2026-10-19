use std::time::Duration;

use durable_workflow::{json, Error, ParallelOperation, Result, Worker, WorkflowContext};

type Application = fn(&WorkflowContext, bool) -> Result<(i32, Vec<u64>)>;

fn original_application(ctx: &WorkflowContext, concurrent: bool) -> Result<(i32, Vec<u64>)> {
    let version = ctx.get_version("sample-timer-application", 1, 1)?;
    Ok((version, original_delays(concurrent)))
}

fn upgraded_application(ctx: &WorkflowContext, concurrent: bool) -> Result<(i32, Vec<u64>)> {
    versioned_application(ctx, concurrent, 1)
}

fn unversioned_application(_ctx: &WorkflowContext, concurrent: bool) -> Result<(i32, Vec<u64>)> {
    Ok((-1, original_delays(concurrent)))
}

fn introduced_application(ctx: &WorkflowContext, concurrent: bool) -> Result<(i32, Vec<u64>)> {
    versioned_application(ctx, concurrent, -1)
}

fn versioned_application(
    ctx: &WorkflowContext,
    concurrent: bool,
    minimum: i32,
) -> Result<(i32, Vec<u64>)> {
    let version = ctx.get_version("sample-timer-application", minimum, 2)?;
    let delays = original_delays(concurrent)
        .into_iter()
        .map(|delay| if version == 2 { delay + 15 } else { delay })
        .collect();
    Ok((version, delays))
}

fn original_delays(concurrent: bool) -> Vec<u64> {
    if concurrent {
        vec![60, 30, 45]
    } else {
        vec![30]
    }
}

pub fn register(worker: &mut Worker, revision: &str) -> Result<()> {
    let (application, introduction, revision): (Application, Application, &'static str) = match revision {
        "v1" => (original_application, unversioned_application, "v1"),
        "v2" => (upgraded_application, introduced_application, "v2"),
        _ => {
            return Err(Error::WorkerLoop(
                "unknown timer application revision".into(),
            ))
        }
    };
    for (unversioned, application) in [(false, application), (true, introduction)] {
        for concurrent in [false, true] {
            let workflow_type = match (unversioned, concurrent) {
                (false, false) => "polyglot.rust.upgrade-timer",
                (false, true) => "polyglot.rust.upgrade-concurrent-timers",
                (true, false) => "polyglot.rust.unversioned-upgrade-timer",
                (true, true) => "polyglot.rust.unversioned-upgrade-concurrent-timers",
            };
            worker.register_workflow(workflow_type, move |ctx, input| async move {
                let request = super::first_argument(&input);
                let (version, delays) = application(&ctx, concurrent)?;
                if concurrent {
                    ctx.parallel(delays.iter().map(|delay| ParallelOperation::timer(Duration::from_secs(*delay))).collect()).await?;
                } else {
                    ctx.sleep(Duration::from_secs(delays[0])).await?;
                }
                Ok(json!({"workflow_runtime": "rust", "request": request, "application_revision": revision,
                    "version": version, "timer_seconds": if concurrent { json!(delays) } else { json!(delays[0]) }}))
            });
        }
    }
    Ok(())
}
