"""Observe actual SDK claims, retries and durable results on the published Server."""

import argparse
import asyncio
import importlib.metadata
import json
import os
from datetime import datetime, timedelta
from pathlib import Path

from durable_workflow import Client, serializer

DIRECTIONS = {(workflow, activity) for workflow in ("php", "python", "rust") for activity in ("php", "python", "rust")}
SCENARIOS = {"retry", "worker-loss", "total-deadline", "retry-exhaustion", "progress-heartbeat"}
FAILURE_SCENARIOS = {"total-deadline", "retry-exhaustion"}
TERMINAL = {"WorkflowCompleted", "WorkflowFailed", "WorkflowCancelled", "WorkflowTerminated", "WorkflowTimedOut"}


def emit(record):
    print(json.dumps(record, sort_keys=True), flush=True)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def path(suffix):
    return Path(os.environ["ACTIVITY_RECOVERY_PROOF"]) / (os.environ["ACTIVITY_RECOVERY_CASE"] + suffix)


def save(suffix, value):
    path(suffix).write_text(json.dumps(value, indent=2, sort_keys=True) + "\n")


def read(suffix):
    return json.loads(path(suffix).read_text())


def events(history, kind):
    return [event for event in history["events"] if event["event_type"] == kind]


def one(history, kind):
    found = events(history, kind)
    require(len(found) == 1, f"Expected one {kind}, got {len(found)}.")
    return found[0]


def timestamp(value):
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


async def observe(client, record):
    run = await client.describe_workflow_run(record["workflow_id"], record["run_id"])
    history, token, seen = [], None, set()
    while True:
        page = await client.get_history(record["workflow_id"], run_id=record["run_id"], next_page_token=token)
        history.extend(page["events"])
        token = page.get("next_page_token")
        if not token:
            return run, {"events": history}
        require(token not in seen, "History pagination repeated a token.")
        seen.add(token)


async def ownership(client, claim):
    result = await client.activity_task_status(**{key: claim[key] for key in ("task_id", "activity_attempt_id", "lease_owner")})
    require(result.get("can_continue") is True and result.get("heartbeat_recorded") is False,
            "Checkpoint does not hold a live claim without recording an application heartbeat.")
    require(result["activity_attempt_id"] == claim["activity_attempt_id"], "Claim observation selected another attempt.")
    require(result.get("deadlines", {}).get("schedule_to_close"), "Original total activity deadline is missing.")
    return result


def verify_attempts(history, record, first, second):
    scheduled = one(history, "ActivityScheduled")["payload"]
    starts = events(history, "ActivityStarted")
    require(len(starts) == 2, "Retry did not produce exactly two started attempts.")
    require([event["payload"]["attempt_number"] for event in starts] == [1, 2], "Attempt numbering changed.")
    require([event["payload"]["activity_attempt_id"] for event in starts] ==
            [first["activity_attempt_id"], second["activity_attempt_id"]], "Worker receipts differ from durable attempt IDs.")
    require(first["activity_attempt_id"] != second["activity_attempt_id"], "Retry reused the old attempt identity.")
    require(all(event["payload"]["activity_execution_id"] == scheduled["activity_execution_id"] for event in starts),
            "Retry replaced the original activity execution.")
    require(scheduled["activity_type"] == f'sample-app.activity-recovery.{record["activity_runtime"]}.work',
            "Activity was routed to a different language handler.")
    retry = one(history, "ActivityRetryScheduled")
    require(retry["payload"]["activity_execution_id"] == scheduled["activity_execution_id"]
            and retry["payload"]["retry_after_attempt_id"] == first["activity_attempt_id"]
            and retry["payload"]["retry_after_attempt"] == 1, "Retry is not linked to the original first attempt.")
    require(retry["payload"]["retry_backoff_seconds"] == 2, "Retry changed its recorded backoff.")
    require(timestamp(starts[1]["timestamp"]) >= timestamp(retry["payload"]["retry_available_at"]), "Retry started before its durable backoff.")
    if record["scenario"] == "worker-loss":
        require(retry["payload"].get("timeout_kind") == "start_to_close", "Worker loss did not exhaust the original attempt deadline.")
    if record["scenario"] == "progress-heartbeat":
        require(retry["payload"].get("timeout_kind") == "heartbeat", "Missing progress did not cause a heartbeat retry.")
    return scheduled["activity_execution_id"]


