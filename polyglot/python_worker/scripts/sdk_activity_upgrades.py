"""Replay nine pending remote-activity directions across SDK/application replacement."""

import argparse
import asyncio
import json
import os
from datetime import datetime, timedelta

RUNTIMES = ("php", "python", "rust")
DIRECTIONS = [(workflow, activity) for workflow in RUNTIMES for activity in RUNTIMES]
VALUE = {"bigint": 9223372036854775805, "optional": None, "ready": True,
         "fraction": 1.25, "note": "café ✓", "items": [0, False, None, "017"]}
TERMINALS = {"WorkflowCompleted", "WorkflowFailed", "WorkflowCancelled", "WorkflowTimedOut", "WorkflowTerminated"}


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def same(left, right):
    return json.dumps(left, sort_keys=True, allow_nan=False) == json.dumps(right, sort_keys=True, allow_nan=False)


def events(history, kind):
    return [event for event in history["events"] if event["event_type"] == kind]


def one(history, kind):
    found = events(history, kind)
    require(len(found) == 1, f"Expected one {kind}, got {len(found)}.")
    return found[0]


def timestamp(value):
    parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    require(parsed.utcoffset() is not None, "Event clock has no timezone.")
    return parsed


def emit(**record):
    print(json.dumps(record, sort_keys=True), flush=True)


def check_commands(history, activity, version):
    marker = one(history, "VersionMarkerRecorded")["payload"]
    expected = {"change_id": "sample-activity-application", "sequence": 1,
                "version": version, "min_supported": 1, "max_supported": version}
    require(all(same(marker.get(key), value) for key, value in expected.items()),
            "Activity replacement changed the recorded version branch or authored bounds.")
    scheduled = one(history, "ActivityScheduled")["payload"]
    snapshot = scheduled.get("activity", {})
    require(type(scheduled.get("sequence")) is int and scheduled["sequence"] == 2
            and scheduled.get("activity_type") == f"polyglot.{activity}.upgrade-activity"
            and isinstance(scheduled.get("activity_execution_id"), str)
            and bool(scheduled["activity_execution_id"])
            and snapshot.get("id") == scheduled["activity_execution_id"]
            and snapshot.get("queue") == f"polyglot-{activity}",
            "Activity command changed its original sequence, execution or target queue.")
    policy = snapshot.get("retry_policy", {})
    expected_policy = {"max_attempts": version + 1, "backoff_seconds": [1] if version == 1 else [1, 2],
                       "start_to_close_timeout": 30, "schedule_to_close_timeout": 120 if version == 1 else 180}
    require(all(same(policy.get(key), value) for key, value in expected_policy.items()),
            "Activity command lost the selected complete retry/timeout policy.")
    kinds = [event["event_type"] for event in history["events"]]
    require(kinds.index("VersionMarkerRecorded") < kinds.index("ActivityScheduled"),
            "Activity was scheduled before its version decision.")
    return scheduled


