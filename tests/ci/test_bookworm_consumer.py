import fnmatch
import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
CONTEXT_SOURCE = ".central-apt-source/docker/apt/require-fixed-bookworm-apt.sh"
GUARD_PATH = "/usr/local/share/sample-app/require-fixed-bookworm-apt.sh"
GUARD = "/bin/sh " + GUARD_PATH
PARENT = "php:8.4.27-cli-bookworm@sha256:b3e907a1108629c562c4bb313ce3449e34d49991f6bb2487913f6146f47200fe"


def instructions(source):
    pending = ""
    for line in source.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if line.endswith("\\"):
            pending += line[:-1] + " "
            continue
        instruction = pending + line
        pending = ""
        operation, _, argument = instruction.partition(" ")
        yield operation, argument.strip()


def php_stages(source):
    stages = []
    current = None
    for operation, argument in instructions(source):
        if operation == "FROM":
            current = [] if argument.startswith("php:") else None
            if current is not None:
                assert argument.split()[0] == PARENT, "Unexpected PHP parent"
                stages.append(current)
        elif current is not None:
            current.append((operation, argument))
    assert len(stages) == 2, "Both PHP stages must use the accepted parent"
    return stages


def protected_php_stages(source):
    for stage in php_stages(source):
        copy = stage.index(("COPY", CONTEXT_SOURCE + " " + GUARD_PATH))
        first_run = next(index for index, (operation, _) in enumerate(stage) if operation == "RUN")
        assert copy < first_run, "The central local-context COPY must precede the first RUN"
        assert stage[first_run][1].startswith(GUARD), "The first RUN must start with the central preflight"
        for operation, argument in stage:
            if operation != "RUN":
                continue
            for boundary in re.finditer(r"\bapt-get\s+(?:update|upgrade|install|purge)\b|\bplaywright\s+install\b[^;]*--with-deps", argument):
                assert argument.rfind(GUARD, 0, boundary.start()) >= 0, "APT boundary has no preceding preflight in its RUN"


class BookwormConsumerTests(unittest.TestCase):
    def setUp(self):
        self.source = (ROOT / ".devcontainer/docker/Dockerfile").read_text()

    def test_both_php_stages_guard_their_apt_boundaries(self):
        protected_php_stages(self.source)

    def test_an_early_brotli_install_cannot_precede_the_guard(self):
        continuation = " " + chr(92) + chr(10) + "    "
        bad = self.source.replace("RUN " + GUARD + continuation + "&& ", "RUN ", 1)
        self.assertTrue(self.source != bad, "The early-guard mutation must change the source")
        with self.assertRaisesRegex(AssertionError, "first RUN"):
            protected_php_stages(bad)

    def test_later_apt_and_playwright_guards_cannot_be_removed(self):
        continuation = " " + chr(92) + chr(10) + "    "
        for following in ["apt-get update", "PLAYWRIGHT_DOWNLOAD_CONNECTION_TIMEOUT=120000 playwright install"]:
            with self.subTest(boundary=following):
                bad = self.source.replace(GUARD + ";" + continuation + following, following, 1)
                self.assertTrue(self.source != bad, "The later-guard mutation must change the source")
                with self.assertRaisesRegex(AssertionError, "APT boundary"):
                    protected_php_stages(bad)

    def test_missing_central_context_has_no_external_copy_fallback(self):
        bad = self.source.replace("COPY " + CONTEXT_SOURCE, "COPY --from=unreviewed-guard " + CONTEXT_SOURCE, 1)
        with self.assertRaises(ValueError):
            protected_php_stages(bad)

    def test_the_guard_is_in_the_build_context_but_git_metadata_is_not(self):
        patterns = [line.strip().rstrip("/") for line in (ROOT / ".dockerignore").read_text().splitlines() if line.strip() and not line.startswith("#")]
        ancestors = [str(path) for path in pathlib.PurePosixPath(CONTEXT_SOURCE).parents]
        for pattern in patterns:
            self.assertFalse(any(fnmatch.fnmatchcase(path, pattern) for path in [CONTEXT_SOURCE, *ancestors]), pattern)
        self.assertIn(".central-apt-source/.git", patterns)
        self.assertIn("/.central-apt-source/", (ROOT / ".gitignore").read_text().splitlines())

    def test_completed_image_qualification_rechecks_the_installed_pair(self):
        qualification = (ROOT / "scripts/ci/qualify-devcontainer-image.sh").read_text()
        invocation = '"${compose[@]}" exec -T --user root laravel ' + GUARD
        self.assertIn(invocation, qualification)
        self.assertGreater(qualification.index(invocation), qualification.index("run_in_ready_devcontainer laravel .devcontainer/post-create.sh"))


if __name__ == "__main__":
    unittest.main()
