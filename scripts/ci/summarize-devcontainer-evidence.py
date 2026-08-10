#!/usr/bin/env python3
"""Validate and combine public devcontainer workflow evidence."""

from __future__ import annotations

import json
import sys
from collections import defaultdict
from pathlib import Path
from typing import Any


EXPECTED_ARCHITECTURES = {
    "linux/amd64": ("amd64", "x86_64"),
    "linux/arm64": ("arm64", "aarch64"),
}
EXPECTED_REGISTRIES = {"ghcr", "dockerhub"}


def fail(message: str) -> None:
    raise SystemExit(message)


def load_evidence(directory: Path) -> list[dict[str, Any]]:
    evidence: list[dict[str, Any]] = []
    for path in sorted(directory.glob("*.json")):
        with path.open(encoding="utf-8") as source:
            payload = json.load(source)
        if not isinstance(payload, dict) or "evidence_type" not in payload:
            fail(f"{path} is not a devcontainer evidence record")
        payload["evidence_file"] = path.name
        evidence.append(payload)

    if not evidence:
        fail(f"no JSON evidence records found in {directory}")

    return evidence


def group_by_type(evidence: list[dict[str, Any]]) -> dict[str, list[dict[str, Any]]]:
    grouped: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for record in evidence:
        grouped[str(record["evidence_type"])].append(record)
    return grouped


def require_types(
    grouped: dict[str, list[dict[str, Any]]],
    expected_counts: dict[str, int],
) -> None:
    actual_counts = {name: len(records) for name, records in grouped.items()}
    if actual_counts != expected_counts:
        fail(f"evidence type counts do not match: expected={expected_counts}, actual={actual_counts}")


def validate_native(record: dict[str, Any]) -> None:
    platform = str(record.get("platform"))
    if platform not in EXPECTED_ARCHITECTURES:
        fail(f"unsupported evidence platform: {platform}")

    expected_architecture, expected_machine = EXPECTED_ARCHITECTURES[platform]
    runner = record.get("runner", {})
    if not isinstance(runner, dict):
        fail(f"runner evidence is missing for {platform}")

    if runner.get("docker_architecture") not in {expected_architecture, expected_machine}:
        fail(f"Docker did not run natively for {platform}: {runner}")
    if runner.get("host_machine") != expected_machine:
        fail(f"host did not run natively for {platform}: {runner}")
    if runner.get("host_architecture", expected_architecture) != expected_architecture:
        fail(f"normalized host architecture does not match {platform}: {runner}")


def validate_timings(evidence: list[dict[str, Any]]) -> tuple[int, int]:
    starts: list[int] = []
    completions: list[int] = []
    for record in evidence:
        started = record.get("run_started_epoch_ms")
        completed = record.get("completed_epoch_ms")
        stages = record.get("stages_ms")
        if not isinstance(started, int) or not isinstance(completed, int) or completed < started:
            fail(f"invalid evidence timestamps in {record.get('evidence_file')}")
        if not isinstance(stages, dict) or not stages:
            fail(f"stage timing is missing in {record.get('evidence_file')}")
        if any(not isinstance(value, int) or value < 0 for value in stages.values()):
            fail(f"invalid stage timing in {record.get('evidence_file')}: {stages}")
        starts.append(started)
        completions.append(completed)

    return min(starts), max(completions)


def validate_candidate(grouped: dict[str, list[dict[str, Any]]]) -> None:
    require_types(grouped, {"candidate_qualification": 2})
    candidates = grouped["candidate_qualification"]
    if {record.get("platform") for record in candidates} != set(EXPECTED_ARCHITECTURES):
        fail("candidate evidence must contain one native record for each architecture")
    for record in candidates:
        validate_native(record)
        if record.get("anonymous_pull_verification", {}).get("required"):
            fail("local pull-request candidates must not claim an anonymous public pull")