def check_completed(original, execution, history, result, current_workers, targets, restarted_at, decode):
    workflow, activity, version = original["workflow_runtime"], original["activity_runtime"], original["version"]
    require(execution.workflow_id == original["workflow_id"] and execution.run_id == original["run_id"]
            and execution.status == "completed", "Replacement did not complete the same original run.")
    prefix = original["history_prefix"]
    require(bool(prefix) and same(history["events"][:len(prefix)], prefix),
            "Replacement changed the complete original history prefix.")
    scheduled = check_commands(history, activity, version)
    require(same(scheduled, original["scheduled"]), "Replacement changed the original activity snapshot.")
    worker = next(item for item in current_workers if item["runtime"] == workflow)
    require(worker.get("sdk_version") == f"durable-workflow-{workflow}/{targets[workflow]}",
            "Workflow successor uses another SDK package.")
    if version == 1:
        require(worker["worker_id"] != original["worker_id"], "Pending run did not use a cold replacement.")
    if workflow == "python":
        started = one(history, "WorkflowStarted")["payload"]
        fingerprint = started.get("workflow_definition_fingerprint")
        require(started.get("workflow_definition_fingerprint_source") == "worker" and bool(fingerprint)
                and worker["workflow_definition_fingerprints"].get("polyglot.python.activity-upgrade") == fingerprint,
                "Replacement rebound the original Python workflow definition.")
    started, completed = one(history, "ActivityStarted"), one(history, "ActivityCompleted")
    started_payload, completed_payload = started["payload"], completed["payload"]
    require(started_payload.get("activity_execution_id") == scheduled["activity_execution_id"]
            and completed_payload.get("activity_execution_id") == scheduled["activity_execution_id"]
            and isinstance(started_payload.get("activity_attempt_id"), str)
            and bool(started_payload["activity_attempt_id"])
            and started_payload["activity_attempt_id"] == completed_payload.get("activity_attempt_id")
            and type(started_payload.get("attempt_number")) is int and started_payload["attempt_number"] == 1,
            "Activity was duplicated or its original attempt/result identity changed.")
    require(not events(history, "ActivityRetryScheduled") and not events(history, "ActivityFailed"),
            "Pending activity unexpectedly retried or failed.")
    require(timestamp(started["timestamp"]) >= timestamp(restarted_at),
            "Activity executed before the current-package workers started.")
    total = scheduled["activity"]["retry_policy"]["schedule_to_close_timeout"]
    scheduled_at = timestamp(one(history, "ActivityScheduled")["timestamp"])
    require(scheduled_at <= timestamp(started["timestamp"]) <= timestamp(completed["timestamp"])
            <= scheduled_at + timedelta(seconds=total), "Activity escaped its original total deadline.")
    expected_activity = {"activity_runtime": activity, "application_revision": "v2",
        "sdk_version": f"durable-workflow-{activity}/{targets[activity]}", "attempt_number": 1,
        "request": {"case_id": original["workflow_id"], "version": version, "value": VALUE}}
    expected = {"workflow_runtime": workflow, "application_revision": "v2", "version": version,
                "activity": expected_activity}
    require(same(result, expected) and same(decode(completed_payload["result"]), expected_activity),
            "Current SDK callbacks changed the recorded branch or exact typed activity/workflow result.")
    terminals = [event for event in history["events"] if event["event_type"] in TERMINALS]
    require(len(terminals) == 1 and terminals[0]["event_type"] == "WorkflowCompleted"
            and same(decode(terminals[0]["payload"]["output"]), result)
            and timestamp(terminals[0]["timestamp"]) >= timestamp(completed["timestamp"]),
            "Original workflow has another terminal event or result.")


def original_records(scenario):
    rows = [json.loads(line) for line in os.environ["DURABLE_WORKFLOW_TIMER_PENDING"].splitlines()]
    expected = set(DIRECTIONS)
    actual = [(row.get("workflow_runtime"), row.get("activity_runtime")) for row in rows]
    require(len(rows) == len(expected) and set(actual) == expected
            and all(row.get("phase") == "pending" and row.get("scenario") == scenario for row in rows)
            and len({row.get("run_id") for row in rows}) == len(rows),
            "Pending upgrade set omits, repeats or substitutes an original direction.")
    return rows


def ready_snapshot(roster, common, selected):
    workers = []
    current = selected == common.sdk_versions("DURABLE_WORKFLOW_TIMER_SDK_TARGETS")
    for runtime in RUNTIMES:
        matches = common.active_workers(roster, runtime)
        require(len(matches) <= 1, "Ambiguous active upgrade workers.")
        if not matches:
            return None
        worker = matches[0]
        if (f"polyglot.{runtime}.activity-upgrade" not in (worker.supported_workflow_types or [])
                or (worker.raw or {}).get("sdk_version") != f"durable-workflow-{runtime}/{selected[runtime]}"):
            return None
        advertised = set(worker.supported_activity_types or [])
        if (f"polyglot.{runtime}.upgrade-activity" in advertised) != current:
            return None
        workers.append(common.worker_snapshot(worker))
    return workers


async def ready_workers(client, common, scenario, selected):
    deadline = asyncio.get_running_loop().time() + 20
    while True:
        workers = ready_snapshot(await client.list_workers(), common, selected)
        if workers is not None:
            emit(phase="workers_ready", scenario=scenario, project=common.required("DURABLE_WORKFLOW_TIMER_ID"), workers=workers)
            return
        if asyncio.get_running_loop().time() >= deadline:
            raise TimeoutError("Exact package/role upgrade roster did not become ready.")
        await asyncio.sleep(0.25)


async def observe(client, workflow_id, workflow):
    handle = client.get_workflow_handle(workflow_id, workflow_type=f"polyglot.{workflow}.activity-upgrade")
    execution = await handle.describe()
    return handle, execution, await client.get_history(workflow_id, execution.run_id)


