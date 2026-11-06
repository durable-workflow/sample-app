import pathlib
import re
import shlex
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
DOWNLOADS = {
    "docker/install-phpredis.sh": "$PHPREDIS_SOURCE_URL",
    ".devcontainer/docker/Dockerfile": "https://durable-workflow.com/install.sh",
    "polyglot/python_worker/Dockerfile": "https://durable-workflow.com/install.sh",
}


def download_commands(source, url):
    logical = source.replace("\\\n", " ")
    commands = [
        shlex.split(re.split(r"&&|;|\n", logical[match.start():], maxsplit=1)[0])
        for match in re.finditer(r"\bcurl\s+(?=-)", logical)
    ]
    return [arguments for arguments in commands if url in arguments]


def require_https_downloads(source, url):
    commands = download_commands(source, url)
    assert commands, "Expected an actual curl download command"
    for arguments in commands:
        for option in ("--proto", "--proto-redir"):
            assert option in arguments, "Missing HTTPS protocol restriction"
            position = arguments.index(option)
            assert arguments[position + 1] == "=https", "Download permits another protocol"


def require_binary_yaml(source):
    commands = re.findall(r"^\s*- run: (.+PyYAML==[^\n]+)", source, re.MULTILINE)
    assert commands, "Expected the action-policy YAML dependency installation"
    for command in commands:
        if command[0] in ("'", '"') and command[-1] == command[0]:
            command = command[1:-1]
        assert "--only-binary=:all:" in shlex.split(command), "YAML installation allows source builds"


def require_nonroot_production(source):
    final = re.split(r"^FROM ", source, flags=re.MULTILINE)[-1]
    users = re.findall(r"^USER (\S+)", final, re.MULTILINE)
    assert users and users[-1].split(":")[0] not in ("root", "0"), "Production runtime runs as root"


class SourceSecurityBoundaryTests(unittest.TestCase):
    def test_every_reported_installer_restricts_initial_and_redirect_protocols(self):
        for path, url in DOWNLOADS.items():
            with self.subTest(path=path):
                require_https_downloads((ROOT / path).read_text(), url)

    def test_either_download_protocol_restriction_cannot_be_removed(self):
        for path, url in DOWNLOADS.items():
            source = (ROOT / path).read_text()
            for option in ("--proto", "--proto-redir"):
                with self.subTest(path=path, option=option):
                    arguments = download_commands(source, url)[0]
                    changed = list(arguments)
                    position = changed.index(option)
                    del changed[position:position + 2]
                    self.assertNotEqual(arguments, changed)
                    with self.assertRaisesRegex(AssertionError, "HTTPS protocol"):
                        require_https_downloads(shlex.join(changed), url)

    def test_action_policy_yaml_installation_does_not_execute_source_builds(self):
        require_binary_yaml((ROOT / ".github/workflows/ci.yml").read_text())

    def test_binary_only_yaml_policy_cannot_be_removed(self):
        source = (ROOT / ".github/workflows/ci.yml").read_text()
        changed = source.replace("--only-binary=:all:", "", 1)
        self.assertNotEqual(source, changed)
        with self.assertRaisesRegex(AssertionError, "source builds"):
            require_binary_yaml(changed)

    def test_production_entrypoint_inherits_a_nonroot_user(self):
        require_nonroot_production((ROOT / "Dockerfile").read_text())

    def test_production_user_cannot_be_removed_or_overridden_with_root(self):
        source = (ROOT / "Dockerfile").read_text()
        for changed in (re.sub(r"^USER .*\n", "", source, flags=re.MULTILINE), source + "\nUSER root\n"):
            with self.subTest(changed=changed[-40:]):
                with self.assertRaisesRegex(AssertionError, "runs as root"):
                    require_nonroot_production(changed)

    def test_nonroot_browser_and_application_state_have_writable_locations(self):
        source = (ROOT / "Dockerfile").read_text()
        final = re.split(r"^FROM ", source, flags=re.MULTILINE)[-1]
        self.assertIn("HOME=/home/www-data", final)
        self.assertIn("PLAYWRIGHT_BROWSERS_PATH=/ms-playwright", final)
        self.assertIn('mkdir -p /app/videos "$HOME" "$PLAYWRIGHT_BROWSERS_PATH"', final)
        self.assertRegex(final, r'chown -R www-data:www-data /app "\$HOME" "\$PLAYWRIGHT_BROWSERS_PATH"')
        self.assertLess(final.index("PLAYWRIGHT_BROWSERS_PATH="), final.index("playwright install"))
        self.assertLess(final.index("chown -R"), final.index("USER www-data"))


if __name__ == "__main__":
    unittest.main()
