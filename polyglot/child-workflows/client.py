from __future__ import annotations

import asyncio
import json
import os
import uuid

from durable_workflow import Client


LANGUAGES = ("php", "python", "rust")
CHILD_EVENTS = (
    "WorkflowStarted",
    "ChildWorkflowScheduled",
    "ChildRunStarted",
    "ChildRunCompleted",
    "WorkflowCompleted",
)


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before starting the client.")
    return value


async def main() -> None:
    queue = required("DURABLE_WORKFLOW_TASK_QUEUE")
    marker = f"child-matrix-{uuid.uuid4().hex[:12]}"
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_CLIENT_TOKEN"),
    ) as client:
        for parent in LANGUAGES:
            for child in LANGUAGES:
                workflow_id = f"{marker}-{parent}-{child}"
                handle = await client.start_workflow(
                    workflow_type=f"sample-app.child-matrix.{parent}.parent-{child}",
                    workflow_id=workflow_id,
                    task_queue=queue,
                    input=[marker],
                )
                result = await handle.result(timeout=90.0, poll_interval=0.5)
                expected = {
                    "parent_runtime": parent,
                    "child_result": {"runtime": child, "value": marker},
                }
                if result != expected:
                    raise RuntimeError(f"{parent}/{child}: unexpected result {result!r}")

                history = await handle.get_history()
                events = [event["event_type"] for event in history["events"]]
                matched = [event for event in events if event in CHILD_EVENTS]
                if matched != list(CHILD_EVENTS):
                    raise RuntimeError(f"{parent}/{child}: unexpected history {events!r}")

                print(json.dumps({
                    "parent": parent,
                    "child": child,
                    "workflow_id": workflow_id,
                    "run_id": handle.run_id,
                    "result": result,
                    "history_events": matched,
                }, sort_keys=True), flush=True)

    print("9/9 child-workflow directions completed", flush=True)


if __name__ == "__main__":
    asyncio.run(main())
