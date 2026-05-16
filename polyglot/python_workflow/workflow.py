"""Python-authored polyglot workflows + activities.

The worker hosts two Python-authored scenarios against the same
standalone server the PHP workflow runs against:

* ``polyglot.python.greeter`` stays inside Python — workflow code,
  activity code, and worker container are all Python.
* ``polyglot.python-to-php.greeter`` keeps workflow code in Python but
  schedules activities on the PHP activity-worker queue.

Running this module starts a long-lived worker that registers
both workflows (and the ``polyglot.python.greet`` /
``polyglot.python.summarise`` activities) on the polyglot Python task
queue. The PHP activity-worker queue is separate; the Python-to-PHP
workflow names it explicitly when scheduling ``polyglot.python-to-php.*``.
The polyglot smoke driver uses the running worker rather than spinning
up its own embedded poller, so the docker-compose stack is the actual
unit under test.
"""
from __future__ import annotations

import asyncio
import logging
import os
import socket
from typing import Any

from durable_workflow import Client, TransportRetryPolicy, Worker, activity, workflow

TASK_QUEUE = os.environ.get("POLYGLOT_PY_TASK_QUEUE", "polyglot-python")
PHP_ACTIVITY_TASK_QUEUE = os.environ.get("POLYGLOT_PY2PHP_TASK_QUEUE", "polyglot-python-to-php")
POLL_TIMEOUT_SECONDS = float(os.environ.get("DURABLE_WORKFLOW_POLL_TIMEOUT_SECONDS", "90"))


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


@workflow.defn(name="polyglot.python-to-php.greeter")
class PythonToPhpGreeterWorkflow:
    def run(self, ctx, request):  # type: ignore[no-untyped-def]
        marker = yield ctx.schedule_activity(
            "polyglot.python-to-php.marker",
            [request],
            queue=PHP_ACTIVITY_TASK_QUEUE,
        )
        description = yield ctx.schedule_activity(
            "polyglot.python-to-php.describe",
            [marker],
            queue=PHP_ACTIVITY_TASK_QUEUE,
        )
        return {
            "workflow_runtime": "python",
            "activity_runtime": marker.get("runtime") if isinstance(marker, dict) else None,
            "php_marker": marker,
            "php_description": description,
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

    async with Client(
        server_url,
        token=token,
        namespace=namespace,
        timeout=POLL_TIMEOUT_SECONDS + 15,
        retry_policy=TransportRetryPolicy(max_attempts=1),
    ) as client:
        worker = Worker(
            client,
            task_queue=TASK_QUEUE,
            workflows=[PythonGreeterWorkflow, PythonToPhpGreeterWorkflow],
            activities=[greet, summarise],
            worker_id=worker_id,
            poll_timeout=POLL_TIMEOUT_SECONDS,
            shutdown_timeout=10.0,
        )
        log.info(
            "polyglot python workflow worker starting: id=%s queue=%s types=[polyglot.python.greeter,polyglot.python-to-php.greeter]",
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
