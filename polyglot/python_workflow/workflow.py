"""Python-authored polyglot workflow + activities.

This is the second polyglot scenario: a Python author writes a v2
workflow against the same standalone server the PHP workflow runs
against. The workflow stays inside Python — workflow code, activity
code, and worker container are all Python — so it sits next to the
PHP-authored scenario as the language-symmetric reference.

Running this module starts a long-lived worker that registers
``polyglot.python.greeter`` (and the ``polyglot.python.greet`` /
``polyglot.python.summarise`` activities) on the polyglot Python task
queue. The polyglot smoke driver uses the running worker rather than
spinning up its own embedded poller, so the docker-compose stack is
the actual unit under test.
"""
from __future__ import annotations

import asyncio
import logging
import os
import socket
from typing import Any

from durable_workflow import Client, Worker, activity, workflow

TASK_QUEUE = os.environ.get("POLYGLOT_PY_TASK_QUEUE", "polyglot-python")


@activity.defn(name="polyglot.python.greet")
def greet(name: str, locale: str) -> dict[str, Any]:
    return {
        "language": "python",
        "name": name,
        "locale": locale,
        "greeting": _localised_greeting(name, locale),
    }


@activity.defn(name="polyglot.python.summarise")
def summarise(payload: dict[str, Any]) -> dict[str, Any]:
    return {
        "language": "python",
        "input_keys": sorted(payload.keys()),
        "name_length": len(str(payload["name"])),
    }


@workflow.defn(name="polyglot.python.greeter")
class PythonGreeterWorkflow:
    def run(self, ctx, request):  # type: ignore[no-untyped-def]
        greeting = yield ctx.schedule_activity(
            "polyglot.python.greet",
            [request["name"], request["locale"]],
        )
        summary = yield ctx.schedule_activity(
            "polyglot.python.summarise",
            [greeting],
        )
        return {
            "workflow_runtime": "python",
            "greeting": greeting,
            "summary": summary,
            "request": request,
        }


def _localised_greeting(name: str, locale: str) -> str:
    table = {
        "en": "hello",
        "fr": "bonjour",
        "ja": "こんにちは",
        "es": "hola",
    }
    return f"{table.get(locale, 'hello')}, {name}"


async def main() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(name)s %(levelname)s %(message)s",
    )
    log = logging.getLogger("polyglot.python_workflow_worker")

    server_url = os.environ["DURABLE_WORKFLOW_SERVER_URL"]
    token = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "test-token")
    namespace = os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default")
    worker_id = os.environ.get(
        "POLYGLOT_PY_WORKER_ID",
        f"py-workflow-worker-{socket.gethostname()}",
    )

    async with Client(server_url, token=token, namespace=namespace) as client:
        worker = Worker(
            client,
            task_queue=TASK_QUEUE,
            workflows=[PythonGreeterWorkflow],
            activities=[greet, summarise],
            worker_id=worker_id,
            shutdown_timeout=10.0,
        )
        log.info(
            "polyglot python workflow worker starting: id=%s queue=%s types=[polyglot.python.greeter]",
            worker_id,
            TASK_QUEUE,
        )
        # `worker.run()` calls /api/worker/register, then the SDK emits its
        # own INFO log of the form "worker <id> registered on <queue>". The
        # polyglot CI greps that line as the registration regression test;
        # see polyglot-validation.yml.
        await worker.run()

    return 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
