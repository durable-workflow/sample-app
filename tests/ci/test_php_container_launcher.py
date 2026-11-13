import json
import os
import pathlib
import shlex
import shutil
import subprocess
import sys
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
LAUNCHER = ROOT / "scripts/ci/run-php-container.sh"


class PhpContainerLauncherTests(unittest.TestCase):
    def invoke(self, command="php", arguments=(), **overrides):
        with tempfile.TemporaryDirectory() as directory:
            state = pathlib.Path(directory)
            (state / "bin").mkdir()
            (state / "bin" / command).symlink_to(LAUNCHER)
            docker = state / "bin/docker"
            docker.write_text("#!/usr/bin/env python3\nimport json,sys\nprint(json.dumps(sys.argv[1:]))\n")
            docker.chmod(0o755)
            bundle = state / "ca.pem"
            bundle.write_text("synthetic CA mount, never executed")
            environment = {
                "PATH": str(state / "bin") + os.pathsep + os.environ["PATH"],
                "SAMPLE_APP_PHP_CI_ROOT": str(ROOT),
                "SAMPLE_APP_PHP_CI_STATE": str(state),
                "SAMPLE_APP_PHP_CI_IMAGE": "sha256:" + "f" * 64,
                "SSL_CERT_FILE": str(bundle),
                "COMPOSER_CAFILE": str(bundle),
                "DW_CI_CA_SHA256": "1" * 64,
                "DB_PORT": "12345",
                "GITHUB_TOKEN": "must-not-enter-container",
                "COMPOSER_AUTH": "must-not-enter-container",
                "SAMPLE_APP_PLAYGROUND_SCENARIO": '{"input":["sample"]}',
                "SAMPLE_APP_ROOT": str(ROOT),
                "DURABLE_WORKFLOW_RUNTIME_URL": "http://127.0.0.1:8080",
                "DURABLE_WORKFLOW_WORKER_TOKEN": "synthetic-worker",
                **overrides,
            }
            arguments = [str(state / "bin/composer") if x == "{composer}" else x for x in arguments]
            result = subprocess.run([str(state / "bin" / command), *arguments], cwd=ROOT,
                                    env=environment, text=True, capture_output=True, check=True)
            return json.loads(result.stdout), str(bundle)

    def test_only_selected_application_and_trust_environment_reaches_runtime(self):
        invocation, bundle = self.invoke(arguments=("-r", "echo PHP_VERSION;"))
        passed = [invocation[i + 1] for i, part in enumerate(invocation[:-1]) if part == "--env"]
        self.assertIn("DB_PORT", passed)
        self.assertIn("DW_CI_CA_SHA256", passed)
        self.assertNotIn("GITHUB_TOKEN", passed)
        self.assertNotIn("COMPOSER_AUTH", passed)
        self.assertIn("SAMPLE_APP_PLAYGROUND_SCENARIO", passed)
        self.assertIn("SAMPLE_APP_ROOT", passed)
        self.assertIn("DURABLE_WORKFLOW_RUNTIME_URL", passed)
        self.assertIn("DURABLE_WORKFLOW_WORKER_TOKEN", passed)
        mounts = [invocation[i + 1] for i, part in enumerate(invocation[:-1]) if part == "--mount"]
        self.assertEqual(mounts.count(f"type=bind,source={bundle},target={bundle},readonly"), 1)
        self.assertIn("host", invocation)
        self.assertEqual(invocation[-3:], ["php", "-r", "echo PHP_VERSION;"])

    def test_trust_verifier_inspects_real_composer_phar(self):
        invocation, _ = self.invoke(arguments=("/guard/verify.php", "{composer}", str(ROOT)),
                                    GITHUB_ACTION_PATH="/guard")
        self.assertEqual(invocation[-4:], ["php", "/guard/verify.php", "/usr/local/bin/composer", str(ROOT)])
        self.assertIn("type=bind,source=/guard,target=/guard,readonly", invocation)

    def test_composer_uses_the_container_executable(self):
        invocation, _ = self.invoke(command="composer", arguments=("audit", "--locked"))
        self.assertEqual(invocation[-3:], ["composer", "audit", "--locked"])

    def test_mutable_image_tag_refuses_before_docker(self):
        with self.assertRaises(subprocess.CalledProcessError) as failure:
            self.invoke(SAMPLE_APP_PHP_CI_IMAGE="sample-app-php-ci:mutable")
        self.assertIn("inspected immutable image config ID", failure.exception.stderr)

    def test_missing_runtime_identity_refuses_before_docker(self):
        with tempfile.TemporaryDirectory() as directory:
            command = pathlib.Path(directory) / "php"
            command.symlink_to(LAUNCHER)
            result = subprocess.run([str(command), "--version"], env={"PATH": os.environ["PATH"]},
                                    text=True, capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("PHP CI repository root is required", result.stderr)


class PhpContainerSetupImportTests(unittest.TestCase):
    def check_import_isolation(self, location, module="json"):
        with tempfile.TemporaryDirectory() as directory:
            state = pathlib.Path(directory)
            source = state / "source"
            for relative in (
                "scripts/ci/setup-php-container.sh",
                "scripts/ci/run-php-container.sh",
                "scripts/ci/php-container-images.json",
                "tests/ci/test_php_container_tls.py",
            ):
                destination = source / relative
                destination.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(ROOT / relative, destination)
            guard = source / ".central-apt-source/docker/apt/require-fixed-bookworm-apt.sh"
            guard.parent.mkdir(parents=True)
            guard.touch()
            working_directory = state / "working-directory"
            import_directory = state / "imports"
            working_directory.mkdir()
            import_directory.mkdir()
            home = state / "home"
            home.mkdir()
            marker = state / "import-executed"
            planted = {
                "working-directory": working_directory,
                "PYTHONPATH": import_directory,
                "TLS-script-directory": source / "tests/ci",
            }[location]
            (planted / f"{module}.py").write_text(
                f"open({str(marker)!r}, 'w').write('marker-only import\\n')\n"
                "raise SystemExit(42)\n"
            )
            binary_directory = state / "bin"
            binary_directory.mkdir()
            # Native-command stubs keep this an import-only control: no build,
            # container, certificate generation, network request or trust claim.
            commands = {
                "git": "printf '%s\\n' 18783be6bef737cc778e4c7282a8797e39daa61a\n",
                "sha256sum": "cat >/dev/null\n",
                "docker": (
                    "case \"$1\" in\n"
                    "  build|run) exit 0 ;;\n"
                    f"  image) printf '%s\\n' sha256:{'f' * 64} ;;\n"
                    "  *) exit 91 ;;\nesac\n"
                ),
                "python3": (
                    # Import the real TLS control, but skip its runtime test.
                    "unset SAMPLE_APP_PHP_CI_TEST_IMAGE\n"
                    f"exec {shlex.quote(sys.executable)} \"$@\"\n"
                ),
            }
            for name, body in commands.items():
                command = binary_directory / name
                command.write_text("#!/bin/sh\nset -eu\n" + body)
                command.chmod(0o700)
            environment = {
                "PATH": str(binary_directory) + os.pathsep + os.defpath,
                "HOME": str(home),
                "TMPDIR": str(state),
                "RUNNER_TEMP": str(state),
                "GITHUB_ENV": str(state / "github-env"),
                "GITHUB_PATH": str(state / "github-path"),
            }
            if location == "PYTHONPATH":
                environment["PYTHONPATH"] = str(import_directory)
            result = subprocess.run(
                ["bash", str(source / "scripts/ci/setup-php-container.sh"), "8.4"],
                cwd=working_directory, env=environment, text=True, capture_output=True, timeout=15,
            )
            self.assertFalse(marker.exists(), f"Executed {location}/{module}.py")
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("skipped=1", result.stderr, "The real TLS test module must be imported")
            self.assertIn("SAMPLE_APP_PHP_CI_IMAGE=sha256:", (state / "github-env").read_text())
            self.assertEqual(list(source.rglob("__pycache__")), [])

    def test_selector_ignores_working_directory_imports(self):
        self.check_import_isolation("working-directory")

    def test_selector_ignores_pythonpath_imports(self):
        self.check_import_isolation("PYTHONPATH")

    def test_startup_ignores_pythonpath_site_customization(self):
        self.check_import_isolation("PYTHONPATH", "sitecustomize")

    def test_tls_control_does_not_add_its_script_directory_to_imports(self):
        self.check_import_isolation("TLS-script-directory")


if __name__ == "__main__":
    unittest.main()
