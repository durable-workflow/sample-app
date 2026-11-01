import base64
import hashlib
import importlib.util
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

MODULE = pathlib.Path(__file__).resolve().parents[2] / "scripts/ci/verify-devcontainer-attestations.py"
SPEC = importlib.util.spec_from_file_location("devcontainer_attestations", MODULE)
VERIFIER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(VERIFIER)

IMAGE = "sha256:" + "1" * 64
BASE = "sha256:" + "2" * 64
REVISION = "3" * 40


def bundle(platform="linux/amd64", change=None):
    architecture = platform.split("/")[1]
    spdx = {"spdxVersion": "SPDX-2.3", "packages": [{
        "name": "base-files", "versionInfo": "12.4+deb12u15",
        "externalRefs": [{"referenceType": "purl", "referenceLocator":
                          f"pkg:deb/debian/base-files@12.4%2Bdeb12u15?arch={architecture}&distro=debian-12.15"}],
    }]}
    metadata = {"vcs": {"source": "https://github.com/durable-workflow/sample-app",
                        "revision": REVISION, "localdir:dockerfile": ".devcontainer/docker"},
                "source": {"infos": [{"filename": "Dockerfile", "language": "Dockerfile",
                                      "data": base64.b64encode(f"FROM php:8.4.27-cli-bookworm@{BASE}\n".encode()).decode()}]}}
    provenance = {"runDetails": {"metadata": {"buildkit_metadata": metadata}},
                  "buildDefinition": {"resolvedDependencies": [
                      {"uri": f"pkg:docker/php@8.4.27-cli-bookworm?digest={BASE}&platform={platform}",
                       "digest": {"sha256": BASE[7:]}}]}}

    def statement(kind, predicate):
        return {"_type": "https://in-toto.io/Statement/v0.1", "predicateType": kind,
                "subject": [{"name": "fixture", "digest": {"sha256": IMAGE[7:]}}], "predicate": predicate}

    statements = {VERIFIER.SPDX: statement(VERIFIER.SPDX, spdx),
                  VERIFIER.SLSA: statement(VERIFIER.SLSA, provenance)}
    if change:
        change(statements)
    blobs = {}

    def add(value, media_type):
        raw = json.dumps(value).encode()
        digest = "sha256:" + hashlib.sha256(raw).hexdigest()
        blobs[digest] = raw
        return {"digest": digest, "size": len(raw), "mediaType": media_type}

    layers = []
    for kind, value in statements.items():
        descriptor = add(value, "application/vnd.in-toto+json")
        descriptor["annotations"] = {"in-toto.io/predicate-type": kind}
        layers.append(descriptor)
    attestation = add({"schemaVersion": 2, "layers": layers}, "application/vnd.oci.image.manifest.v1+json")
    attestation["annotations"] = {"vnd.docker.reference.type": "attestation-manifest",
                                  "vnd.docker.reference.digest": IMAGE}
    index = {"schemaVersion": 2, "manifests": [
        {"digest": IMAGE, "platform": {"os": "linux", "architecture": architecture}}, attestation]}
    raw = json.dumps(index).encode()
    return raw, "sha256:" + hashlib.sha256(raw).hexdigest(), blobs


