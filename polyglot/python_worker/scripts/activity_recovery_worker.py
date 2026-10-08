"""Ordinary published Python SDK workflow and activity workers."""

import asyncio
import importlib.metadata
import json
import os
from pathlib import Path

from durable_workflow import Client, Worker, activity, workflow


async def gate(case_id, suffix):
    async with asyncio.timeout(180):
        while not (Path(os.environ["ACTIVITY_RECOVERY_PROOF"]) / f"{case_id}{suffix}").exists():
            await asyncio.sleep(0.1)


@workflow.defn(name="sample-app.activity-recovery.python")
class RecoveryWorkflow:
    def run(self, context, request):
        runtime = request["activity_runtime"]
        result = yield context.schedule_activity(
            f"sample-app.activity-recovery.{runtime}.work", [request],
            queue=f"activity-recovery-activity-{runtime}",
            retry_policy=workflow.ActivityRetryPolicy(max_attempts=2, backoff_seconds=[2]),
            start_to_close_timeout=60 if request["scenario"] == "total-deadline" else 20,
            schedule_to_close_timeout=30 if request["scenario"] == "total-deadline" else 120,
        )
        return {"workflow_runtime": "python", "activity": result}


@activity.defn(name="sample-app.activity-recovery.python.work")
async def recover(request):
    info = activity.context().info
    receipt = {"case_id": request["case_id"], "runtime": "python",
               "sdk_version": "durable-workflow-python/" + importlib.metadata.version("durable-workflow"),
               "pid": os.getpid(), "task_id": info.task_id, "activity_attempt_id": info.activity_attempt_id,
               "lease_owner": info.worker_id, "attempt_number": info.attempt_number}
    print(json.dumps({"event": "activity-started", "claim": receipt}), flush=True)
    path = Path(os.environ["ACTIVITY_RECOVERY_PROOF"]) / f'{request["case_id"]}.attempt-{info.attempt_number}.json'
    temporary = path.with_suffix(".pending")
    temporary.write_text(json.dumps(receipt))
    temporary.replace(path)
    if info.attempt_number == 1:
        await gate(request["case_id"], ".first-release")
        raise RuntimeError("injected first-attempt failure")
    await gate(request["case_id"], ".release")
    if request["scenario"] == "retry-exhaustion":
        raise RuntimeError("injected second-attempt failure")
    return receipt


async def main():
    mode = os.environ["ACTIVITY_RECOVERY_MODE"]
    if mode not in {"workflow", "activity"}:
        raise ValueError("unknown activity recovery worker mode")
    queue = f"activity-recovery-{mode}-python"
    async with Client(os.environ["DURABLE_WORKFLOW_SERVER_URL"], token=os.environ["DURABLE_WORKFLOW_AUTH_TOKEN"],
                      namespace="default") as client:
        worker = Worker(client, task_queue=queue, worker_id=f'{queue}-{os.environ["HOSTNAME"]}', poll_timeout=2,
                        workflows=[RecoveryWorkflow] if mode == "workflow" else [],
                        activities=[recover] if mode == "activity" else [])
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
