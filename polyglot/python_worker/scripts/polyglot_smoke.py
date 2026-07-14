"""Run the public polyglot conformance harness and emit metadata."""
from __future__ import annotations

import asyncio
import importlib.metadata
import json
import os
import re
import subprocess
import sys
import time
import uuid
from datetime import datetime, timezone
from typing import Any
from urllib.parse import quote
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from durable_workflow import Client


def semantic_version_from_text(value: str | None) -> str | None:
    if not value:
        return None
    match = re.search(r"\b\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\b", value)
    return match.group(0) if match else None


def required_env(name: str) -> str:
    value = os.environ.get(name)
    if value is None or value == "":
        raise RuntimeError(
            f"{name} must be set by scripts/resolve-current-artifacts.sh before running polyglot smoke"
        )
    return value


def required_env_version(name: str) -> str:
    value = semantic_version_from_text(required_env(name))
    if value is None:
        raise RuntimeError(f"{name} must contain a semantic version")
    return value


SERVER_URL = os.environ["DURABLE_WORKFLOW_SERVER_URL"]
TOKEN = os.environ.get("DURABLE_WORKFLOW_AUTH_TOKEN", "test-token")
NAMESPACE = os.environ.get("DURABLE_WORKFLOW_NAMESPACE", "default")
DW = os.environ.get("DURABLE_WORKFLOW_CLI", "dw")
WATERLINE_URL = os.environ.get("DURABLE_WORKFLOW_WATERLINE_URL", "http://waterline:8081/waterline")
ARTIFACT_PROBE_URL = os.environ.get(
    "DURABLE_WORKFLOW_ARTIFACT_PROBE_URL",
    "http://waterline:8081/polyglot/conformance/artifacts",
)
SERVER_PIN = required_env("DURABLE_SERVER_IMAGE")

REQUIRED_ARTIFACT_VERSIONS = {
    "server": semantic_version_from_text(SERVER_PIN) or required_env_version("DURABLE_SERVER_VERSION"),
    "cli": required_env_version("DURABLE_WORKFLOW_CLI_VERSION"),
    "sdk-php": (
        semantic_version_from_text(os.environ.get("DURABLE_WORKFLOW_PHP_SDK_PIN"))
        or required_env_version("DURABLE_WORKFLOW_PHP_SDK_VERSION")
    ),
    "sdk-python": required_env_version("DURABLE_WORKFLOW_PYTHON_SDK_VERSION"),
    "sdk-rust": required_env_version("DURABLE_WORKFLOW_RUST_SDK_VERSION"),
    "workflow": (
        semantic_version_from_text(os.environ.get("DURABLE_WORKFLOW_WORKFLOW_PIN"))
        or required_env_version("DURABLE_WORKFLOW_WORKFLOW_VERSION")
    ),
    "waterline": (
        semantic_version_from_text(os.environ.get("DURABLE_WORKFLOW_WATERLINE_PIN"))
        or required_env_version("DURABLE_WORKFLOW_WATERLINE_VERSION")
    ),
}

PY_QUEUE = os.environ.get("POLYGLOT_PY_TASK_QUEUE", "polyglot-python")
PHP_QUEUE = os.environ.get("POLYGLOT_PHP_TASK_QUEUE", "polyglot-php")
PHP2PY_QUEUE = os.environ.get("POLYGLOT_PHP2PY_TASK_QUEUE", "polyglot-php-to-python")
PY2PHP_QUEUE = os.environ.get("POLYGLOT_PY2PHP_TASK_QUEUE", "polyglot-python-to-php")
SIGNAL_NAME = "polyglot-signal"
RUST_QUEUE = os.environ.get("POLYGLOT_RUST_TASK_QUEUE", "polyglot-rust")
TO_RUST_QUEUE = os.environ.get("POLYGLOT_TO_RUST_TASK_QUEUE", "polyglot-to-rust")
RUST_AVRO_VERSION = required_env_version("DURABLE_WORKFLOW_RUST_AVRO_VERSION")
PYTHON_AVRO_VERSION = required_env_version("DURABLE_WORKFLOW_PYTHON_AVRO_VERSION")

PHP_REQUIRED_RUNTIME_CELLS = {
    "workflow": (
        "php_same_language",
        "php_to_python",
        "php_to_rust",
    ),
    "activity": (
        "php_same_language",
        "python_to_php",
        "rust_to_php",
    ),
}


class DwCommandError(RuntimeError):
    def __init__(
        self,
        *,
        args: list[str],
        returncode: int | None,
        stdout: str | bytes | None,
        stderr: str | bytes | None,
    ) -> None:
        self.args_list = args
        self.returncode = returncode
        self.stdout = self._text(stdout)
        self.stderr = self._text(stderr)
        exit_label = "timeout" if returncode is None else str(returncode)
        super().__init__(
            "dw command failed "
            f"exit={exit_label} args={args!r}\nstdout={self.stdout}\nstderr={self.stderr}"
        )

    @staticmethod
    def _text(value: str | bytes | None) -> str:
        if value is None:
            return ""
        if isinstance(value, bytes):
            return value.decode("utf-8", errors="replace")
        return value

    def json_stdout(self) -> dict[str, Any] | None:
        try:
            return parse_json_object(self.stdout)
        except Exception:  # noqa: BLE001
            return None


class RuntimeMatrixError(RuntimeError):
    def __init__(
        self,
        *,
        spec: dict[str, Any],
        completed_runs: list[dict[str, Any]],
        cause: Exception,
    ) -> None:
        scenario = str(spec["scenario"])
        super().__init__(f"{scenario}: {cause}")
        self.completed_runs = list(completed_runs)
        self.failed_cell = {
            "scenario": scenario,
            "workflow_runtime": spec["workflow_language"],
            "activity_runtime": spec["activity_language"],
            "status": "failed",
            "error": str(cause),
        }


