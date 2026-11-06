import importlib.util
import pathlib
import re
import sys
import types
import unittest
from unittest import mock

from test_bookworm_consumer import GUARD, instructions, protected_php_stages


ROOT = pathlib.Path(__file__).resolve().parents[2]
PYTHON_PARENT = "python:3.12-slim-bookworm@sha256:935c3c5b12357a7e10caa9c1e6ca5692344d97cf229832ef584be2cec9fc03df"
CENTRAL_COMMIT = "18783be6bef737cc778e4c7282a8797e39daa61a"
WORKFLOWS = (
    "smoke", "polyglot-validation", "sdk-timers", "sdk-package-timers",
    "sdk-application-package-timers", "sdk-activity-upgrades", "sdk-unversioned-timers",
    "sdk-children", "sdk-updates", "sdk-namespaces", "sdk-search-attributes",
    "sdk-activity-recovery", "sdk-external-effects",
)


def guarded_managers(source):
    for operation, argument in instructions(source):
        if operation != "RUN":
            continue
        for boundary in re.finditer(r"\bapt-get\s+(?:update|upgrade|install|purge)\b|\bplaywright\s+install\b[^;]*--with-deps", argument):
            assert argument.rfind(GUARD, 0, boundary.start()) >= 0, "Package operation precedes the effective-client guard"
            assert argument.startswith(GUARD) or argument.startswith("set -eux;"), "Guard refusal does not stop the RUN"


class ProducerPrerequisiteTests(unittest.TestCase):
    def test_production_preserves_both_fixed_php_stages_and_all_late_guards(self):
        source = (ROOT / "Dockerfile").read_text()
        protected_php_stages(source)
        guarded_managers(source)

    def test_every_direct_package_operation_requires_its_own_earlier_guard(self):
        for path in ("Dockerfile", "polyglot/python_worker/Dockerfile"):
            source = (ROOT / path).read_text()
            for operation, argument in instructions(source):
                if operation != "RUN" or not re.search(r"\bapt-get\s+(?:update|upgrade|install|purge)\b|--with-deps", argument):
                    continue
                with self.subTest(path=path, run=argument[:60]):
                    changed = argument.replace(GUARD, "true")
                    self.assertNotEqual(argument, changed)
                    with self.assertRaisesRegex(AssertionError, "precedes"):
                        guarded_managers("RUN " + changed)

    def test_python_parent_and_source_copy_precede_the_first_run(self):
        source = (ROOT / "polyglot/python_worker/Dockerfile").read_text()
        self.assertEqual(next(instructions(source)), ("FROM", PYTHON_PARENT))
        copy = source.index("COPY .central-apt-source/docker/apt/")
        first_run = source.index("\nRUN ")
        self.assertLess(copy, first_run)
        self.assertIn("RUN set -eux;", source)
        self.assertLess(source.index(GUARD), source.index("apt-get update"))
        self.assertLess(source.index("prepare-trust.py"), source.index("apt-get update"))
        guarded_managers(source)

    def test_old_pip_has_only_an_offline_bootstrap_before_qualified_dependency_requests(self):
        source = (ROOT / "polyglot/python_worker/Dockerfile").read_text()
        producer = source.index("build-pip.py /tmp/sample-app-python-wheels")
        offline = source.index("--no-index --no-deps --upgrade")
        effective = source.index("verify-python-trust.py --pip-only")
        dependencies = source.index('"fastavro==${FASTAVRO_PYTHON_VERSION}"')
        self.assertLess(producer, offline)
        self.assertLess(offline, effective)
        self.assertLess(effective, dependencies)
        self.assertNotIn("--seed", source)
        self.assertIn('--cert "$SSL_CERT_FILE"', source)

    def test_initial_trust_export_does_not_claim_to_qualify_the_old_pip(self):
        exporter = mock.Mock(return_value={"qualified": "system trust"})
        fake_module = types.SimpleNamespace(export_bundle=exporter)
        path = ROOT / "docker/python/prepare-trust.py"
        spec = importlib.util.spec_from_file_location("prepare_trust", path)
        module = importlib.util.module_from_spec(spec)
        with mock.patch.dict(sys.modules, {"export_trust": fake_module}):
            spec.loader.exec_module(module)
        with mock.patch.object(pathlib.Path, "mkdir"):
            self.assertEqual(module.prepare(), {"qualified": "system trust"})
        self.assertEqual(exporter.call_args.kwargs["clients"], ("curl", "git", "python"))
        self.assertEqual(exporter.call_args.args[0], pathlib.Path(module.ssl.get_default_verify_paths().cafile))

    def test_actual_callers_check_out_the_same_public_source_without_credentials(self):
        for name in WORKFLOWS:
            with self.subTest(workflow=name):
                source = (ROOT / ".github/workflows" / (name + ".yml")).read_text()
                block = source.split("name: Check out the accepted central build source", 1)[1].split("\n      - ", 1)[0]
                self.assertIn("repository: durable-workflow/.github", block)
                self.assertIn("ref: " + CENTRAL_COMMIT, block)
                self.assertIn("path: .central-apt-source", block)
                self.assertIn("persist-credentials: false", block)
        preparation = (ROOT / "scripts/prepare-apt-build-source.sh").read_text()
        self.assertIn(CENTRAL_COMMIT, preparation)
        self.assertIn("credential.helper=", preparation)
        self.assertIn("env -i", preparation)
        self.assertIn("sha256sum --check --strict", preparation)


if __name__ == "__main__":
    unittest.main()
