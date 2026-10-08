"""Shared child workflow examples for the manual and published Compose matrix."""

import os

from durable_workflow import CancellationPolicy, ParentClosePolicy, workflow
from durable_workflow.errors import ChildWorkflowFailed, WorkflowCancelled


def cleanup(context, cancelled, role):
    cancellation = context.cancellation_context
    if cancellation is None or cancellation is not cancelled.context:
        raise RuntimeError("Child cancellation lacks its committed workflow context.")
    with context.cancellation_shield():
        yield context.side_effect(lambda: {"role": role, "stage": "entry", "runtime": "python",
            "context": cancellation.to_dict(), "remaining": cancellation.remaining()})
        yield context.start_timer(10 if role == "child" else 1)
        yield context.side_effect(lambda: {"role": role, "stage": "finished", "runtime": "python",
            "context": cancellation.to_dict(), "remaining": cancellation.remaining()})
    raise cancelled


def child_queue(runtime):
    return os.environ.get("DURABLE_WORKFLOW_TASK_QUEUE") or f"polyglot-{runtime}"


def child_result(context, runtime, value, behavior):
    try:
        options = {}
        if behavior == "cancel":
            options = {"cancellation_policy": CancellationPolicy.WAIT_CANCELLATION_COMPLETED,
                       "parent_close_policy": ParentClosePolicy.REQUEST_CANCELLATION}
        result = yield context.start_child_workflow(
            f"sample-app.child-matrix.{runtime}.child", [value, behavior],
            task_queue=child_queue(runtime), **options,
        )
        return {"parent_runtime": "python", "child_result": result}
    except WorkflowCancelled as cancelled:
        yield from cleanup(context, cancelled, "parent")
    except ChildWorkflowFailed as failure:
        return {"parent_runtime": "python", "child_failure": {
            "type": "ChildWorkflowFailed", "message": str(failure),
            "child_type": failure.child_workflow_type,
        }}


@workflow.defn(name="sample-app.child-matrix.python.child")
class PythonChildWorkflow:
    def __init__(self):
        self.finished = False

    @workflow.signal("child-finish")
    def finish(self):
        self.finished = True

    def run(self, context, value, behavior="complete"):
        if behavior == "fail":
            raise ValueError(f"child-probe-failure: {value}")
        if behavior in ("wait", "cancel"):
            try:
                yield context.wait_condition(lambda: self.finished, key="child-finish")
            except WorkflowCancelled as cancelled:
                yield from cleanup(context, cancelled, "child")
        elif behavior != "complete":
            raise ValueError("Unknown child behavior.")
        return {"value": value, "runtime": "python"}


@workflow.defn(name="sample-app.child-matrix.python.parent-php")
class PythonParentPhpWorkflow:
    def run(self, context, value, behavior="complete"):
        return (yield from child_result(context, "php", value, behavior))


@workflow.defn(name="sample-app.child-matrix.python.parent-python")
class PythonParentPythonWorkflow:
    def run(self, context, value, behavior="complete"):
        return (yield from child_result(context, "python", value, behavior))


@workflow.defn(name="sample-app.child-matrix.python.parent-rust")
class PythonParentRustWorkflow:
    def run(self, context, value, behavior="complete"):
        return (yield from child_result(context, "rust", value, behavior))


CHILD_WORKFLOWS = (PythonChildWorkflow, PythonParentPhpWorkflow,
                   PythonParentPythonWorkflow, PythonParentRustWorkflow)