def validate_publication(grouped: dict[str, list[dict[str, Any]]]) -> None:
    require_types(
        grouped,
        {
            "architecture_publication": 2,
            "index_assembly": 1,
            "moving_channel_verification": 2,
            "promotion": 1,
            "public_qualification": 4,
        },
    )

    builds = grouped["architecture_publication"]
    if {record.get("platform") for record in builds} != set(EXPECTED_ARCHITECTURES):
        fail("publication evidence must contain one native build for each architecture")
    for record in builds:
        validate_native(record)
        compressed_platform_bytes = record.get("compressed_platform_bytes")
        if not isinstance(compressed_platform_bytes, int) or compressed_platform_bytes <= 0:
            fail(f"compressed platform size is missing for {record.get('platform')}")
        if record.get("manifest_digest_parity") is not True:
            fail(f"architecture registry digest parity failed for {record.get('platform')}")
        attestations = record.get("attestations", {})
        if attestations.get("provenance") != "mode=max" or attestations.get("sbom") is not True:
            fail(f"attestation evidence is incomplete for {record.get('platform')}")

    index = grouped["index_assembly"][0]
    if set(index.get("platforms", [])) != set(EXPECTED_ARCHITECTURES):
        fail("assembled index does not contain the two supported platforms")
    if index.get("manifest_digest_parity") is not True:
        fail("GHCR and Docker Hub indexes do not have matching digests")

    qualifications = grouped["public_qualification"]
    qualification_cells = {
        (record.get("registry"), record.get("platform")) for record in qualifications
    }
    expected_cells = {
        (registry, platform)
        for registry in EXPECTED_REGISTRIES
        for platform in EXPECTED_ARCHITECTURES
    }
    if qualification_cells != expected_cells:
        fail(f"public qualification cells do not match: {qualification_cells}")
    for record in qualifications:
        validate_native(record)
        anonymous = record.get("anonymous_pull_verification", {})
        if not all(
            anonymous.get(key) is True
            for key in ("required", "credentials_absent", "pull_performed")
        ):
            fail(f"anonymous pull verification failed for {record.get('registry')}/{record.get('platform')}")

    promotion = grouped["promotion"][0]
    if set(promotion.get("qualification_gate", [])) != {
        "ghcr/amd64",
        "ghcr/arm64",
        "dockerhub/amd64",
        "dockerhub/arm64",
    }:
        fail("main promotion did not record every public qualification gate")

    moving_channels = grouped["moving_channel_verification"]
    if {record.get("registry") for record in moving_channels} != EXPECTED_REGISTRIES:
        fail("moving-channel evidence must inspect both public registries")
    if any(
        record.get("anonymous_manifest_inspection") is not True
        or record.get("revision_and_main_digest_parity") is not True
        for record in moving_channels
    ):
        fail("moving-channel anonymous manifest inspection failed")
    if len({record.get("manifest_digest") for record in moving_channels}) != 1:
        fail("GHCR and Docker Hub main manifests do not have matching digests")


def main() -> None:
    if len(sys.argv) != 5:
        fail(
            "Usage: summarize-devcontainer-evidence.py "
            "{candidate|publication} EVIDENCE_DIRECTORY OUTPUT MAX_SECONDS"
        )

    mode = sys.argv[1]
    evidence_directory = Path(sys.argv[2])
    output_path = Path(sys.argv[3])
    max_seconds = int(sys.argv[4])
    evidence = load_evidence(evidence_directory)
    grouped = group_by_type(evidence)

    if mode == "candidate":
        validate_candidate(grouped)
    elif mode == "publication":
        validate_publication(grouped)
    else:
        fail(f"unsupported evidence mode: {mode}")

    started, completed = validate_timings(evidence)
    elapsed_ms = completed - started
    max_duration_ms = max_seconds * 1000
    summary = {
        "schema_version": 1,
        "mode": mode,
        "run_started_epoch_ms": started,
        "completed_epoch_ms": completed,
        "elapsed_ms": elapsed_ms,
        "max_duration_ms": max_duration_ms,
        "within_duration_budget": elapsed_ms < max_duration_ms,
        "runner_architectures": sorted(
            {
                f"{record['platform']}@{record['runner']['label']}"
                for record in evidence
                if "platform" in record and "runner" in record
            }
        ),
        "manifest_digest_parity": (
            grouped["index_assembly"][0]["manifest_digest_parity"]
            if mode == "publication"
            else None
        ),
        "compressed_platform_bytes": (
            {
                record["platform"]: record["compressed_platform_bytes"]
                for record in grouped["architecture_publication"]
            }
            if mode == "publication"
            else None
        ),
        "anonymous_pull_verification": (
            all(
                record["anonymous_pull_verification"]["credentials_absent"]
                and record["anonymous_pull_verification"]["pull_performed"]
                for record in grouped["public_qualification"]
            )
            if mode == "publication"
            else None
        ),
        "evidence": evidence,
    }

    with output_path.open("w", encoding="utf-8") as output:
        json.dump(summary, output, indent=2, sort_keys=True)
        output.write("\n")
    print(json.dumps(summary, indent=2, sort_keys=True))

    if elapsed_ms >= max_duration_ms:
        fail(
            f"cold {mode} workflow took {elapsed_ms}ms; "
            f"limit is strictly less than {max_duration_ms}ms"
        )


if __name__ == "__main__":
    main()