def detect_dw_version() -> str | None:
    fallback = os.environ.get("DURABLE_WORKFLOW_CLI_VERSION")
    try:
        proc = subprocess.run(
            [DW, "--version"],
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
    except Exception:  # noqa: BLE001
        return fallback

    return semantic_version_from_text(f"{proc.stdout}\n{proc.stderr}") or fallback


def installed_python_sdk_version() -> str | None:
    try:
        return importlib.metadata.version("durable-workflow")
    except importlib.metadata.PackageNotFoundError:
        return None


def installed_distribution_version(name: str) -> str | None:
    try:
        return importlib.metadata.version(name)
    except importlib.metadata.PackageNotFoundError:
        return None


def php_artifact_probe_url() -> str:
    return ARTIFACT_PROBE_URL


def fetch_php_artifact_probe() -> tuple[dict[str, Any] | None, str | None]:
    try:
        return fetch_json_url(php_artifact_probe_url(), label="PHP artifact probe"), None
    except Exception as exc:  # noqa: BLE001
        return None, str(exc)


def php_artifact_versions(probe: dict[str, Any] | None) -> dict[str, str | None]:
    versions: dict[str, str | None] = {
        "sdk-php": None,
        "workflow": None,
        "waterline": None,
    }
    artifacts = probe.get("artifacts") if isinstance(probe, dict) else None
    if not isinstance(artifacts, dict):
        return versions

    for key in versions:
        artifact = artifacts.get(key)
        if not isinstance(artifact, dict):
            continue
        raw_version = artifact.get("version")
        versions[key] = semantic_version_from_text(raw_version if isinstance(raw_version, str) else None)

    return versions


def official_avro_packages(probe: dict[str, Any] | None) -> dict[str, dict[str, Any]]:
    artifacts = probe.get("artifacts") if isinstance(probe, dict) else None
    php_avro = artifacts.get("apache-avro-php") if isinstance(artifacts, dict) else None
    php_version = php_avro.get("version") if isinstance(php_avro, dict) else None
    return {
        "php": {
            "package": "apache/avro",
            "channel": "Packagist",
            "version": semantic_version_from_text(php_version if isinstance(php_version, str) else None),
            "official": True,
        },
        "python": {
            "package": "avro",
            "channel": "PyPI",
            "version": installed_distribution_version("avro"),
            "required_version": PYTHON_AVRO_VERSION,
            "official": True,
        },
        "rust": {
            "package": "apache-avro",
            "channel": "crates.io",
            "version": RUST_AVRO_VERSION,
            "required_version": RUST_AVRO_VERSION,
            "official": True,
        },
    }


def php_waterline_assets(probe: dict[str, Any] | None) -> dict[str, Any] | None:
    assets = probe.get("assets") if isinstance(probe, dict) else None
    if not isinstance(assets, dict):
        return None

    waterline = assets.get("waterline")
    return waterline if isinstance(waterline, dict) else None


def resolved_artifact_versions(
    php_probe: dict[str, Any] | None = None,
    php_sdk_worker_version: str | None = None,
) -> dict[str, str | None]:
    php_versions = php_artifact_versions(php_probe)

    return {
        "server": semantic_version_from_text(SERVER_PIN),
        "cli": detect_dw_version(),
        "sdk-php": php_sdk_worker_version,
        "sdk-python": installed_python_sdk_version(),
        "sdk-rust": REQUIRED_ARTIFACT_VERSIONS["sdk-rust"],
        "workflow": php_versions.get("workflow"),
        "waterline": php_versions.get("waterline"),
    }


def artifact_version_findings(
    versions: dict[str, str | None],
) -> tuple[dict[str, dict[str, str | None]], dict[str, str]]:
    stale: dict[str, dict[str, str | None]] = {}
    missing: dict[str, str] = {}
    for artifact, expected in REQUIRED_ARTIFACT_VERSIONS.items():
        actual = versions.get(artifact)
        if actual is None:
            missing[artifact] = expected
        elif actual != expected:
            stale[artifact] = {
                "expected": expected,
                "actual": actual,
            }
    return stale, missing


def waterline_asset_findings(
    php_probe: dict[str, Any] | None,
) -> dict[str, dict[str, Any]]:
    waterline = php_waterline_assets(php_probe)
    if waterline is None:
        return {
            "waterline": {
                "reason": "The PHP artifact probe did not report Waterline asset manifest status.",
            },
        }

    if waterline.get("current") is True:
        return {}

    published = waterline.get("published_manifest")
    package = waterline.get("package_manifest")
    published = published if isinstance(published, dict) else {}
    package = package if isinstance(package, dict) else {}

    return {
        "waterline": {
            "reason": "Published Waterline assets do not match the installed Waterline package manifest.",
            "published_manifest_present": published.get("present"),
            "published_manifest_sha256": published.get("sha256"),
            "package_manifest_present": package.get("present"),
            "package_manifest_sha256": package.get("sha256"),
        },
    }


def runtime_matrix_cells(runs: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return [
        {
            "scenario": run["scenario"],
            "workflow_runtime": run["workflow_language"],
            "activity_runtime": run["activity_language"],
            "workflow_id": run["workflow_id"],
            "run_id": run["run_id"],
            "status": run["status"],
        }
        for run in runs
    ]


def php_sdk_execution_evidence(runtime_matrix: dict[str, Any] | None) -> dict[str, Any]:
    required_cells = [
        {"scenario": scenario, "php_role": role}
        for role, scenarios in PHP_REQUIRED_RUNTIME_CELLS.items()
        for scenario in scenarios
    ]
    raw_cells = runtime_matrix.get("cells") if isinstance(runtime_matrix, dict) else None
    cells = raw_cells if isinstance(raw_cells, list) else []
    completed_cells: list[dict[str, Any]] = []
    missing_cells: list[dict[str, str]] = []

    for required in required_cells:
        runtime_key = f"{required['php_role']}_runtime"
        matching_cell = next(
            (
                cell
                for cell in cells
                if isinstance(cell, dict)
                and cell.get("scenario") == required["scenario"]
                and cell.get(runtime_key) == "php"
                and cell.get("status") == "passed"
            ),
            None,
        )
        if matching_cell is None:
            missing_cells.append(required)
            continue
        completed_cells.append({
            **required,
            "workflow_id": matching_cell.get("workflow_id"),
            "run_id": matching_cell.get("run_id"),
            "status": matching_cell.get("status"),
        })

    matrix_status = runtime_matrix.get("status") if isinstance(runtime_matrix, dict) else "not_run"
    exercised = matrix_status == "passed" and not missing_cells
    if exercised:
        status = "completed"
        reason = None
    elif matrix_status == "failed":
        status = "not_completed"
        reason = "runtime_matrix_failed"
    elif matrix_status == "passed":
        status = "not_completed"
        reason = "required_php_cells_missing"
    else:
        status = "not_run"
        reason = "runtime_matrix_not_run"

    return {
        "source": "runtime_matrix.completed_cells",
        "status": status,
        "reason": reason,
        "matrix_status": matrix_status,
        "failed_cell": runtime_matrix.get("failed_cell") if isinstance(runtime_matrix, dict) else None,
        "required_cells": required_cells,
        "completed_cells": completed_cells,
        "missing_cells": missing_cells,
        "required_cell_count": len(required_cells),
        "completed_cell_count": len(completed_cells),
        "all_required_cells_completed": not missing_cells,
        "matrix_passed": matrix_status == "passed",
    }


def artifact_metadata(
    versions: dict[str, str | None] | None = None,
    php_probe: dict[str, Any] | None = None,
    php_probe_error: str | None = None,
    rust_exercised: bool = False,
    php_worker_error: str | None = None,
    runtime_matrix: dict[str, Any] | None = None,
) -> dict[str, Any]:
    versions = versions or resolved_artifact_versions()
    php_execution = php_sdk_execution_evidence(runtime_matrix)
    php_registration_observed = versions.get("sdk-php") is not None and php_worker_error is None
    probe = {
        "url": php_artifact_probe_url(),
        "payload": php_probe,
        "error": php_probe_error,
    }
    return {
        "server": {
            "artifact": "durableworkflow/server",
            "pin": SERVER_PIN,
            "version": versions.get("server"),
            "exercised": True,
        },
        "cli": {
            "artifact": "dw",
            "pin": f"dw=={REQUIRED_ARTIFACT_VERSIONS['cli']}",
            "install": os.environ.get(
                "DURABLE_WORKFLOW_CLI_PIN",
                f"dw=={REQUIRED_ARTIFACT_VERSIONS['cli']}",
            ),
            "install_channel": "https://durable-workflow.com/install.sh",
            "version": versions.get("cli"),
            "exercised": True,
        },
        "sdk_python": {
            "artifact": "durable-workflow",
            "pin": f"durable-workflow=={versions.get('sdk-python') or 'unknown'}",
            "version": versions.get("sdk-python"),
            "exercised": True,
        },
        "sdk_rust": {
            "artifact": "durable-workflow",
            "pin": f"cargo add durable-workflow@{versions.get('sdk-rust') or 'unknown'} --exact",
            "version": versions.get("sdk-rust"),
            "version_source": "rust_worker_registration" if rust_exercised else "artifact_tuple_metadata",
            "install_channel": "crates.io",
            "exercised": rust_exercised,
            "execution_evidence": "runtime_matrix" if rust_exercised else None,
        },
        "sdk_php": {
            "artifact": "durable-workflow/sdk",
            "pin": (
                os.environ.get("DURABLE_WORKFLOW_PHP_SDK_PIN")
                or f"durable-workflow/sdk:{REQUIRED_ARTIFACT_VERSIONS['sdk-php']}"
            ),
            "version": versions.get("sdk-php"),
            "version_source": "standalone_php_worker_registration",
            "role": "framework-neutral standalone client and remote worker SDK",
            "registration_evidence": {
                "status": "registered" if php_registration_observed else "not_registered",
                "observed": php_registration_observed,
                "version_matched": php_registration_observed,
                "worker_id": "php-workflow-worker",
                "task_queue": PHP2PY_QUEUE,
                "workflow_type": "polyglot.php-to-python.PhpToPythonWorkflow",
                "error": php_worker_error,
            },
            "exercised": php_registration_observed and php_execution["status"] == "completed",
            "execution_evidence": php_execution,
        },
        "workflow": {
            "artifact": "durable-workflow/workflow",
            "pin": (
                os.environ.get("DURABLE_WORKFLOW_WORKFLOW_PIN")
                or f"durable-workflow/workflow:{REQUIRED_ARTIFACT_VERSIONS['workflow']}"
            ),
            "version": versions.get("workflow"),
            "version_source": "waterline_conformance_artifact_probe",
            "probe": probe,
            "role": "embedded Laravel engine and Waterline host",
            "exercised": False,
        },
        "waterline": {
            "artifact": "durable-workflow/waterline",
            "pin": (
                os.environ.get("DURABLE_WORKFLOW_WATERLINE_PIN")
                or f"durable-workflow/waterline:{REQUIRED_ARTIFACT_VERSIONS['waterline']}"
            ),
            "url": WATERLINE_URL,
            "version": versions.get("waterline"),
            "version_source": "waterline_conformance_artifact_probe",
            "probe": probe,
            "assets": php_waterline_assets(php_probe),
            "exercised": True,
        },
    }


async def wait_for_worker(
    *,
    task_queue: str,
    runtime: str,
    workflow_type: str | None = None,
    activity_type: str | None = None,
    worker_id: str | None = None,
    timeout_seconds: float = 90.0,
) -> str | None:
    deadline = time.monotonic() + timeout_seconds
    last_error: Exception | None = None

    async with Client(SERVER_URL, token=TOKEN, namespace=NAMESPACE) as client:
        while time.monotonic() < deadline:
            try:
                roster = await client.list_workers(task_queue=task_queue)
            except Exception as exc:  # noqa: BLE001
                last_error = exc
                await asyncio.sleep(1)
                continue

            for worker in getattr(roster, "workers", []) or []:
                observed_version: str | None = None
                if worker_id is not None and getattr(worker, "worker_id", None) != worker_id:
                    continue
                if getattr(worker, "runtime", None) != runtime:
                    continue
                if runtime == "php":
                    sdk_version = getattr(worker, "sdk_version", None)
                    actual = semantic_version_from_text(sdk_version if isinstance(sdk_version, str) else None)
                    expected = REQUIRED_ARTIFACT_VERSIONS["sdk-php"]
                    if actual != expected:
                        last_error = RuntimeError(
                            "PHP worker advertised standalone SDK "
                            f"{sdk_version!r}; expected durable-workflow/sdk:{expected}"
                        )
                        continue
                    observed_version = actual
                if runtime == "rust":
                    sdk_version = getattr(worker, "sdk_version", None)
                    actual = semantic_version_from_text(sdk_version if isinstance(sdk_version, str) else None)
                    expected = REQUIRED_ARTIFACT_VERSIONS["sdk-rust"]
                    if actual != expected:
                        last_error = RuntimeError(
                            "Rust worker advertised SDK "
                            f"{sdk_version!r}; expected durable-workflow crates.io release {expected}"
                        )
                        continue
                    observed_version = actual
                workflows = getattr(worker, "supported_workflow_types", None) or []
                activities = getattr(worker, "supported_activity_types", None) or []
                if workflow_type is not None and workflow_type not in workflows:
                    continue
                if activity_type is not None and activity_type not in activities:
                    continue
                return observed_version

            await asyncio.sleep(1)

    detail = f": {last_error}" if last_error is not None else ""
    identity = f" worker_id={worker_id!r}" if worker_id is not None else ""
    raise RuntimeError(
        f"no {runtime}{identity} worker registered on task queue {task_queue!r} "
        f"within {timeout_seconds:.0f}s{detail}"
    )


def run_dw(args: list[str], *, timeout_seconds: int = 300) -> dict[str, Any]:
    env = os.environ.copy()
    env["DURABLE_WORKFLOW_SERVER_URL"] = SERVER_URL
    env["DURABLE_WORKFLOW_NAMESPACE"] = NAMESPACE
    env["DURABLE_WORKFLOW_AUTH_TOKEN"] = TOKEN
    cmd = [DW, *args]
    try:
        proc = subprocess.run(
            cmd,
            check=False,
            text=True,
            capture_output=True,
            timeout=timeout_seconds,
            env=env,
        )
    except subprocess.TimeoutExpired as exc:
        raise DwCommandError(
            args=args,
            returncode=None,
            stdout=exc.stdout,
            stderr=exc.stderr,
        ) from exc
    if proc.returncode != 0:
        raise DwCommandError(args=args, returncode=proc.returncode, stdout=proc.stdout, stderr=proc.stderr)
    return parse_json_object(proc.stdout)


def parse_json_object(output: str) -> dict[str, Any]:
    text = output.strip()
    if not text:
        raise RuntimeError("dw command returned empty stdout")
    try:
        parsed = json.loads(text)
    except json.JSONDecodeError:
        start = text.rfind("\n{")
        candidate = text[start + 1 :] if start >= 0 else text[text.find("{") :]
        parsed = json.loads(candidate)
    if not isinstance(parsed, dict):
        raise RuntimeError(f"expected JSON object from dw, got {type(parsed).__name__}")
    return parsed


def workflow_id(prefix: str) -> str:
    return f"{prefix}-{uuid.uuid4().hex[:10]}"


def json_arg(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def cli_start(
    *,
    workflow_type: str,
    task_queue: str,
    workflow_id_value: str,
    input_args: list[Any],
    wait: bool,
) -> dict[str, Any]:
    args = [
        "workflow:start",
        f"--type={workflow_type}",
        f"--workflow-id={workflow_id_value}",
        f"--task-queue={task_queue}",
        f"--input={json_arg(input_args)}",
        "--json",
    ]
    if wait:
        args.append("--wait")
    return run_dw(args)


def cli_query(
    workflow_id_value: str,
    query_name: str,
    input_args: list[Any] | None = None,
    *,
    timeout_seconds: int = 120,
) -> dict[str, Any]:
    args = ["workflow:query", workflow_id_value, query_name, "--json"]
    if input_args is not None:
        args.append(f"--input={json_arg(input_args)}")
    return run_dw(args, timeout_seconds=timeout_seconds)


def cli_signal(workflow_id_value: str, signal_name: str, input_args: list[Any]) -> dict[str, Any]:
    return run_dw([
        "workflow:signal",
        workflow_id_value,
        signal_name,
        f"--input={json_arg(input_args)}",
        "--json",
    ])


def retry_signal_until_accepted(
    workflow_id_value: str,
    signal_name: str,
    input_args: list[Any],
    *,
    timeout_seconds: float = 90.0,
) -> dict[str, Any]:
    deadline = time.monotonic() + timeout_seconds
    last: dict[str, Any] | str | None = None
    while time.monotonic() < deadline:
        try:
            return cli_signal(workflow_id_value, signal_name, input_args)
        except DwCommandError as exc:
            parsed = exc.json_stdout()
            last = parsed or str(exc)
            reason = parsed.get("reason") if isinstance(parsed, dict) else None
            if reason == "run_not_active" or (isinstance(parsed, dict) and parsed.get("is_terminal") is True):
                raise
            if reason in {"unknown_signal", "invalid_signal_arguments", "instance_not_found"}:
                raise
        time.sleep(1)
    raise RuntimeError(f"signal {signal_name!r} on {workflow_id_value} was not accepted in time: {last}")


def cli_describe(workflow_id_value: str) -> dict[str, Any]:
    return run_dw(["workflow:describe", workflow_id_value, "--json"], timeout_seconds=120)


def wait_for_completed(workflow_id_value: str, *, timeout_seconds: float = 240.0) -> dict[str, Any]:
    deadline = time.monotonic() + timeout_seconds
    last: dict[str, Any] | None = None

    while time.monotonic() < deadline:
        last = cli_describe(workflow_id_value)
        if last.get("status_bucket") == "completed" or last.get("status") == "completed":
            return last
        if last.get("is_terminal") is True:
            raise RuntimeError(f"workflow {workflow_id_value} reached non-completed terminal state: {last}")
        time.sleep(1)

    raise RuntimeError(f"workflow {workflow_id_value} did not complete in time; last describe={last}")


def wait_for_signal_wait_open(
    workflow_id_value: str,
    signal_name: str,
    *,
    timeout_seconds: float = 90.0,
) -> dict[str, Any]:
    deadline = time.monotonic() + timeout_seconds
    last: dict[str, Any] | None = None

    while time.monotonic() < deadline:
        last = cli_describe(workflow_id_value)
        if last.get("is_terminal") is True:
            raise RuntimeError(f"workflow {workflow_id_value} reached terminal state before signal: {last}")

        wait_kind = last.get("wait_kind")
        wait_reason = last.get("wait_reason")
        if wait_kind == "condition":
            return last
        if wait_kind == "signal" and wait_reason == f"Waiting for signal {signal_name}":
            return last

        time.sleep(1)

    raise RuntimeError(
        f"workflow {workflow_id_value} did not open a durable wait for {signal_name!r}; "
        f"last describe={last}"
    )


def completed_output(describe: dict[str, Any]) -> Any:
    if describe.get("status_bucket") != "completed" and describe.get("status") != "completed":
        raise RuntimeError(f"workflow did not complete: {describe}")
    return describe.get("output")


def assert_dict(value: Any, label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise RuntimeError(f"{label}: expected object, got {type(value).__name__}: {value!r}")
    return value


async def four_corner_cli_scenarios() -> list[dict[str, Any]]:
    specs = [
        {
            "scenario": "python_same_language",
            "workflow_language": "python",
            "activity_language": "python",
            "workflow_type": "polyglot.python.greeter",
            "task_queue": PY_QUEUE,
            "input": [{"name": "Ada", "locale": "en"}],
            "workers": [
                {"task_queue": PY_QUEUE, "runtime": "python", "workflow_type": "polyglot.python.greeter"},
            ],
        },
        {
            "scenario": "php_same_language",
            "workflow_language": "php",
            "activity_language": "php",
            "workflow_type": "polyglot.php.greeter",
            "task_queue": PHP_QUEUE,
            "input": [{"name": "Rasmus", "locale": "en"}],
            "workers": [
                {"task_queue": PHP_QUEUE, "runtime": "php", "workflow_type": "polyglot.php.greeter"},
                {"task_queue": PHP_QUEUE, "runtime": "php", "activity_type": "polyglot.php.marker"},
            ],
        },
        {
            "scenario": "php_to_python",
            "workflow_language": "php",
            "activity_language": "python",
            "workflow_type": "polyglot.php-to-python.PhpToPythonWorkflow",
            "task_queue": PHP2PY_QUEUE,
            "input": ["polyglot"],
            "workers": [
                {"task_queue": PHP2PY_QUEUE, "runtime": "php", "workflow_type": "polyglot.php-to-python.PhpToPythonWorkflow"},
                {"task_queue": PHP2PY_QUEUE, "runtime": "python", "activity_type": "polyglot.php-to-python.reverse"},
            ],
        },
        {
            "scenario": "python_to_php",
            "workflow_language": "python",
            "activity_language": "php",
            "workflow_type": "polyglot.python-to-php.greeter",
            "task_queue": PY_QUEUE,
            "input": [{"name": "Grace", "locale": "en"}],
            "workers": [
                {"task_queue": PY_QUEUE, "runtime": "python", "workflow_type": "polyglot.python-to-php.greeter"},
                {"task_queue": PY2PHP_QUEUE, "runtime": "php", "activity_type": "polyglot.python-to-php.marker"},
            ],
        },
        {
            "scenario": "rust_same_language",
            "workflow_language": "rust",
            "activity_language": "rust",
            "workflow_type": "polyglot.rust.greeter",
            "task_queue": RUST_QUEUE,
            "input": [{"name": "Ferris", "locale": "en"}],
            "workers": [
                {"task_queue": RUST_QUEUE, "runtime": "rust", "workflow_type": "polyglot.rust.greeter"},
                {"task_queue": RUST_QUEUE, "runtime": "rust", "activity_type": "polyglot.rust.echo"},
            ],
        },
        {
            "scenario": "rust_to_python",
            "workflow_language": "rust",
            "activity_language": "python",
            "workflow_type": "polyglot.rust-to-python.greeter",
            "task_queue": RUST_QUEUE,
            "input": [{"name": "Guido", "locale": "en"}],
            "workers": [
                {"task_queue": RUST_QUEUE, "runtime": "rust", "workflow_type": "polyglot.rust-to-python.greeter"},
                {"task_queue": PHP2PY_QUEUE, "runtime": "python", "activity_type": "polyglot.rust-to-python.echo"},
            ],
        },
        {
            "scenario": "rust_to_php",
            "workflow_language": "rust",
            "activity_language": "php",
            "workflow_type": "polyglot.rust-to-php.greeter",
            "task_queue": RUST_QUEUE,
            "input": [{"name": "Rasmus", "locale": "en", "source": "rust"}],
            "workers": [
                {"task_queue": RUST_QUEUE, "runtime": "rust", "workflow_type": "polyglot.rust-to-php.greeter"},
                {"task_queue": PY2PHP_QUEUE, "runtime": "php", "activity_type": "polyglot.rust-to-php.echo"},
            ],
        },
        {
            "scenario": "python_to_rust",
            "workflow_language": "python",
            "activity_language": "rust",
            "workflow_type": "polyglot.python-to-rust.greeter",
            "task_queue": PY_QUEUE,
            "input": [{"name": "Graydon", "locale": "en"}],
            "workers": [
                {"task_queue": PY_QUEUE, "runtime": "python", "workflow_type": "polyglot.python-to-rust.greeter"},
                {"task_queue": TO_RUST_QUEUE, "runtime": "rust", "activity_type": "polyglot.python-to-rust.echo"},
            ],
        },
        {
            "scenario": "php_to_rust",
            "workflow_language": "php",
            "activity_language": "rust",
            "workflow_type": "polyglot.php-to-rust.greeter",
            "task_queue": TO_RUST_QUEUE,
            "input": [{"name": "Ferris", "locale": "en", "source": "php"}],
            "workers": [
                {"task_queue": TO_RUST_QUEUE, "runtime": "php", "workflow_type": "polyglot.php-to-rust.greeter", "worker_id": "php-to-rust-workflow-worker"},
                {"task_queue": TO_RUST_QUEUE, "runtime": "rust", "activity_type": "polyglot.php-to-rust.echo"},
            ],
        },
    ]

    results: list[dict[str, Any]] = []
    for spec in specs:
        try:
            for worker in spec["workers"]:
                await wait_for_worker(**worker)
            wid = workflow_id(f"polyglot-cli-{spec['scenario']}")
            describe = cli_start(
                workflow_type=spec["workflow_type"],
                task_queue=spec["task_queue"],
                workflow_id_value=wid,
                input_args=spec["input"],
                wait=True,
            )
            result = assert_dict(completed_output(describe), spec["scenario"])
            assert_runtime(result, spec["workflow_language"], spec["activity_language"], spec["scenario"])
            results.append({
                "surface": "cli_start_result",
                "scenario": spec["scenario"],
                "workflow_id": wid,
                "run_id": describe.get("run_id"),
                "workflow_type": spec["workflow_type"],
                "task_queue": spec["task_queue"],
                "workflow_language": spec["workflow_language"],
                "activity_language": spec["activity_language"],
                "workflow_start_result_driver": "dw CLI",
                "input": spec["input"],
                "status": "passed",
                "result": result,
            })
            print(json.dumps(results[-1], indent=2, sort_keys=True), flush=True)
        except Exception as exc:  # noqa: BLE001
            raise RuntimeMatrixError(spec=spec, completed_runs=results, cause=exc) from exc
    return results


def assert_runtime(result: dict[str, Any], workflow_language: str, activity_language: str, scenario: str) -> None:
    if result.get("workflow_runtime") != workflow_language:
        raise RuntimeError(f"{scenario}: workflow runtime mismatch: {result}")
    if workflow_language != activity_language and result.get("activity_runtime") != activity_language:
        raise RuntimeError(f"{scenario}: activity runtime mismatch: {result}")
    if workflow_language == activity_language == "php" and result.get("activity_runtime") != "php":
        raise RuntimeError(f"{scenario}: PHP same-language activity runtime mismatch: {result}")
    if workflow_language == activity_language == "rust" and result.get("activity_runtime") != "rust":
        raise RuntimeError(f"{scenario}: Rust same-language activity runtime mismatch: {result}")


def type_matrix_payload() -> dict[str, Any]:
    return {
        "string_non_ascii": "hello, こんにちは, café",
        "integer": 42,
        "float": 3.14159,
        "boolean": True,
        "null_value": None,
        "mixed_list": ["text", 7, 2.5, False, None, {"nested": "map"}],
        "nested_map": {"outer": {"inner": [1, "two", None], "flag": True}},
        "timestamp": "2026-05-17T00:00:00Z",
        "binary_base64": {
            "encoding": "base64",
            "value": "cG9seWdsb3QtYmluYXJ5AAE=",
        },
    }


async def run_type_matrix() -> dict[str, Any]:
    payload = type_matrix_payload()
    directions = [
        {
            "direction": "php_to_python",
            "workflow_type": "polyglot.php-to-python.type-roundtrip",
            "task_queue": PHP2PY_QUEUE,
            "workflow_runtime": "php",
            "activity_runtime": "python",
            "workers": [
                {"task_queue": PHP2PY_QUEUE, "runtime": "php", "workflow_type": "polyglot.php-to-python.type-roundtrip"},
                {"task_queue": PHP2PY_QUEUE, "runtime": "python", "activity_type": "polyglot.php-to-python.echo"},
            ],
        },
        {
            "direction": "python_to_php",
            "workflow_type": "polyglot.python-to-php.type-roundtrip",
            "task_queue": PY_QUEUE,
            "workflow_runtime": "python",
            "activity_runtime": "php",
            "workers": [
                {"task_queue": PY_QUEUE, "runtime": "python", "workflow_type": "polyglot.python-to-php.type-roundtrip"},
                {"task_queue": PY2PHP_QUEUE, "runtime": "php", "activity_type": "polyglot.python-to-php.echo"},
            ],
        },
        {
            "direction": "rust_to_python",
            "workflow_type": "polyglot.rust-to-python.type-roundtrip",
            "task_queue": RUST_QUEUE,
            "workflow_runtime": "rust",
            "activity_runtime": "python",
            "workers": [
                {"task_queue": RUST_QUEUE, "runtime": "rust", "workflow_type": "polyglot.rust-to-python.type-roundtrip"},
                {"task_queue": PHP2PY_QUEUE, "runtime": "python", "activity_type": "polyglot.rust-to-python.echo"},
            ],
        },
        {
            "direction": "python_to_rust",
            "workflow_type": "polyglot.python-to-rust.type-roundtrip",
            "task_queue": PY_QUEUE,
            "workflow_runtime": "python",
            "activity_runtime": "rust",
            "workers": [
                {"task_queue": PY_QUEUE, "runtime": "python", "workflow_type": "polyglot.python-to-rust.type-roundtrip"},
                {"task_queue": TO_RUST_QUEUE, "runtime": "rust", "activity_type": "polyglot.python-to-rust.echo"},
            ],
        },
        {
            "direction": "rust_to_php",
            "workflow_type": "polyglot.rust-to-php.type-roundtrip",
            "task_queue": RUST_QUEUE,
            "workflow_runtime": "rust",
            "activity_runtime": "php",
            "workers": [
                {"task_queue": RUST_QUEUE, "runtime": "rust", "workflow_type": "polyglot.rust-to-php.type-roundtrip"},
                {"task_queue": PY2PHP_QUEUE, "runtime": "php", "activity_type": "polyglot.rust-to-php.echo"},
            ],
        },
        {
            "direction": "php_to_rust",
            "workflow_type": "polyglot.php-to-rust.type-roundtrip",
            "task_queue": TO_RUST_QUEUE,
            "workflow_runtime": "php",
            "activity_runtime": "rust",
            "workers": [
                {"task_queue": TO_RUST_QUEUE, "runtime": "php", "workflow_type": "polyglot.php-to-rust.type-roundtrip", "worker_id": "php-to-rust-workflow-worker"},
                {"task_queue": TO_RUST_QUEUE, "runtime": "rust", "activity_type": "polyglot.php-to-rust.echo"},
            ],
        },
    ]
    runs = []
    for spec in directions:
        for worker in spec["workers"]:
            await wait_for_worker(**worker)
        direction = spec["direction"]
        wid = workflow_id(f"polyglot-types-{direction}")
        describe = cli_start(
            workflow_type=spec["workflow_type"],
            task_queue=spec["task_queue"],
            workflow_id_value=wid,
            input_args=[payload],
            wait=True,
        )
        result = assert_dict(completed_output(describe), f"type_matrix.{direction}")
        echo = assert_dict(result.get("echo"), f"type_matrix.{direction}.echo")
        if echo.get("value") != payload:
            raise RuntimeError(f"type_matrix.{direction}: value changed: {echo}")
        assert_runtime(
            result,
            spec["workflow_runtime"],
            spec["activity_runtime"],
            f"type_matrix.{direction}",
        )
        codec = assert_dict(echo.get("codec"), f"type_matrix.{direction}.codec")
        expected_package = official_avro_package_name(spec["activity_runtime"])
        if codec.get("codec") != "avro" or codec.get("package") != expected_package:
            raise RuntimeError(f"type_matrix.{direction}: official Avro observation missing: {codec}")
        runs.append({
            "direction": direction,
            "workflow_id": wid,
            "run_id": describe.get("run_id"),
            "workflow_type": spec["workflow_type"],
            "workflow_runtime": spec["workflow_runtime"],
            "activity_runtime": spec["activity_runtime"],
            "codec_observation": codec,
            "typed_observations": [
                {
                    "case": name,
                    "input_type": json_type_name(value),
                    "output_type": json_type_name(echo["value"][name]),
                    "equal": echo["value"][name] == value,
                }
                for name, value in payload.items()
            ],
            "status": "passed",
        })

    return {
        "surface": "type_matrix",
        "status": "passed",
        "codec_scope": "JSON-native values carried in the published Avro generic wrapper by official Apache Avro packages",
        "direction_count": len(runs),
        "cases": sorted(payload.keys()),
        "binary": {
            "status": "covered_as_base64_string",
            "reason": "The published generic-wrapper codec carries JSON-native values; raw bytes are represented as an explicit base64 object.",
        },
        "runs": runs,
    }


def official_avro_package_name(runtime: str) -> str:
    return {
        "php": "apache/avro",
        "python": "avro",
        "rust": "apache-avro",
    }[runtime]


def json_type_name(value: Any) -> str:
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "boolean"
    if isinstance(value, int):
        return "integer"
    if isinstance(value, float):
        return "number"
    if isinstance(value, str):
        return "string"
    if isinstance(value, list):
        return "array"
    if isinstance(value, dict):
        return "object"
    return type(value).__name__


async def run_typed_errors() -> dict[str, Any]:
    await wait_for_worker(task_queue=PHP2PY_QUEUE, runtime="php", workflow_type="polyglot.php-to-python.typed-error")
    await wait_for_worker(task_queue=PHP2PY_QUEUE, runtime="python", activity_type="polyglot.php-to-python.typed-error")
    await wait_for_worker(task_queue=PY_QUEUE, runtime="python", workflow_type="polyglot.python-to-php.typed-error")
    await wait_for_worker(task_queue=PY2PHP_QUEUE, runtime="php", activity_type="polyglot.python-to-php.typed-error")

    cases = [
        ("python_activity_to_php_workflow", "polyglot.php-to-python.typed-error", PHP2PY_QUEUE, "PolyglotPythonTypedError", "python"),
        ("php_activity_to_python_workflow", "polyglot.python-to-php.typed-error", PY_QUEUE, "PolyglotPhpTypedError", "php"),
    ]
    runs = []
    for name, workflow_type, task_queue, expected_type, expected_origin in cases:
        wid = workflow_id(f"polyglot-errors-{expected_origin}")
        describe = cli_start(
            workflow_type=workflow_type,
            task_queue=task_queue,
            workflow_id_value=wid,
            input_args=[{"case": name}],
            wait=True,
        )
        result = assert_dict(completed_output(describe), name)
        failure = assert_dict(result.get("failure"), f"{name}.failure")
        if failure.get("exception_type") != expected_type:
            raise RuntimeError(f"{name}: exception type mismatch: {failure}")
        if failure.get("non_retryable") is not True:
            raise RuntimeError(f"{name}: non_retryable was not preserved: {failure}")
        if expected_origin == "python":
            details = assert_dict(failure.get("details"), f"{name}.details")
            if details.get("origin") != "python" or details.get("code") != "PYTHON_TYPED_ERROR":
                raise RuntimeError(f"{name}: Python failure details changed: {details}")
        else:
            details = assert_dict(failure.get("details"), f"{name}.details")
            if details.get("origin") != "php" or details.get("code") != "PHP_TYPED_ERROR":
                raise RuntimeError(f"{name}: PHP failure details changed: {details}")
        runs.append({
            "case": name,
            "workflow_id": wid,
            "run_id": describe.get("run_id"),
            "workflow_type": workflow_type,
            "status": "passed",
            "exception_type": expected_type,
        })

    return {
        "surface": "typed_errors",
        "status": "passed",
        "runs": runs,
    }


async def run_signal_query() -> dict[str, Any]:
    await wait_for_worker(task_queue=PY_QUEUE, runtime="python", workflow_type="polyglot.python.signal-query")
    await wait_for_worker(
        task_queue=PHP2PY_QUEUE,
        runtime="php",
        workflow_type="polyglot.php.signal-query",
        worker_id="php-workflow-worker",
    )
    await wait_for_worker(
        task_queue=PHP2PY_QUEUE,
        runtime="php",
        workflow_type="polyglot.php.signal-query",
        worker_id="php-query-worker",
    )
    await wait_for_worker(
        task_queue=RUST_QUEUE,
        runtime="rust",
        workflow_type="polyglot.rust.signal-query",
        worker_id="rust-workflow-worker",
    )

    cases = [
        ("python_signal_query", "polyglot.python.signal-query", PY_QUEUE, "python"),
        ("php_signal_query", "polyglot.php.signal-query", PHP2PY_QUEUE, "php"),
        ("rust_signal_query", "polyglot.rust.signal-query", RUST_QUEUE, "rust"),
    ]
    runs = []
    for name, workflow_type, task_queue, runtime in cases:
        wid = workflow_id(f"polyglot-signal-{runtime}")
        start = cli_start(
            workflow_type=workflow_type,
            task_queue=task_queue,
            workflow_id_value=wid,
            input_args=[{"workflow_runtime": runtime}],
            wait=False,
        )
        before = retry_query_until(
            wid,
            "state",
            lambda value: isinstance(value, dict) and value.get("stage") == "waiting",
        )
        durable_wait = wait_for_signal_wait_open(wid, SIGNAL_NAME)
        before_repeat = retry_query_until(
            wid,
            "state",
            lambda value: (
                isinstance(value, dict)
                and value.get("stage") == "waiting"
                and value.get("signal_count") == 0
                and value.get("signals") == []
            ),
        )
        signal_payload = {"source": "dw CLI", "target_runtime": runtime, "note": "signal/query parity"}
        sent = cli_signal(wid, SIGNAL_NAME, [signal_payload])
        after_signal_query = retry_query_until(
            wid,
            "state",
            lambda value: (
                isinstance(value, dict)
                and value.get("stage") == "signaled"
                and value.get("signal_count") == 1
                and value.get("signals") == [signal_payload]
            ),
        )
        after_signal_repeat = retry_query_until(
            wid,
            "state",
            lambda value: (
                isinstance(value, dict)
                and value.get("stage") == "signaled"
                and value.get("signal_count") == 1
                and value.get("signals") == [signal_payload]
            ),
        )
        completion_payload = {
            "source": "dw CLI",
            "target_runtime": runtime,
            "note": "complete after signal/query parity observation",
        }
        completion_signal = retry_signal_until_accepted(wid, SIGNAL_NAME, [completion_payload])
        after = wait_for_completed(wid)
        result = assert_dict(completed_output(after), f"{name}.result")
        if result.get("signal") != signal_payload:
            raise RuntimeError(f"{name}: signal payload did not round-trip: {result}")
        runs.append({
            "case": name,
            "workflow_id": wid,
            "run_id": after.get("run_id") or start.get("run_id"),
            "workflow_type": workflow_type,
            "runtime": runtime,
            "status": "passed",
            "query_before_signal": before,
            "query_after_signal": after_signal_query,
            "durable_wait_before_signal": {
                "wait_kind": durable_wait.get("wait_kind"),
                "wait_reason": durable_wait.get("wait_reason"),
            },
            "signal_response": sent,
            "completion_signal_response": completion_signal,
            "query_before_signal_repeat": before_repeat,
            "result": result,
            "query_after_signal_repeat": after_signal_repeat,
        })

    return {
        "surface": "signals_queries",
        "status": "passed",
        "workflow_start_driver": "dw CLI",
        "signal_driver": "dw CLI",
        "query_driver": "dw CLI",
        "result_driver": "dw CLI",
        "runs": runs,
    }


def retry_query_until(workflow_id_value: str, query_name: str, predicate) -> dict[str, Any]:  # type: ignore[no-untyped-def]
    deadline = time.monotonic() + 90
    last: dict[str, Any] | None = None
    while time.monotonic() < deadline:
        try:
            response = cli_query(workflow_id_value, query_name)
            result = response.get("result")
            if predicate(result):
                return response
            last = response
        except Exception as exc:  # noqa: BLE001
            last = {"error": str(exc)}
        time.sleep(1)
    raise RuntimeError(f"query {query_name!r} on {workflow_id_value} did not reach expected state: {last}")


def fetch_json_url(url: str, *, label: str | None = None, timeout_seconds: float = 90.0) -> dict[str, Any]:
    request = Request(
        url,
        headers={"Accept": "application/json"},
    )
    label = label or url
    deadline = time.monotonic() + timeout_seconds
    last_error: str | None = None

    while time.monotonic() < deadline:
        try:
            with urlopen(request, timeout=30) as response:  # noqa: S310
                payload = json.loads(response.read().decode("utf-8"))
            if not isinstance(payload, dict):
                raise RuntimeError(f"{label} did not return a JSON object")
            return payload
        except HTTPError as exc:
            body = exc.read().decode("utf-8", errors="replace")[:2000]
            last_error = f"HTTP {exc.code}: {body}"
        except (URLError, TimeoutError, json.JSONDecodeError, RuntimeError) as exc:
            last_error = str(exc)
        time.sleep(2)

    raise RuntimeError(f"{label} did not return usable JSON within {timeout_seconds:.0f}s: {last_error}")


def fetch_waterline(path: str, *, timeout_seconds: float = 90.0) -> dict[str, Any]:
    return fetch_json_url(
        WATERLINE_URL.rstrip("/") + path,
        label=f"Waterline {path}",
        timeout_seconds=timeout_seconds,
    )


def compare_waterline(cli_runs: list[dict[str, Any]]) -> dict[str, Any]:
    fetch_waterline("/api/v2/health")

    details: dict[str, Any] = {}
    shapes: dict[str, list[str]] = {}
    for run in cli_runs:
        run_id = run.get("run_id")
        if not isinstance(run_id, str) or not run_id:
            raise RuntimeError(f"Waterline comparison missing run_id for {run['scenario']}: {run}")
        path = f"/api/instances/{quote(run['workflow_id'])}/runs/{quote(run_id)}?history_limit=all"
        detail = fetch_waterline(path)
        if detail.get("workflow_type") != run["workflow_type"]:
            raise RuntimeError(f"Waterline workflow_type mismatch for {run['scenario']}: {detail}")
        if detail.get("arguments") != run.get("input"):
            raise RuntimeError(f"Waterline did not render arguments for {run['scenario']}: {detail}")
        if detail.get("output") != run.get("result"):
            raise RuntimeError(f"Waterline output rendering mismatch for {run['scenario']}: {detail.get('output')}")
        summary = summarize_waterline_detail(detail)
        if not summary["event_types"]:
            raise RuntimeError(f"Waterline event typing summary empty for {run['scenario']}: {detail}")
        expected_rust_activity_worker = {
            "rust_same_language": "rust-workflow-worker",
            "python_to_rust": "rust-activity-worker",
            "php_to_rust": "rust-activity-worker",
        }.get(run["scenario"])
        if (
            expected_rust_activity_worker is not None
            and expected_rust_activity_worker not in summary["activity_worker_ids"]
        ):
            raise RuntimeError(
                f"Waterline did not attribute {run['scenario']} activity to "
                f"{expected_rust_activity_worker}: {summary}"
            )
        details[run["scenario"]] = summary
        shapes[run["scenario"]] = sorted(detail.keys())

    health = fetch_waterline("/api/v2/health")
    worker_summary = summarize_waterline_workers(health)
    required_runtimes = {"php", "python", "rust"}
    if not required_runtimes.issubset(set(worker_summary["runtimes"])):
        raise RuntimeError(f"Waterline health did not attribute PHP, Python, and Rust runtimes: {worker_summary}")
    required_rust_workers = {"rust-workflow-worker", "rust-activity-worker"}
    if not required_rust_workers.issubset(set(worker_summary["worker_ids"])):
        raise RuntimeError(f"Waterline health did not attribute both Rust workers: {worker_summary}")

    reference_shape = shapes["php_same_language"]
    if any(shape != reference_shape for shape in shapes.values()):
        raise RuntimeError(f"Waterline run-detail shape drifted across polyglot runs: {shapes}")

    return {
        "surface": "waterline",
        "status": "passed",
        "event_typing": details,
        "worker_attribution": worker_summary,
        "shape_compared": True,
    }


def summarize_waterline_detail(detail: dict[str, Any]) -> dict[str, Any]:
    event_types: set[str] = set()
    for row in (detail.get("timeline") or []) + (detail.get("logs") or []):
        if isinstance(row, dict):
            for key in ("event_type", "history_event_type", "type"):
                value = row.get(key)
                if isinstance(value, str):
                    event_types.add(value)
    for row in detail.get("chartData") or []:
        if isinstance(row, dict):
            value = row.get("type")
            if isinstance(value, str):
                event_types.add(value)
    raw_activities = detail.get("activities")
    activities = raw_activities if isinstance(raw_activities, list) else []
    activity_queues: set[str] = set()
    activity_worker_ids: set[str] = set()
    for row in activities:
        if not isinstance(row, dict):
            continue
        queue = row.get("queue")
        if isinstance(queue, str):
            activity_queues.add(queue)
        lease_owner = row.get("lease_owner")
        if isinstance(lease_owner, str):
            activity_worker_ids.add(lease_owner)
        for attempt in row.get("attempts") or []:
            if not isinstance(attempt, dict):
                continue
            lease_owner = attempt.get("lease_owner")
            if isinstance(lease_owner, str):
                activity_worker_ids.add(lease_owner)
    return {
        "workflow_type": detail.get("workflow_type"),
        "payload_rendering": {
            "arguments": detail.get("arguments") is not None,
            "output": detail.get("output") is not None,
        },
        "event_types": sorted(event_types),
        "activity_count": len(activities),
        "activity_queues": sorted(activity_queues),
        "activity_worker_ids": sorted(activity_worker_ids),
    }


def summarize_waterline_workers(health: dict[str, Any]) -> dict[str, Any]:
    registrations = (((health.get("operator_metrics") or {}).get("workers") or {}).get("registrations") or [])
    runtimes: set[str] = set()
    queues: set[str] = set()
    worker_ids: set[str] = set()
    attributed: list[dict[str, Any]] = []
    for item in registrations:
        if not isinstance(item, dict):
            continue
        runtime = item.get("runtime")
        queue = item.get("task_queue")
        worker_id_value = item.get("worker_id")
        if isinstance(runtime, str):
            runtimes.add(runtime)
        if isinstance(queue, str):
            queues.add(queue)
        if isinstance(worker_id_value, str):
            worker_ids.add(worker_id_value)
        attributed.append({
            "worker_id": worker_id_value,
            "runtime": runtime,
            "sdk_version": item.get("sdk_version"),
            "task_queue": queue,
        })
    return {
        "runtimes": sorted(runtimes),
        "task_queues": sorted(queues),
        "worker_ids": sorted(worker_ids),
        "registrations": sorted(attributed, key=lambda item: str(item.get("worker_id"))),
    }


async def run_all() -> int:
    php_probe, php_probe_error = fetch_php_artifact_probe()
    php_sdk_worker_error: str | None = None
    try:
        php_sdk_worker_version = await wait_for_worker(
            task_queue=PHP2PY_QUEUE,
            runtime="php",
            workflow_type="polyglot.php-to-python.PhpToPythonWorkflow",
            worker_id="php-workflow-worker",
        )
    except Exception as exc:  # noqa: BLE001
        php_sdk_worker_version = None
        php_sdk_worker_error = str(exc)

    artifact_versions = resolved_artifact_versions(php_probe, php_sdk_worker_version)
    avro_packages = official_avro_packages(php_probe)
    missing_avro_packages = {
        runtime: package
        for runtime, package in avro_packages.items()
        if package.get("version") is None
        or (
            package.get("required_version") is not None
            and package.get("version") != package.get("required_version")
        )
    }
    stale_artifacts, missing_artifacts = artifact_version_findings(artifact_versions)
    stale_assets = waterline_asset_findings(php_probe)

    if stale_artifacts or missing_artifacts or stale_assets or missing_avro_packages:
        surfaces = {
            "artifact_versions": {
                "surface": "artifact_versions",
                "status": "artifact_blocked",
                "reason": "The polyglot smoke did not resolve the required current published artifact set.",
                "required": REQUIRED_ARTIFACT_VERSIONS,
                "resolved": artifact_versions,
                "stale": stale_artifacts,
                "missing": missing_artifacts,
                "stale_assets": stale_assets,
                "official_avro_packages": avro_packages,
                "missing_avro_packages": missing_avro_packages,
            },
        }
        metadata = {
            "schema": "durable-workflow.sample-app.polyglot-conformance.run",
            "version": 3,
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "artifactVersions": artifact_versions,
            "requiredArtifactVersions": REQUIRED_ARTIFACT_VERSIONS,
            "artifactProbe": {
                "url": php_artifact_probe_url(),
                "payload": php_probe,
                "error": php_probe_error,
                "standalonePhpWorkerError": php_sdk_worker_error,
            },
            "artifacts": artifact_metadata(
                artifact_versions,
                php_probe,
                php_probe_error,
                php_worker_error=php_sdk_worker_error,
            ),
            "publishedDependencies": {"officialApacheAvro": avro_packages},
            "surfaces": surfaces,
            "summary": {
                "status": "artifact_blocked",
                "surface_count": len(surfaces),
                "failed_surfaces": [],
                "skipped_surfaces": [],
                "artifact_versions_current": False,
                "stale_artifacts": stale_artifacts,
                "missing_artifacts": missing_artifacts,
                "waterline_assets_current": False,
                "stale_assets": stale_assets,
                "missing_avro_packages": missing_avro_packages,
                "artifact_probe_error": php_probe_error,
            },
        }
        print("\n==> polyglot conformance: artifact-version preflight", flush=True)
        print(json.dumps(metadata, indent=2, sort_keys=True, ensure_ascii=False), flush=True)
        print("\npolyglot conformance: required artifact set is not current", flush=True)
        return 1

    surfaces: dict[str, dict[str, Any]] = {}
    failed = False
    cli_runs: list[dict[str, Any]] = []

    print("\n==> polyglot conformance: CLI four-corner runtime matrix", flush=True)
    try:
        cli_runs = await four_corner_cli_scenarios()
        surfaces["cli_start_result"] = {"status": "passed", "runs": cli_runs}
        surfaces["four_corners"] = {"status": "passed", "runs": [run["scenario"] for run in cli_runs[:4]]}
        surfaces["runtime_matrix"] = {
            "status": "passed",
            "languages": ["php", "python", "rust"],
            "executed_runtimes": sorted({
                runtime
                for run in cli_runs
                for runtime in (run["workflow_language"], run["activity_language"])
            }),
            "rust_execution": True,
            "cells": runtime_matrix_cells(cli_runs),
        }
    except RuntimeMatrixError as exc:
        failed = True
        surfaces["cli_start_result"] = {"status": "failed", "error": str(exc)}
        surfaces["four_corners"] = {"status": "failed", "error": str(exc)}
        surfaces["runtime_matrix"] = {
            "status": "failed",
            "error": str(exc),
            "rust_execution": False,
            "cells": runtime_matrix_cells(exc.completed_runs),
            "failed_cell": exc.failed_cell,
        }
        print(json.dumps(surfaces["cli_start_result"], indent=2, sort_keys=True), flush=True)
    except Exception as exc:  # noqa: BLE001
        failed = True
        surfaces["cli_start_result"] = {"status": "failed", "error": str(exc)}
        surfaces["four_corners"] = {"status": "failed", "error": str(exc)}
        surfaces["runtime_matrix"] = {
            "status": "failed",
            "error": str(exc),
            "rust_execution": False,
            "cells": [],
        }
        print(json.dumps(surfaces["cli_start_result"], indent=2, sort_keys=True), flush=True)

    print("\n==> polyglot conformance: type round-trip matrix", flush=True)
    try:
        surfaces["type_matrix"] = await run_type_matrix()
    except Exception as exc:  # noqa: BLE001
        failed = True
        surfaces["type_matrix"] = {"status": "failed", "error": str(exc)}
    print(json.dumps(surfaces["type_matrix"], indent=2, sort_keys=True), flush=True)

    print("\n==> polyglot conformance: typed error round trips", flush=True)
    try:
        surfaces["typed_errors"] = await run_typed_errors()
    except Exception as exc:  # noqa: BLE001
        failed = True
        surfaces["typed_errors"] = {"status": "failed", "error": str(exc)}
    print(json.dumps(surfaces["typed_errors"], indent=2, sort_keys=True), flush=True)

    print("\n==> polyglot conformance: CLI signal/query matrix", flush=True)
    try:
        surfaces["signals_queries"] = await run_signal_query()
    except Exception as exc:  # noqa: BLE001
        failed = True
        surfaces["signals_queries"] = {"status": "failed", "error": str(exc)}
    print(json.dumps(surfaces["signals_queries"], indent=2, sort_keys=True), flush=True)

    print("\n==> polyglot conformance: Waterline mixed-language rendering", flush=True)
    try:
        if cli_runs:
            surfaces["waterline"] = compare_waterline(cli_runs)
        else:
            surfaces["waterline"] = {
                "status": "skipped",
                "reason": "CLI four-corner runs did not produce run ids for Waterline comparison.",
            }
    except Exception as exc:  # noqa: BLE001
        failed = True
        surfaces["waterline"] = {"status": "failed", "error": str(exc)}
    print(json.dumps(surfaces["waterline"], indent=2, sort_keys=True), flush=True)

    failed_surfaces = [
        name for name, surface in surfaces.items() if surface.get("status") == "failed"
    ]
    summary_status = "failed" if failed else "passed"
    metadata = {
        "schema": "durable-workflow.sample-app.polyglot-conformance.run",
        "version": 3,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "artifactVersions": artifact_versions,
        "requiredArtifactVersions": REQUIRED_ARTIFACT_VERSIONS,
        "artifactProbe": {
            "url": php_artifact_probe_url(),
            "payload": php_probe,
            "error": php_probe_error,
            "standalonePhpWorkerError": php_sdk_worker_error,
        },
        "artifacts": artifact_metadata(
            artifact_versions,
            php_probe,
            php_probe_error,
            rust_exercised=(surfaces.get("runtime_matrix", {}).get("status") == "passed"),
            php_worker_error=php_sdk_worker_error,
            runtime_matrix=surfaces.get("runtime_matrix"),
        ),
        "publishedDependencies": {"officialApacheAvro": avro_packages},
        "surfaces": surfaces,
        "summary": {
            "status": summary_status,
            "surface_count": len(surfaces),
            "failed_surfaces": failed_surfaces,
            "skipped_surfaces": [
                name for name, surface in surfaces.items() if surface.get("status") == "skipped"
            ],
            "artifact_versions_current": True,
            "waterline_assets_current": True,
            "rust_executed": surfaces.get("runtime_matrix", {}).get("rust_execution") is True,
        },
    }

    print("\n==> polyglot conformance: run metadata", flush=True)
    print(json.dumps(metadata, indent=2, sort_keys=True, ensure_ascii=False), flush=True)
    if failed:
        print("\npolyglot conformance: one or more required surfaces failed", flush=True)
        return 1

    print("\npolyglot conformance: all required surfaces passed", flush=True)
    return 0


if __name__ == "__main__":
    try:
        sys.exit(asyncio.run(run_all()))
    except Exception as exc:  # noqa: BLE001
        print(str(exc), file=sys.stderr)
        sys.exit(1)
