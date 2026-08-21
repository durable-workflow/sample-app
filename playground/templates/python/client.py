from __future__ import annotations

import asyncio
import json
import os
import uuid
from typing import Any

from durable_workflow import Client


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before starting the client.")
    return value


async def main() -> None:
    scenario: dict[str, Any] = json.loads(required("SAMPLE_APP_PLAYGROUND_SCENARIO"))
    workflow_id = f"{scenario['workflow_id_prefix']}-{uuid.uuid4().hex}"
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        control_token=required("DURABLE_WORKFLOW_CLIENT_TOKEN"),
    ) as client:
        handle = await client.start_workflow(
            workflow_type=scenario["workflow_type"],
            workflow_id=workflow_id,
            task_queue=scenario["task_queue"],
            input=[scenario["input"]],
        )
        result = await handle.result(timeout=90.0, poll_interval=0.5)

    print(
        json.dumps(
            {
                "workflow_id": workflow_id,
                "run_id": handle.run_id,
                "result": result,
            },
            separators=(",", ":"),
        )
    )


if __name__ == "__main__":
    asyncio.run(main())