async def start_original(client, common, scenario, selected, workflow, activity):
    fresh = scenario.endswith("-fresh")
    version = 2 if fresh else 1
    workflow_id = f"{common.required('DURABLE_WORKFLOW_TIMER_ID')}-{scenario}-{workflow}-{activity}"
    worker = next(row for row in common.retirement_snapshot() if row["runtime"] == workflow)
    await client.start_workflow(workflow_type=f"polyglot.{workflow}.activity-upgrade", workflow_id=workflow_id,
        task_queue=f"polyglot-{workflow}", input=[{"case_id": workflow_id, "activity_runtime": activity, "value": VALUE}])
    deadline = asyncio.get_running_loop().time() + 20
    while True:
        _, execution, history = await observe(client, workflow_id, workflow)
        require(fresh or not events(history, "ActivityStarted"),
                f"Activity started before replacement: {workflow_id}, run={execution.run_id}, status={execution.status}.")
        if events(history, "ActivityScheduled") and (fresh or execution.status == "waiting"):
            break
        require(execution.status not in {"failed", "cancelled", "terminated"}, "Original closed before activity scheduling.")
        if asyncio.get_running_loop().time() >= deadline:
            raise TimeoutError(f"Pending activity did not arrive: {workflow_id}, status={execution.status}.")
        await asyncio.sleep(0.25)
    scheduled = check_commands(history, activity, version)
    if not fresh:
        require(execution.status == "waiting" and not events(history, "ActivityStarted"),
                "Previous-package activity was not genuinely pending before replacement.")
    emit(phase="pending", scenario=scenario, workflow_runtime=workflow, activity_runtime=activity,
         workflow_id=workflow_id, run_id=execution.run_id, version=version, worker_id=worker["worker_id"],
         sdk_version=selected[workflow], scheduled=scheduled, history_prefix=history["events"])


async def verify_original(client, common, scenario, original, targets, previous):
    from durable_workflow import serializer

    fresh = scenario.endswith("-fresh")
    workflow = original["workflow_runtime"]
    require(type(original.get("version")) is int and original["version"] == (2 if fresh else 1),
            "Original observation changed its old/fresh version branch.")
    require(original["sdk_version"] == (targets if fresh else previous)[workflow], "Original used the wrong SDK package.")
    handle, _, _ = await observe(client, original["workflow_id"], workflow)
    result = await handle.result(timeout=120, poll_interval=0.25)
    _, execution, history = await observe(client, original["workflow_id"], workflow)
    check_completed(original, execution, history, result, common.retirement_snapshot(), targets,
        common.required("DURABLE_WORKFLOW_WORKER_RESTART_AT"), lambda value: serializer.decode_envelope(value, codec="avro"))
    emit(phase="verified", scenario=scenario, workflow_runtime=workflow, activity_runtime=original["activity_runtime"],
         workflow_id=execution.workflow_id, run_id=execution.run_id, result=result,
         original_sdk=original["sdk_version"], current_sdk=targets[workflow],
         marker=one(history, "VersionMarkerRecorded")["payload"], scheduled=original["scheduled"],
         history_events=[row["event_type"] for row in history["events"]])


async def main(phase, scenario):
    from durable_workflow import Client
    import sdk_timers as common

    selected = common.sdk_versions("DURABLE_WORKFLOW_TIMER_SDK_VERSIONS")
    async with Client(common.required("DURABLE_WORKFLOW_SERVER_URL"), namespace="default",
                      control_token=common.required("DURABLE_WORKFLOW_AUTH_TOKEN")) as client:
        if phase == "retire":
            await common.retire_workers(client, scenario)
        elif phase == "ready":
            await ready_workers(client, common, scenario, selected)
        elif phase == "start":
            await asyncio.gather(*(start_original(client, common, scenario, selected, *direction) for direction in DIRECTIONS))
        elif phase == "verify":
            targets = common.sdk_versions("DURABLE_WORKFLOW_TIMER_SDK_TARGETS")
            previous = common.sdk_versions("DURABLE_WORKFLOW_TIMER_SDK_PREVIOUS")
            require(selected == targets and all(previous[runtime] != targets[runtime] for runtime in RUNTIMES),
                    "Verification did not select distinct previous/current packages.")
            await asyncio.gather(*(verify_original(client, common, scenario, original, targets, previous)
                                   for original in original_records(scenario)))
        elif phase == "diagnose":
            for workflow, activity in DIRECTIONS:
                workflow_id = f"{common.required('DURABLE_WORKFLOW_TIMER_ID')}-{scenario}-{workflow}-{activity}"
                _, execution, history = await observe(client, workflow_id, workflow)
                emit(phase="diagnostic", workflow_id=workflow_id, run_id=execution.run_id, status=execution.status,
                     history_events=[row["event_type"] for row in history["events"]])


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("ready", "retire", "start", "verify", "diagnose"))
    parser.add_argument("scenario", choices=("activity-upgrade", "activity-upgrade-fresh"))
    args = parser.parse_args()
    asyncio.run(main(args.phase, args.scenario))
