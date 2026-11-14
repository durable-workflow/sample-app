import contextlib
import os
import pathlib
import shlex
import shutil
import subprocess
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]


@unittest.skipUnless(os.environ.get("SAMPLE_APP_PHP_CI_TEST_IMAGE"), "requires the built PHP CI image")
class PhpContainerIsolationTests(unittest.TestCase):
    @contextlib.contextmanager
    def fixture(self):
        with tempfile.TemporaryDirectory() as directory:
            base = pathlib.Path(directory)
            root, state = base / "repository", base / "state"
            for path in (
                root / "scripts/ci", root / "public", root / "microservice/public",
                root / "vendor", root / "storage", root / "bootstrap/cache",
                root / ".phpunit.cache",
                state / "bin", state / "tmp", state / "composer-home/cache",
                state / "source/php", state / "source/python", state / "source/rust",
            ):
                path.mkdir(parents=True, exist_ok=True)
            launcher = root / "scripts/ci/run-php-container.sh"
            shutil.copy2(ROOT / "scripts/ci/run-php-container.sh", launcher)
            (root / "public/index.php").write_text("<?php // immutable source\n")
            (root / "microservice/public/index.php").write_text("<?php // immutable source\n")
            for name in ("php", "composer"):
                (state / "bin" / name).symlink_to(launcher)
            subprocess.run(["git", "init", "--quiet", str(root)], check=True,
                           stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
            environment = {
                "PATH": os.defpath,
                "HOME": str(state / "composer-home"),
                "DOCKER_CONFIG": str(state / "docker"),
                "TMPDIR": str(state / "tmp"),
                "SAMPLE_APP_PHP_CI_ROOT": str(root),
                "SAMPLE_APP_PHP_CI_STATE": str(state),
                "SAMPLE_APP_PHP_CI_IMAGE": os.environ["SAMPLE_APP_PHP_CI_TEST_IMAGE"],
                "COMPOSER_HOME": str(state / "composer-home"),
                "COMPOSER_CACHE_DIR": str(state / "composer-home/cache"),
            }
            (state / "docker").mkdir()
            (state / "docker/config.json").write_text('{}\n')
            yield root, state, launcher, environment

    def invoke(self, root, state, environment, *arguments, command="php"):
        return subprocess.run([str(state / "bin" / command), *map(str, arguments)],
                              cwd=root, env=environment, text=True, capture_output=True, timeout=30)

    def test_overwrite_and_rename_cannot_substitute_the_next_host_verifier(self):
        for target_name in ("launcher", "php", "composer"):
            for operation in ("overwrite", "rename"):
                with self.subTest(target=target_name, operation=operation), self.fixture() as fixture:
                    root, state, launcher, environment = fixture
                    target = launcher if target_name == "launcher" else state / "bin" / target_name
                    marker = state / "substituted-verifier"
                    payload = "#!/bin/sh\nprintf substituted > " + shlex.quote(str(marker)) + "\nexit 0\n"
                    code = (
                        '$target=$argv[1]; $payload=$argv[2]; '
                        'if ($argv[3] === "overwrite") { '
                        '  exit(@file_put_contents($target,$payload) === false ? 73 : 0); '
                        '} '
                        '$temporary=$target.".replacement"; '
                        'if (@file_put_contents($temporary,$payload) === false) { exit(73); } '
                        'chmod($temporary,0755); '
                        'if (!@rename($temporary,$target)) { @unlink($temporary); exit(73); }'
                    )
                    attack = self.invoke(root, state, environment, "-r", code, target, payload, operation)
                    genuine = self.invoke(root, state, environment, "-r", 'echo "genuine-php";')
                    self.assertFalse(marker.exists(), "Package code substituted the next host verifier")
                    self.assertNotEqual(attack.returncode, 0, "The protected path accepted a write")
                    self.assertEqual(genuine.returncode, 0, genuine.stderr)
                    self.assertEqual(genuine.stdout, "genuine-php")
                    composer = self.invoke(root, state, environment, "--version", command="composer")
                    self.assertEqual(composer.returncode, 0, composer.stderr)
                    self.assertIn("Composer version 2.10.3", composer.stdout)

    def test_required_data_writes_remain_available(self):
        with self.fixture() as (root, state, _launcher, environment):
            paths = [root / "vendor/result", root / "storage/result", root / "bootstrap/cache/result",
                     root / ".phpunit.cache/result", root / ".env", state / "tmp/result",
                     state / "composer-home/cache/result", state / "source/php/Scenario.php"]
            result = self.invoke(root, state, environment, "-r",
                                 'foreach(array_slice($argv,1) as $path) { '
                                 'if (file_put_contents($path,"allowed-data") === false) { exit(1); }}',
                                 *paths)
            self.assertEqual(result.returncode, 0, result.stderr)
            for path in paths:
                self.assertEqual(path.read_text(), "allowed-data")

    def test_public_output_does_not_change_host_checkout(self):
        with self.fixture() as (root, state, _launcher, environment):
            result = self.invoke(root, state, environment, "-r",
                                 'exit(file_put_contents($argv[1],"temporary hot output") === false ? 1 : 0);',
                                 root / "public/hot")
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertFalse((root / "public/hot").exists())
            self.assertEqual((state / "data/public-root/hot").read_text(), "temporary hot output")

    def test_php_cannot_plant_host_python_or_rust_source(self):
        for relative in ("source/python/main.py", "source/rust/build.rs"):
            with self.subTest(relative=relative), self.fixture() as (root, state, _launcher, environment):
                path = state / relative
                result = self.invoke(root, state, environment, "-r",
                                     'exit(@file_put_contents($argv[1],"marker") === false ? 73 : 0);', path)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(path.exists())

    def test_symlinks_cannot_turn_data_mounts_into_writable_controls(self):
        for relative in ("vendor", "php-source"):
            with self.subTest(relative=relative), self.fixture() as (root, state, _launcher, environment):
                path = root / "vendor" if relative == "vendor" else state / "source/php"
                path.rmdir()
                path.symlink_to(root / "scripts/ci", target_is_directory=True)
                result = self.invoke(root, state, environment, "-r",
                                     'file_put_contents($argv[1],"marker");', path / "planted")
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("canonical", result.stderr)
                self.assertFalse((root / "scripts/ci/planted").exists())


if __name__ == "__main__":
    unittest.main()