def verify_progress(history, record, first, status):
    beats = [event for event in events(history, "ActivityHeartbeatRecorded")
             if event["payload"]["activity_attempt_id"] == first["claim"]["activity_attempt_id"]]
    require(len(beats) == 5, "Expected five actual application progress heartbeats.")
    execution_id = one(history, "ActivityScheduled")["payload"]["activity_execution_id"]
    for step, event in enumerate(beats, start=1):
        payload = event["payload"]
        details = payload.get("progress", {}).get("details")
        expected = {"case_id": record["workflow_id"], "runtime": record["activity_runtime"],
                    "attempt": 1, "step": step, "fraction": 0.5,
                    "ready": True, "optional": None, "note": "café ✓"}
        require(payload["activity_execution_id"] == execution_id and payload["attempt_number"] == 1
                and details == expected and type(details["step"]) is int
                and type(details["ready"]) is bool and type(details["fraction"]) is float,
                "Progress lost its actual attempt, ordered step, typed values or Unicode details.")
    times = [timestamp(event["payload"]["heartbeat_at"]) for event in beats]
    require(all(later > earlier for earlier, later in zip(times, times[1:]))
            and (times[-1] - times[0]).total_seconds() > 10,
            "Progress did not keep the same attempt alive beyond its first heartbeat deadline.")
    require(status.get("can_continue") is True and status.get("heartbeat_recorded") is False
            and abs((timestamp(status["last_heartbeat_at"]) - times[-1]).total_seconds()) < 1
            and abs((timestamp(status["deadlines"]["heartbeat"]) - times[-1] - timedelta(seconds=10)).total_seconds()) < 1,
            "Read-only status does not expose the accepted progress and renewed heartbeat deadline.")
    for kind in ("start_to_close", "schedule_to_close"):
        require(status["deadlines"][kind] == first["status"]["deadlines"][kind],
                "Progress changed the original attempt or total deadline.")
    require(len(events(history, "ActivityStarted")) == 1, "Healthy progress caused another attempt.")
    return beats


def verify_unchanged_history(history, reference, record, second):
    if record["scenario"] != "progress-heartbeat":
        require(history == reference, "Stale completion changed durable history.")
        return
    prefix = reference["events"]
    require(history["events"][:len(prefix)] == prefix, "A stale write changed the acknowledged history prefix.")
    require(all(event["event_type"] == "ActivityHeartbeatRecorded"
                and event["payload"]["activity_attempt_id"] == second["claim"]["activity_attempt_id"]
                for event in history["events"][len(prefix):]),
            "An obsolete claim changed history beyond live retry progress.")


def verify_terminal_failure(history, record, first, second):
    require(record["scenario"] in FAILURE_SCENARIOS, "Not a terminal activity scenario.")
    execution_id = verify_attempts(history, record, first["claim"], second["claim"])
    require(one(history, "WorkflowStarted")["payload"]["workflow_run_id"] == record["run_id"],
            "Failure history belongs to another run.")
    require(history["events"][:len(second["history"]["events"])] == second["history"]["events"],
            "Failure changed the acknowledged history prefix.")
    terminals = [event for event in history["events"] if event["event_type"] in TERMINAL]
    require(len(terminals) == 1 and terminals[0]["event_type"] == "WorkflowFailed",
            "Unhandled activity failure did not produce one workflow failure.")
    activity_terminals = [event for event in history["events"] if event["event_type"] in
                          {"ActivityCompleted", "ActivityFailed", "ActivityTimedOut", "ActivityCancelled"}]
    kind = "ActivityTimedOut" if record["scenario"] == "total-deadline" else "ActivityFailed"
    require(len(activity_terminals) == 1 and activity_terminals[0]["event_type"] == kind,
            "Activity has another terminal result or successful completion.")
    terminal = activity_terminals[0]
    failure = terminal["payload"]
    require(failure["activity_execution_id"] == execution_id
            and failure["activity_attempt_id"] == second["claim"]["activity_attempt_id"]
            and failure["attempt_number"] == 2, "Failure is not the actual second attempt.")
    require(timestamp(terminals[0]["timestamp"]) >= timestamp(terminal["timestamp"]),
            "Workflow failure preceded its activity failure.")
    require(failure["message"] and failure["message"] in terminals[0]["payload"]["message"],
            "Workflow failure lost the actual terminal activity cause.")
    deadline = timestamp(first["status"]["deadlines"]["schedule_to_close"])
    if record["scenario"] == "total-deadline":
        require(failure.get("timeout_kind") == "schedule_to_close"
                and timestamp(terminal["timestamp"]) >= deadline,
                "Total deadline expired early or as a different timeout.")
    else:
        require(failure.get("non_retryable") is False
                and "injected second-attempt failure" in failure["message"]
                and "injected second-attempt failure" in terminals[0]["payload"]["message"],
                "Exhaustion lost the actual retryable activity failure.")
        require(timestamp(terminal["timestamp"]) < deadline, "Exhaustion was caused by the total deadline.")
    return execution_id


