"""Request cancellation through the published Python client, including refusals."""

import asyncio
import json
import os

from durable_workflow import Client
from durable_workflow.errors import RuntimeDiscoveryUnavailable, ServerError, Unauthorized


async def main():
    phase = os.environ["DURABLE_WORKFLOW_CANCELLATION_PHASE"]
    async with Client(os.environ["DURABLE_WORKFLOW_SERVER_URL"],
                      token=os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN") or None,
                      namespace=os.environ["DURABLE_WORKFLOW_NAMESPACE"]) as client:
        for run in json.loads(os.environ["DURABLE_WORKFLOW_CANCELLATION_RUNS"]):
            if run["child" if phase == "duplicate" else "parent"] != "python":
                continue
            record = {"caller": "python", "phase": phase,
                      "workflow_id": run["parent_workflow_id"], "run_id": run["parent_run_id"]}
            handle = client.get_workflow_handle(record["workflow_id"], run_id=record["run_id"])
            try:
                record["response"] = await handle.request_cancellation(
                    reason="duplicate must not replace original" if phase == "duplicate" else "SDK child cancellation conformance",
                    cleanup_timeout_seconds=60 if phase == "duplicate" else 30)
            except RuntimeDiscoveryUnavailable as error:
                if (phase != "deny-anonymous" or not isinstance(error.cause, Unauthorized)
                        or error.operation != "Client.request_workflow_cancellation"):
                    raise
                record["refusal"] = {"status": 401, "reason": "unauthorized"}
                record["sdk_exception"] = type(error).__name__
                record["discovery_cause"] = type(error.cause).__name__
            except ServerError as error:
                expected = {"deny-worker": 403, "deny-anonymous": 401}.get(phase)
                status = getattr(error, "status", None)
                if expected is None or status != expected:
                    raise
                record["refusal"] = {"status": status, "reason": error.reason()}
            else:
                if phase.startswith("deny-"):
                    raise RuntimeError("An unauthorized cancellation succeeded.")
            print(json.dumps(record, sort_keys=True), flush=True)


if __name__ == "__main__":
    asyncio.run(main())
