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
        microservice = self.assert_job_condition(ci, "microservice-test", GITHUB_ONLY)
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
        self.assertIn("hashFiles('composer.lock')", php)
        self.assertNotIn("hashFiles('**/composer.lock')", php)
        self.assertIn("run: php artisan test", php)
        self.assertIn(
            "name: microservice composer and tests (php ${{ matrix.php }})",
            microservice,
        )
        self.assertIn("working-directory: microservice", microservice)
        self.assertIn("php: ['8.4', '8.5']", microservice)
        self.assertIn("MYSQL_DATABASE: microservice", microservice)
        self.assertIn("DB_CONNECTION: mysql", microservice)
        self.assertIn("SHARED_DB_DATABASE: microservice", microservice)
        self.assertIn(
            "composer validate --strict --check-lock --no-check-all",
            microservice,
        )
        self.assertIn(
            "composer install --prefer-dist --no-progress --no-interaction",
            microservice,
        )
        self.assertIn("composer audit --locked", microservice)
        self.assertIn("run: php artisan test", microservice)
        self.assertNotIn("actions/cache", microservice)
        self.assertIn("name: Target branch qualification", php_qualification)
        self.assertIn("needs: [test, microservice-test]", php_qualification)
        self.assertIn(f"if: {GITHUB_ONLY}", php_qualification)
        self.assertIn('run: test "$TEST_RESULT" = success', php_qualification)
        self.assertIn(
            'run: test "$MICROSERVICE_TEST_RESULT" = success',
            php_qualification,
        )

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
            ("ci.yml", "microservice-test"),
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

    def test_devcontainer_publication_isolated_from_untrusted_validation(self) -> None:
        candidate_workflow = read_workflow("devcontainer-image-pr.yml")
        publication_workflow = read_workflow("devcontainer-image.yml")
        combined_workflows = f"{candidate_workflow}\n{publication_workflow}"
        candidate_header = candidate_workflow.split("\njobs:", maxsplit=1)[0]
        publication_header = publication_workflow.split("\njobs:", maxsplit=1)[0]
        artifact_identity = job_block(publication_workflow, "artifact-identity")
        validate = job_block(candidate_workflow, "validate")
        candidate_evidence = job_block(candidate_workflow, "candidate-evidence")
        publish = job_block(publication_workflow, "publish-architecture")
        assembly = job_block(publication_workflow, "assemble-indexes")
        qualification = job_block(publication_workflow, "qualify-published")
        promotion = job_block(publication_workflow, "promote-main")
        recovery = job_block(publication_workflow, "recover-main")
        moving_channel = job_block(publication_workflow, "verify-main")
        publication_evidence = job_block(publication_workflow, "publication-evidence")

        matrix_runner = (
            "runs-on: ${{ github.server_url == 'https://github.com' "
            "&& matrix.runner || 'ubuntu-latest' }}"
        )
        aggregation_runner = (
            "runs-on: ${{ github.server_url == 'https://github.com' "
            "&& 'ubuntu-24.04' || 'ubuntu-latest' }}"
        )

        self.assertIn("  pull_request:\n    branches: [ main ]", candidate_header)
        self.assertNotIn("  push:", candidate_header)
        self.assertNotIn("  schedule:", candidate_header)
        self.assertNotIn("  workflow_dispatch:", candidate_header)
        self.assertIn("permissions:\n  contents: read", candidate_header)
        for header in (candidate_header, publication_header):
            self.assertIn(".github/workflows/devcontainer-image.yml", header)
            self.assertIn(".github/workflows/devcontainer-image-pr.yml", header)
        self.assertNotIn("  pull_request:", publication_header)
        self.assertIn("  push:\n    branches: [ main ]", publication_header)
        self.assertIn("  schedule:", publication_header)
        self.assertIn("  workflow_dispatch:", publication_header)
        self.assertIn("permissions:\n  contents: read", publication_header)
        self.assertIn("devcontainer-image-${{ github.event.pull_request.number }}", candidate_header)
        self.assertIn("group: devcontainer-image-protected-main", publication_header)
        self.assertNotIn("\n  publish-architecture:", candidate_workflow)
        self.assertNotIn("\n  validate:", publication_workflow)
        self.assertNotIn("pull_request_target", combined_workflows)
        self.assertNotIn("setup-qemu-action", combined_workflows)
        self.assertNotIn("QEMU", combined_workflows)
        for forbidden in (
            "packages: write",
            "secrets.",
            "docker/login-action",
            "cache-from",
            "cache-to",
            "environment:",
        ):
            self.assertNotIn(forbidden, candidate_workflow)
        self.assertIn("github.event_name == 'pull_request'", validate)
        self.assertIn("runner: ubuntu-24.04", validate)
        self.assertIn("runner: ubuntu-24.04-arm", validate)
        for block in (validate, publish, qualification):
            self.assertIn(matrix_runner, block)
        for block in (
            artifact_identity,
            candidate_evidence,
            assembly,
            promotion,
            recovery,
            moving_channel,
            publication_evidence,
        ):
            self.assertIn(aggregation_runner, block)
        self.assertIn("contents: read", validate)
        self.assertNotIn("packages: write", validate)
        self.assertNotIn("secrets.", validate)
        self.assertNotIn("docker/login-action", validate)
        self.assertNotIn("needs:", validate)
        self.assertNotIn("cache-from", validate)
        self.assertNotIn("cache-to", validate)
        self.assertIn("no-cache: true", validate)
        self.assertIn("needs: [validate]", candidate_evidence)
        self.assertIn("summarize-devcontainer-evidence.py", candidate_evidence)

        self.assertIn("github.event_name != 'pull_request'", artifact_identity)
        self.assertIn("contents: read", artifact_identity)
        self.assertNotIn("packages: write", artifact_identity)
        self.assertNotIn("secrets.", artifact_identity)
        self.assertNotIn("docker/login-action", artifact_identity)
        self.assertIn(
            'revision_tag="sha-${GITHUB_SHA}-run-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"',
            artifact_identity,
        )

        self.assertIn("github.repository == 'durable-workflow/sample-app'", publish)
        self.assertIn("github.ref == 'refs/heads/main'", publish)
        self.assertIn("github.event_name != 'pull_request'", publish)
        self.assertIn("needs: [artifact-identity]", publish)
        self.assertIn("runner: ubuntu-24.04", publish)
        self.assertIn("runner: ubuntu-24.04-arm", publish)
        self.assertIn("packages: write", publish)
        self.assertIn("secrets.DOCKERHUB_TOKEN", publish)
        self.assertIn("platforms: ${{ matrix.platform }}", publish)
        self.assertIn("${{ env.REVISION_TAG }}-${{ matrix.suffix }}", publish)
        self.assertIn("provenance: mode=max", publish)
        self.assertIn("sbom: true", publish)
        self.assertNotIn("cache-from", publish)
        self.assertNotIn("cache-to", publish)

        self.assertIn("needs: [artifact-identity, publish-architecture]", assembly)
        self.assertIn("github.ref == 'refs/heads/main'", assembly)
        self.assertIn("imagetools create", assembly)
        self.assertIn("cmp ghcr-index.json dockerhub-index.json", assembly)
        self.assertIn("needs: [artifact-identity, assemble-indexes]", qualification)
        self.assertIn("runner: ubuntu-24.04-arm", qualification)
        self.assertIn("DEVCONTAINER_REQUIRE_ANONYMOUS_PULL: 1", qualification)
        self.assertNotIn("secrets.", qualification)
        self.assertNotIn("docker/login-action", qualification)
        self.assertIn(
            "needs: [artifact-identity, assemble-indexes, qualify-published]",
            promotion,
        )
        self.assertIn("github.ref == 'refs/heads/main'", promotion)
        self.assertIn("inputs.recover_revision_tag != ''", recovery)
        self.assertIn("github.repository == 'durable-workflow/sample-app'", recovery)
        self.assertIn("github.ref == 'refs/heads/main'", recovery)
        self.assertIn("packages: write", recovery)
        self.assertIn("secrets.DOCKERHUB_TOKEN", recovery)
        self.assertIn("^sha-[0-9a-f]{40}-run-[0-9]+-[0-9]+$", recovery)
        self.assertIn("cmp ghcr-source.json dockerhub-source.json", recovery)
        self.assertIn('architectures != {"amd64", "arm64"}', recovery)
        self.assertIn("cmp ghcr-main.json dockerhub-main.json", recovery)
        self.assertIn("needs: [artifact-identity, promote-main]", moving_channel)
        self.assertIn("anonymous-docker-config", moving_channel)
        self.assertIn("needs: [verify-main]", publication_evidence)
        self.assertIn("summarize-devcontainer-evidence.py", publication_evidence)

        action_refs = []
        for line in combined_workflows.splitlines():
            stripped = line.strip()
            if not stripped.startswith("uses: "):
                continue
            action_refs.append(stripped.split("@", maxsplit=1)[1].split()[0])

        self.assertTrue(action_refs)
        for ref in action_refs:
            self.assertRegex(ref, r"^[0-9a-f]{40}$")


if __name__ == "__main__":
    unittest.main(verbosity=2)
