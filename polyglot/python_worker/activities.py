"""Python activities consumed by the polyglot PHP-authored workflow.

The PHP workflow worker schedules `polyglot.php-to-python.reverse` and
`polyglot.php-to-python.tally`; this Python container is the worker that
actually executes them. Both workers poll the same task queue against
one standalone Durable Workflow server, so each scheduled activity
crosses the language boundary on the wire.
"""
from __future__ import annotations

import asyncio
import logging
import os
from typing import Any

from durable_workflow import Client, Worker, activity

TASK_QUEUE = os.environ.get("POLYGLOT_PHP2PY_TASK_QUEUE", "polyglot-php-to-python")


@activity.defn(name="polyglot.php-to-python.reverse")
def reverse_string(value: str) -> dict[str, Any]:
    return {
        "runtime": "python",
        "input": value,
        "reversed": value[::-1],
        "length": len(value),
    }


@activity.defn(name="polyglot.php-to-python.tally")
def tally(items: list[dict[str, Any]]) -> dict[str, Any]:
    total = 0
    for item in items:
        total += int(item["quantity"]) * int(item["unit_price_cents"])
    return {
        "runtime": "python",
        "item_count": len(items),
        "total_cents": total,
    }


async def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(name)s %(levelname)s %(message)s")
    log = logging.getLogger("polyglot.python_worker")

    server_url = os.environ["DURABLE_WORKFLOW_SERVER_URL"]
    token = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "test-token")
    namespace = os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default")

    async with Client(server_url, token=token, namespace=namespace) as client:
        worker = Worker(
            client,
            task_queue=TASK_QUEUE,
            workflows=[],
            activities=[reverse_string, tally],
            shutdown_timeout=10.0,
        )
        log.info("polyglot python activity worker ready on queue %s", TASK_QUEUE)
        await worker.run()

    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