class AttestationTests(unittest.TestCase):
    def verify(self, change=None, platform="linux/amd64", revision=REVISION):
        raw, digest, blobs = bundle(platform, change)
        return VERIFIER.verify_platform(raw, digest, platform, blobs.__getitem__, revision)

    def test_both_platforms_require_and_return_their_exact_image_identity(self):
        for platform in ("linux/amd64", "linux/arm64"):
            with self.subTest(platform=platform):
                spdx, identity = self.verify(platform=platform)
                self.assertTrue(spdx["packages"])
                self.assertEqual(identity["image_digest"], IMAGE)
                self.assertEqual(identity["platform"], platform)
                self.assertEqual(identity["distro"], "debian/12")

    def test_cli_emits_verified_data_without_writing_files_and_refuses_invalid_digest(self):
        raw, digest, blobs = bundle()
        with tempfile.TemporaryDirectory() as temporary:
            directory = pathlib.Path(temporary)
            (directory / "index.json").write_bytes(raw)
            for key, data in blobs.items():
                (directory / (key[7:] + ".json")).write_bytes(data)
            before = {path.name: path.read_bytes() for path in directory.iterdir()}
            command = [sys.executable, str(MODULE), str(directory)]
            result = subprocess.run(command + [digest, "linux/amd64", "--revision", REVISION],
                                    capture_output=True, text=True, check=True)
            payload = json.loads(result.stdout)
            self.assertEqual(set(payload), {"identity", "spdx"})
            self.assertEqual(payload["identity"]["image_digest"], IMAGE)
            self.assertEqual(payload["identity"]["revision"], REVISION)
            self.assertEqual(payload["spdx"]["packages"][0]["name"], "base-files")
            refused = subprocess.run(command + ["sha256:" + "0" * 64, "linux/amd64"],
                                     capture_output=True, text=True)
            self.assertEqual(refused.returncode, 1)
            self.assertEqual(refused.stdout, "")
            self.assertIn("digest mismatch", refused.stderr)
            self.assertEqual({path.name: path.read_bytes() for path in directory.iterdir()}, before)

    def test_wrong_index_digest_is_refused(self):
        raw, _, blobs = bundle()
        with self.assertRaisesRegex(ValueError, "digest mismatch"):
            VERIFIER.verify_platform(raw, "sha256:" + "0" * 64, "linux/amd64", blobs.__getitem__)

    def test_corrupted_attestation_blob_is_refused(self):
        raw, digest, blobs = bundle()
        key = next(iter(blobs))
        blobs[key] += b" "
        with self.assertRaisesRegex(ValueError, "digest mismatch"):
            VERIFIER.verify_platform(raw, digest, "linux/amd64", blobs.__getitem__)

    def test_each_statement_must_name_the_exact_platform_subject(self):
        for kind in (VERIFIER.SPDX, VERIFIER.SLSA):
            with self.subTest(kind=kind), self.assertRaisesRegex(ValueError, "exact platform image"):
                self.verify(lambda values: values[kind]["subject"][0]["digest"].update(sha256="0" * 64))

    def test_missing_either_statement_is_refused(self):
        for kind in (VERIFIER.SPDX, VERIFIER.SLSA):
            with self.subTest(kind=kind), self.assertRaisesRegex(ValueError, "Expected one"):
                self.verify(lambda values: values.pop(kind))

    def test_source_revision_mismatch_is_refused(self):
        with self.assertRaisesRegex(ValueError, "Unexpected source revision"):
            self.verify(revision="4" * 40)

    def test_other_source_repository_is_refused(self):
        def change(values):
            values[VERIFIER.SLSA]["predicate"]["runDetails"]["metadata"]["buildkit_metadata"]["vcs"]["source"] = "https://example.invalid/source"
        with self.assertRaisesRegex(ValueError, "source repository"):
            self.verify(change)

    def test_mutable_or_other_distribution_base_is_refused(self):
        for base in ("php:8.4.27-cli-bookworm", "php:8.4.27-cli-trixie@" + BASE):
            def change(values):
                info = values[VERIFIER.SLSA]["predicate"]["runDetails"]["metadata"]["buildkit_metadata"]["source"]["infos"][0]
                info["data"] = base64.b64encode(f"FROM {base}\n".encode()).decode()
            with self.subTest(base=base), self.assertRaisesRegex(ValueError, "immutable PHP Bookworm"):
                self.verify(change)

    def test_base_without_matching_resolved_digest_is_refused(self):
        def change(values):
            values[VERIFIER.SLSA]["predicate"]["buildDefinition"]["resolvedDependencies"][0]["digest"]["sha256"] = "0" * 64
        with self.assertRaisesRegex(ValueError, "resolved platform dependencies"):
            self.verify(change)

    def test_empty_inventory_is_refused(self):
        def change(values):
            values[VERIFIER.SPDX]["predicate"]["packages"] = []
        with self.assertRaisesRegex(ValueError, "Empty package inventory"):
            self.verify(change)

    def test_source_tag_alone_cannot_override_a_conflicting_package_distro(self):
        def change(values):
            package = values[VERIFIER.SPDX]["predicate"]["packages"][0]
            package["externalRefs"][0]["referenceLocator"] = "pkg:deb/debian/base-files@13.1?distro=debian-13"
        with self.assertRaisesRegex(ValueError, "SPDX does not confirm Debian 12"):
            self.verify(change)


if __name__ == "__main__":
    unittest.main()