def verify_results(results):
    expected = {(w, a, scenario) for w, a in DIRECTIONS for scenario in SCENARIOS}
    require(len(results) == len(expected)
            and {(row["workflow_runtime"], row["activity_runtime"], row["scenario"]) for row in results} == expected,
            "Missing or duplicate recovery directions.")
    by_case = {(row["workflow_runtime"], row["activity_runtime"], row["scenario"]): row for row in results}
    for w, a in DIRECTIONS:
        require(by_case[w, a, "total-deadline"]["second_claim"]["lease_owner"]
                == by_case[w, a, "retry-exhaustion"]["first_claim"]["lease_owner"],
                "The activity worker did not keep its identity when taking the next task after deadline expiry.")


async def main(phase):
    if phase == "summary":
        proof = Path(os.environ["ACTIVITY_RECOVERY_PROOF"])
        results = [json.loads(file.read_text()) for file in proof.glob("*.result.json")]
        verify_results(results)
        report = {"outcome": "pass", "schema": "durable-workflow.sample-app.activity-recovery/v1",
                  "runner_commit": os.environ.get("ACTIVITY_RECOVERY_RUNNER_COMMIT"),
                  "observer_sdk_version": importlib.metadata.version("durable-workflow"),
                  "cases": results}
        (proof / "activity-recovery-result.json").write_text(json.dumps(report, indent=2, sort_keys=True) + "\n")
        emit(report)
        return
    async with Client(os.environ["DURABLE_WORKFLOW_SERVER_URL"], token=os.environ["DURABLE_WORKFLOW_AUTH_TOKEN"], namespace="default") as client:
        if phase == "start":
            w, a = os.environ["ACTIVITY_RECOVERY_WORKFLOW"], os.environ["ACTIVITY_RECOVERY_ACTIVITY"]
            require((w, a) in DIRECTIONS, "Unsupported recovery direction.")
            request = {"case_id": os.environ["ACTIVITY_RECOVERY_CASE"], "activity_runtime": a,
                       "scenario": os.environ["ACTIVITY_RECOVERY_SCENARIO"]}
            require(request["scenario"] in SCENARIOS, "Unsupported recovery scenario.")
            await client.start_workflow(workflow_type=f"sample-app.activity-recovery.{w}",
                                        task_queue=f"activity-recovery-workflow-{w}", workflow_id=request["case_id"], input=[request])
            run = await client.describe_workflow(request["case_id"])
            record = {"workflow_id": request["case_id"], "run_id": run.run_id, "workflow_runtime": w,
                      "activity_runtime": a, "scenario": request["scenario"]}
            require(record["run_id"], "Start did not expose the original run identity.")
            save(".run.json", record)
            emit(record)
            return
        record = read(".run.json")
        if phase == "release-first":
            path(".first-release").touch()
            return
        if phase == "release":
            path(".release").touch()
            return
        if phase == "progress":
            async with asyncio.timeout(40):
                while not path(".progress-ready").exists():
                    await asyncio.sleep(0.2)
            first = read(".first.json")
            status = await ownership(client, first["claim"])
            run, history = await observe(client, record)
            require(run.run_id == record["run_id"] and run.status not in {"failed", "completed"},
                    "Progress belongs to another or terminal run.")
            beats = verify_progress(history, record, first, status)
            save(".progress.json", {"history": history, "status": status, "heartbeats": beats})
            emit({**record, "checkpoint": "progress", "accepted_heartbeats": len(beats), "deadlines": status["deadlines"]})
            return
        if phase in {"first", "second"}:
            attempt = 1 if phase == "first" else 2
            async with asyncio.timeout(50):
                while not path(f".attempt-{attempt}.json").exists():
                    await asyncio.sleep(0.2)
            claim = read(f".attempt-{attempt}.json")
            require(claim["case_id"] == record["workflow_id"] and claim["runtime"] == record["activity_runtime"]
                    and claim["attempt_number"] == attempt and claim["pid"] > 0, "Worker receipt has the wrong actual identity.")
            expected = os.environ[f'DURABLE_WORKFLOW_{record["activity_runtime"].upper()}_SDK_VERSION']
            sdk = "durable-workflow-rust" if record["activity_runtime"] == "rust" else f'durable-workflow-{record["activity_runtime"]}'
            require(claim["sdk_version"] == f"{sdk}/{expected}", "Worker did not execute the selected installed SDK.")
            status = await ownership(client, claim)
            run, history = await observe(client, record)
            require(run.run_id == record["run_id"] and not events(history, "WorkflowCompleted")
                    and not events(history, "ActivityCompleted"), "Checkpoint is not the original pending execution.")
            snapshot = {"claim": claim, "status": status, "history": history}
            save(f".{phase}.observation.json", snapshot)
            if phase == "first":
                require(len(events(history, "ActivityStarted")) == 1, "First checkpoint has additional attempts.")
                policy = one(history, "ActivityScheduled")["payload"]["activity"]["retry_policy"]
                attempt_budget, total_budget = ((60, 120) if record["scenario"] == "progress-heartbeat"
                                               else ((30, 30) if record["scenario"] == "total-deadline" else (20, 120)))
                require(policy["max_attempts"] == 2 and policy["backoff_seconds"] == [2]
                        and policy["start_to_close_timeout"] == attempt_budget and policy["schedule_to_close_timeout"] == total_budget,
                        "SDK command did not record the selected attempt, backoff and timeout budgets.")
                if record["scenario"] == "progress-heartbeat":
                    require(policy["heartbeat_timeout"] == 10, "SDK did not record the ten-second progress heartbeat budget.")
            else:
                first = read(".first.json")
                verify_attempts(history, record, first["claim"], claim)
                if record["scenario"] == "worker-loss":
                    require(claim["lease_owner"] != first["claim"]["lease_owner"],
                            "Replacement reused the killed worker's registration identity.")
                    require(timestamp(events(history, "ActivityStarted")[1]["timestamp"])
                            >= timestamp(first["status"]["deadlines"]["start_to_close"]),
                            "Replacement claimed work before the original attempt deadline.")
                require(status["deadlines"]["schedule_to_close"] == first["status"]["deadlines"]["schedule_to_close"],
                        "Retry reset the original total deadline.")
                require(timestamp(status["deadlines"]["start_to_close"]) > timestamp(first["status"]["deadlines"]["start_to_close"]),
                        "Retry did not receive its own attempt deadline.")
                if record["scenario"] == "progress-heartbeat":
                    progress = read(".progress.json")
                    retry = one(history, "ActivityRetryScheduled")
                    require(timestamp(retry["timestamp"]) >= timestamp(progress["status"]["deadlines"]["heartbeat"])
                            and timestamp(retry["timestamp"]) < timestamp(first["status"]["deadlines"]["start_to_close"])
                            and claim["lease_owner"] == first["claim"]["lease_owner"],
                            "Heartbeat retry expired early, used another deadline or lost worker continuity.")
                    beats = [event for event in events(history, "ActivityHeartbeatRecorded")
                             if event["payload"]["activity_attempt_id"] == first["claim"]["activity_attempt_id"]]
                    require(beats == progress["heartbeats"], "First attempt progress changed after timeout.")
            save(f".{phase}.json", snapshot)
            emit({**record, "checkpoint": phase, "claim": claim, "deadlines": status["deadlines"]})
            return
        first, second = read(".first.json"), read(".second.json")
        if phase == "late-heartbeat":
            rejection = read(".late-heartbeat.json")
            require(rejection["event"] == "late-heartbeat-rejected" and rejection["status"] in {200, 409}
                    and rejection["heartbeat_recorded"] is False and rejection["can_continue"] is False
                    and rejection["reason"] in {"attempt_closed", "stale_attempt", "stale_task", "task_not_leased"},
                    "Expired attempt heartbeat did not withdraw authority precisely.")
            _, history = await observe(client, record)
            verify_unchanged_history(history, read(".stale-check.json")["history"], record, second)
            await ownership(client, second["claim"])
            save(".late-heartbeat-check.json", {"rejection": rejection, "history": history})
            emit({**record, "checkpoint": "late-heartbeat", "reason": rejection["reason"]})
            return
        if phase in {"terminal", "terminal-stale"}:
            async with asyncio.timeout(50):
                while True:
                    run, history = await observe(client, record)
                    if run.status in {"completed", "failed", "cancelled", "terminated", "timed_out"}:
                        break
                    await asyncio.sleep(0.2)
            save(f".{phase}.observation.json", {"history": history, "run_status": run.status})
            require(run.run_id == record["run_id"] and run.status == "failed", "Original run did not fail.")
            execution_id = verify_terminal_failure(history, record, first, second)
            if phase == "terminal":
                save(".terminal.json", {"history": history})
                emit({**record, "checkpoint": "terminal", "run_status": run.status})
                return
            rejection = read(".terminal-stale.json")
            require(rejection["event"] == "stale-rejected" and rejection["status"] == 409
                    and rejection["reason"] in {"stale_attempt", "attempt_closed"}
                    and rejection["task_id"] == second["claim"]["task_id"]
                    and rejection["activity_attempt_id"] == second["claim"]["activity_attempt_id"],
                    "Closed attempt did not refuse a late result precisely.")
            require(history == read(".terminal.json")["history"], "Late completion changed terminal history.")
            status = await client.activity_task_status(**{key: second["claim"][key]
                                                       for key in ("task_id", "activity_attempt_id", "lease_owner")})
            require(status.get("can_continue") is False and status.get("heartbeat_recorded") is False
                    and status.get("reason") == "attempt_closed"
                    and status.get("activity_status") == "failed" and status.get("attempt_status") == "failed"
                    and status.get("deadlines", {}).get("schedule_to_close") == first["status"]["deadlines"]["schedule_to_close"],
                    "Closed attempt retained authority or changed the original total deadline.")
            report = {**record, "outcome": "pass", "activity_execution_id": execution_id,
                      "original_total_deadline": first["status"]["deadlines"]["schedule_to_close"],
                      "first_claim": first["claim"], "second_claim": second["claim"],
                      "stale_rejection": read(".stale-check.json")["rejection"],
                      "terminal_rejection": rejection, "terminal_status": status, "history": history}
            save(".result.json", report)
            emit(report)
            return
        if phase == "stale":
            rejection = read(".stale.json")
            require(rejection["event"] == "stale-rejected" and rejection["status"] == 409
                    and rejection["reason"] in {"attempt_closed", "stale_attempt", "stale_task", "task_not_leased"}
                    and rejection["task_id"] == first["claim"]["task_id"]
                    and rejection["activity_attempt_id"] == first["claim"]["activity_attempt_id"], "Stale completion lacked precise claim refusal.")
            _, history = await observe(client, record)
            verify_unchanged_history(history, second["history"], record, second)
            status = await ownership(client, second["claim"])
            if record["scenario"] == "progress-heartbeat":
                require({key: value for key, value in status["deadlines"].items() if key != "heartbeat"}
                        == {key: value for key, value in second["status"]["deadlines"].items() if key != "heartbeat"},
                        "Stale completion changed a fixed deadline.")
            else:
                require(status["deadlines"] == second["status"]["deadlines"], "Stale completion changed current deadlines.")
            save(".stale-check.json", {"rejection": rejection, "history": history, "status": status})
            emit({**record, "stale_rejection": rejection["reason"]})
            return
        require(phase == "verify", "Unknown recovery phase.")
        async with asyncio.timeout(30):
            while True:
                run, history = await observe(client, record)
                if run.status in {"completed", "failed", "cancelled", "terminated", "timed_out"}:
                    break
                await asyncio.sleep(0.2)
        execution_id = verify_attempts(history, record, first["claim"], second["claim"])
        require(run.run_id == record["run_id"] and run.status == "completed", "Original workflow did not complete.")
        require(history["events"][:len(second["history"]["events"])] == second["history"]["events"], "Completion changed the acknowledged history prefix.")
        terminals = [event for event in history["events"] if event["event_type"] in TERMINAL]
        require(len(terminals) == 1 and terminals[0]["event_type"] == "WorkflowCompleted", "Workflow completed more than once or with another outcome.")
        require(one(history, "WorkflowStarted")["payload"]["workflow_run_id"] == record["run_id"], "History belongs to another original run.")
        require(timestamp(terminals[0]["timestamp"]) <= timestamp(first["status"]["deadlines"]["schedule_to_close"]),
                "Completion exceeded the original total deadline.")
        completed = one(history, "ActivityCompleted")["payload"]
        require(completed["activity_execution_id"] == execution_id and completed["activity_attempt_id"] == second["claim"]["activity_attempt_id"],
                "Another activity attempt published the result.")
        result = serializer.decode_envelope(terminals[0]["payload"]["output"], codec="avro")
        require(result == {"workflow_runtime": record["workflow_runtime"], "activity": second["claim"]}, "Workflow received a changed or stale activity result.")
        require(serializer.decode_envelope(completed["result"], codec="avro") == second["claim"], "Durable activity result differs from the actual retry worker.")
        report = {**record, "outcome": "pass", "activity_execution_id": execution_id,
                  "original_total_deadline": first["status"]["deadlines"]["schedule_to_close"],
                  "first_claim": first["claim"], "second_claim": second["claim"], "result": result,
                  "stale_rejection": read(".stale-check.json")["rejection"], "history": history}
        if record["scenario"] == "worker-loss":
            before, killed, replacement = (read(f".container-{stage}.json") for stage in ("before", "killed", "replacement"))
            require(before["State"]["Running"] and before["Id"] == killed["Id"] != replacement["Id"]
                    and killed["State"]["ExitCode"] == 137 and not killed["State"]["Running"]
                    and not killed["State"]["OOMKilled"] and replacement["State"]["Running"], "Missing actual SIGKILL and fresh-container recovery.")
            report["physical_failure"] = {"before": before, "killed": killed, "replacement": replacement}
        if record["scenario"] == "progress-heartbeat":
            require(not path(".expired-resumed").exists(), "The expired callback resumed after its replacement was released.")
            before, after = read(".progress-container-before.json"), read(".progress-container-after.json")
            require(before["Id"] == after["Id"] and before["State"]["Pid"] == after["State"]["Pid"]
                    and before["State"]["StartedAt"] == after["State"]["StartedAt"]
                    and before["State"]["Running"] and after["State"]["Running"]
                    and not after["State"]["OOMKilled"], "Progress timeout was hidden by a worker container restart.")
            progress = read(".progress.json")
            beats = [event for event in events(history, "ActivityHeartbeatRecorded")
                     if event["payload"]["activity_attempt_id"] == first["claim"]["activity_attempt_id"]]
            require(beats == progress["heartbeats"], "Late heartbeat changed expired attempt progress.")
            report["application_progress"] = progress
            report["late_heartbeat"] = read(".late-heartbeat-check.json")
            report["worker_continuity"] = {"before": before, "after": after}
        save(".result.json", report)
        emit(report)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("phase", choices=("start", "first", "release-first", "progress", "second", "stale", "late-heartbeat",
                                         "release", "verify", "terminal", "terminal-stale", "summary"))
    asyncio.run(main(parser.parse_args().phase))
