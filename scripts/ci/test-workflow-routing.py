#!/usr/bin/env python3
"""Structural coverage for public and execution-mirror workflow routing."""

from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / ".github" / "workflows"
GITHUB_ONLY = "${{ github.server_url == 'https://github.com' }}"
MIRROR_ONLY = "${{ github.server_url != 'https://github.com' }}"
GITHUB_ALWAYS = "${{ always() && github.server_url == 'https://github.com' }}"


def read_workflow(name: str) -> str:
    return (WORKFLOWS / name).read_text(encoding="utf-8")


def job_block(workflow: str, job_id: str) -> str:
    lines = workflow.splitlines()
    marker = f"  {job_id}:"

    try:
        start = lines.index(marker)
    except ValueError as error:
        raise AssertionError(f"workflow is missing job {job_id}") from error

    end = len(lines)
    for index in range(start + 1, len(lines)):
        line = lines[index]
        if line.startswith("  ") and not line.startswith("    ") and line.endswith(":"):
            end = index
            break

    return "\n".join(lines[start:end])


class WorkflowRoutingTest(unittest.TestCase):
    def assert_main_source_triggers(self, workflow: str) -> None:
        header = workflow.split("\njobs:", maxsplit=1)[0]

        self.assertIn("  push:\n    branches: [ main ]", header)
        self.assertIn("  pull_request:\n    branches: [ main ]", header)
        self.assertIn("  workflow_dispatch:", header)
        self.assertNotIn("pull_request_target", header)
        self.assertIn("permissions:\n  contents: read", header)

    def assert_job_condition(self, workflow: str, job_id: str, condition: str) -> str:
        block = job_block(workflow, job_id)
        self.assertIn(f"\n    if: {condition}\n", block)

        return block

    def test_github_keeps_every_authoritative_source_workload(self) -> None:
        ci = read_workflow("ci.yml")
        polyglot = read_workflow("polyglot-validation.yml")
        compose = read_workflow("smoke.yml")
        boundary = read_workflow("public-boundary.yml")

        for workflow in (ci, polyglot, compose):
            self.assert_main_source_triggers(workflow)

        php = self.assert_job_condition(ci, "test", GITHUB_ONLY)
        php_qualification = job_block(ci, "target-branch-qualification")
        polyglot_matrix = self.assert_job_condition(polyglot, "smoke", GITHUB_ONLY)
        polyglot_qualification = self.assert_job_condition(
            polyglot,
            "polyglot-qualification",
            GITHUB_ALWAYS,
        )
        compose_smoke = self.assert_job_condition(compose, "compose", GITHUB_ONLY)
        public_boundary = self.assert_job_condition(boundary, "scan", GITHUB_ONLY)

        self.assertIn("php: ['8.4', '8.5']", php)
        self.assertIn("run: php artisan test", php)
        self.assertIn("name: Target branch qualification", php_qualification)
        self.assertIn(f"if: {GITHUB_ONLY}", php_qualification)
        self.assertIn('run: test "$TEST_RESULT" = success', php_qualification)

        self.assertIn("cache_mode: [cold-cache, warm-cache]", polyglot_matrix)
        self.assertIn("scripts/polyglot-validation.sh", polyglot_matrix)
        self.assertIn("name: polyglot smoke (PHP/Python/Rust)", polyglot_qualification)
        self.assertIn('run: test "$SMOKE_RESULT" = success', polyglot_qualification)

        self.assertIn("name: docker compose sample workflows", compose_smoke)
        self.assertEqual(1, compose_smoke.count("scripts/compose-smoke.sh"))
        self.assertIn("name: Scan public boundary", public_boundary)
        self.assertEqual(1, public_boundary.count("scripts/check-public-boundary.sh"))

    def test_execution_mirror_selects_one_bounded_structural_job(self) -> None:
        ci = read_workflow("ci.yml")
        qualification = job_block(ci, "target-branch-qualification")

        self.assertIn("name: Target branch qualification", qualification)
        self.assertIn("if: ${{ always() }}", qualification)
        self.assertIn("timeout-minutes: 2", qualification)
        self.assertEqual(3, qualification.count(f"if: {MIRROR_ONLY}"))
        self.assertIn("python3 scripts/ci/test-workflow-routing.py", qualification)
        self.assertIn("python3 -m unittest discover", qualification)
        self.assertIn("git diff --check", qualification)
        self.assertIn("bash -n", qualification)
        self.assertIn("scripts/check-public-boundary.sh", qualification)

        for forbidden in (
            "actions/cache",
            "composer install",
            "docker compose",
            "php artisan test",
            "secrets.",
            "setup-php",
        ):
            self.assertNotIn(forbidden, qualification)

        broad_jobs = (
            ("ci.yml", "test"),
            ("polyglot-validation.yml", "smoke"),
            ("polyglot-validation.yml", "polyglot-qualification"),
            ("smoke.yml", "compose"),
            ("public-boundary.yml", "scan"),
        )
        for workflow_name, job_id in broad_jobs:
            block = job_block(read_workflow(workflow_name), job_id)
            self.assertIn("github.server_url == 'https://github.com'", block)

    def test_untrusted_pull_requests_receive_no_privileged_candidate_path(self) -> None:
        workflows = [
            read_workflow(name)
            for name in (
                "ci.yml",
                "polyglot-validation.yml",
                "smoke.yml",
                "public-boundary.yml",
            )
        ]
        joined = "\n".join(workflows)
        candidate = job_block(workflows[0], "target-branch-qualification")
        php = job_block(workflows[0], "test")

        self.assertNotIn("pull_request_target", joined)
        self.assertNotIn("secrets.", joined)
        self.assertIn("persist-credentials: false", candidate)
        self.assertNotIn("actions/cache", candidate)
        self.assertEqual(1, joined.count("uses: actions/cache@"))
        self.assertIn("${{ github.event_name }}-", php)


if __name__ == "__main__":
    unittest.main(verbosity=2)
