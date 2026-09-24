from __future__ import annotations

import asyncio
import os

from durable_workflow import Client, Worker, workflow


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Set {name} before starting the worker.")
    return value


@workflow.defn(name="sample-app.child-matrix.python.child")
class PythonChildWorkflow:
    def run(self, context, value):
        return {"value": value, "runtime": "python"}


@workflow.defn(name="sample-app.child-matrix.python.parent-php")
class PythonParentPhpWorkflow:
    def run(self, context, value):
        child_result = yield context.start_child_workflow(
            "sample-app.child-matrix.php.child", [value]
        )
        return {"parent_runtime": "python", "child_result": child_result}


@workflow.defn(name="sample-app.child-matrix.python.parent-python")
class PythonParentPythonWorkflow:
    def run(self, context, value):
        child_result = yield context.start_child_workflow(
            "sample-app.child-matrix.python.child", [value]
        )
        return {"parent_runtime": "python", "child_result": child_result}


@workflow.defn(name="sample-app.child-matrix.python.parent-rust")
class PythonParentRustWorkflow:
    def run(self, context, value):
        child_result = yield context.start_child_workflow(
            "sample-app.child-matrix.rust.child", [value]
        )
        return {"parent_runtime": "python", "child_result": child_result}


async def main() -> None:
    async with Client(
        required("DURABLE_WORKFLOW_RUNTIME_URL"),
        namespace=required("DURABLE_WORKFLOW_NAMESPACE"),
        worker_token=required("DURABLE_WORKFLOW_WORKER_TOKEN"),
    ) as client:
        worker = Worker(
            client,
            task_queue=required("DURABLE_WORKFLOW_TASK_QUEUE"),
            worker_id=f"sample-child-matrix-python-{os.getpid()}",
            workflows=[
                PythonChildWorkflow,
                PythonParentPhpWorkflow,
                PythonParentPythonWorkflow,
                PythonParentRustWorkflow,
            ],
            activities=[],
        )
        await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
