import datetime as dt
import importlib.util
import json
import pathlib
import tempfile
import unittest

DIRECTORY = pathlib.Path(__file__).resolve().parents[2] / "scripts/ci"
SPEC = importlib.util.spec_from_file_location("devcontainer_scan", DIRECTORY / "scan-devcontainer-image.py")
SCAN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SCAN)


class ScanPolicyTests(unittest.TestCase):
    def setUp(self):
        self.policy = json.loads((DIRECTORY / "devcontainer-security.json").read_text())
        self.report = {"SchemaVersion": 2, "Metadata": {"OS": {"Family": "debian", "Name": "12"}},
                       "Results": [{"Type": kind, "Packages": [{"Name": "fixture"}], "Vulnerabilities": []}
                                   for kind in self.policy["required_package_types"]]}
        rule = self.policy["exceptions"][0]
        self.spdx = {"packages": [self.package(rule["purl"], rule["source"]),
                                  self.package(rule["requires_packages"][0], "installed pip") ]}

    def package(self, purl, source):
        return {"name": "fixture", "sourceInfo": source,
                "externalRefs": [{"referenceType": "purl", "referenceLocator": purl}]}

    def finding(self, identifier="fixture-advisory", fixed="2.0", severity="HIGH", purl="pkg:pypi/example@1.0"):
        return {"VulnerabilityID": identifier, "PkgName": "fixture", "InstalledVersion": SCAN.purl_key(purl).rsplit("@", 1)[-1],
                "FixedVersion": fixed, "Severity": severity, "PkgIdentifier": {"PURL": purl}}

    def assess(self, finding):
        self.report["Results"][-1]["Vulnerabilities"] = [finding]
        return SCAN.assess_report(self.report, self.spdx, self.policy)

    def test_actionable_fixed_high_is_blocked(self):
        self.assertEqual(len(self.assess(self.finding())["blocked"]), 1)

    def test_supported_distribution_no_fix_inventory_is_reported(self):
        result = self.assess(self.finding(fixed=None, severity="HIGH"))
        self.assertEqual(result["blocked"], [])
        self.assertEqual(len(result["unfixed"]), 1)

    def test_unknown_unfixed_critical_is_blocked(self):
        self.assertEqual(len(self.assess(self.finding(fixed=None, severity="CRITICAL"))["blocked"]), 1)

    def test_exact_reviewed_unfixed_critical_remains_visible_with_evidence(self):
        for rule in self.policy["reviewed_unfixed_critical"]:
            for source in rule["sources"]:
                with self.subTest(purl=rule["purl"], source=source):
                    self.spdx["packages"] = [self.package(rule["purl"], source)]
                    result = self.assess(self.finding(rule["ids"][0], fixed=None, severity="CRITICAL", purl=rule["purl"]))
                    self.assertEqual(result["blocked"], [])
                    self.assertEqual(result["reviewed_unfixed_critical"][0]["evidence"], rule["evidence"])

    def test_reviewed_critical_blocks_when_a_supported_fix_becomes_available(self):
        rule = self.policy["reviewed_unfixed_critical"][0]
        self.spdx["packages"] = [self.package(rule["purl"], rule["sources"][0])]
        self.assertEqual(len(self.assess(self.finding(rule["ids"][0], severity="CRITICAL", purl=rule["purl"]))["blocked"]), 1)

    def test_reviewed_critical_does_not_cover_a_different_source(self):
        rule = self.policy["reviewed_unfixed_critical"][0]
        self.spdx["packages"] = [self.package(rule["purl"], "different package source")]
        self.assertEqual(len(self.assess(self.finding(rule["ids"][0], fixed=None, severity="CRITICAL", purl=rule["purl"]))["blocked"]), 1)

    def test_reviewed_component_negative_is_scoped_to_exact_source_and_parent(self):
        rule = self.policy["exceptions"][0]
        result = self.assess(self.finding(identifier=rule["ids"][0], purl=rule["purl"]))
        self.assertEqual(result["blocked"], [])
        self.assertEqual(len(result["component_negatives"]), 1)

    def test_same_package_also_installed_outside_reviewed_component_is_blocked(self):
        rule = self.policy["exceptions"][0]
        self.spdx["packages"].append(self.package(rule["purl"], "installed native extension"))
        self.assertEqual(len(self.assess(self.finding(identifier=rule["ids"][0], purl=rule["purl"]))["blocked"]), 1)

    def test_new_parent_version_does_not_inherit_a_waiver(self):
        rule = self.policy["exceptions"][0]
        self.spdx["packages"] = self.spdx["packages"][:1]
        self.assertEqual(len(self.assess(self.finding(identifier=rule["ids"][0], purl=rule["purl"]))["blocked"]), 1)

    def test_missing_inventory_cannot_be_reported_as_clean(self):
        self.report["Results"][0]["Packages"] = []
        with self.assertRaisesRegex(ValueError, "omitted"):
            self.assess(self.finding())

    def test_conflicting_distro_is_refused(self):
        self.report["Metadata"]["OS"]["Name"] = "13"
        with self.assertRaisesRegex(ValueError, "distribution disagrees"):
            self.assess(self.finding())

    def test_database_freshness_requires_version_and_next_update(self):
        now = dt.datetime(2026, 10, 30, 5, tzinfo=dt.timezone.utc)
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / "metadata.json"
            metadata = {"Version": 2, "UpdatedAt": "2026-10-30T01:00:00Z", "NextUpdate": "2026-10-31T01:00:00Z"}
            path.write_text(json.dumps(metadata))
            self.assertEqual(SCAN.database_metadata(path, now), metadata)
            metadata["NextUpdate"] = "2026-10-30T02:00:00Z"
            path.write_text(json.dumps(metadata))
            with self.assertRaisesRegex(ValueError, "expired"):
                SCAN.database_metadata(path, now)


class DebianSourceIdentityTests(unittest.TestCase):
    def setUp(self):
        aliases = [("libaom3", "aom", "3.6.0-1+deb12u3"),
                   ("libmbedcrypto7", "mbedtls", "2.28.3-1"),
                   ("linux-libc-dev", "linux", "6.1.190-1"),
                   ("zlib1g", "zlib", "1:1.2.13.dfsg-1")]
        self.spdx = {"packages": [{"name": name, "versionInfo": version,
            "sourceInfo": "acquired package info from DPKG DB: /var/lib/dpkg/status",
            "externalRefs": [{"referenceType": "purl", "referenceLocator":
                f"pkg:deb/debian/{name}@{version}?arch=amd64&upstream={upstream}"}]}
            for name, upstream, version in aliases]}

    def test_source_aliases_are_preserved_without_mutating_attested_evidence(self):
        original = json.dumps(self.spdx, sort_keys=True)
        converted, mappings = SCAN.scanner_spdx(self.spdx)
        self.assertEqual(json.dumps(self.spdx, sort_keys=True), original)
        for package in converted["packages"]:
            key = SCAN.purl_key(package["externalRefs"][0]["referenceLocator"])
            row = mappings[key]
            self.assertEqual(package["sourceInfo"], f'built package from: {row["source_name"]} {row["source_version"]}')
            self.assertNotEqual(row["name"], row["source_name"])

    def test_explicit_source_version_survives_binary_version_and_epoch_difference(self):
        package = self.spdx["packages"][0]
        package.update(name="cpp", versionInfo="4:12.2.0-3")
        package["externalRefs"][0]["referenceLocator"] = "pkg:deb/debian/cpp@4%3A12.2.0-3?upstream=gcc-defaults%401.203"
        converted, _ = SCAN.scanner_spdx(self.spdx)
        self.assertEqual(converted["packages"][0]["sourceInfo"], "built package from: gcc-defaults 1.203")

    def test_scanner_must_return_each_exact_source_alias_and_version(self):
        _, mappings = SCAN.scanner_spdx(self.spdx)
        packages = [
            {"Name": "libaom3", "Version": "3.6.0-1+deb12u3", "SrcName": "aom", "SrcVersion": "3.6.0", "SrcRelease": "1+deb12u3"},
            {"Name": "libmbedcrypto7", "Version": "2.28.3-1", "SrcName": "mbedtls", "SrcVersion": "2.28.3", "SrcRelease": "1"},
            {"Name": "linux-libc-dev", "Version": "6.1.190-1", "SrcName": "linux", "SrcVersion": "6.1.190", "SrcRelease": "1"},
            {"Name": "zlib1g", "Version": "1:1.2.13.dfsg-1", "SrcName": "zlib", "SrcEpoch": 1, "SrcVersion": "1.2.13.dfsg", "SrcRelease": "1"},
        ]
        report = {"Results": [{"Type": "debian", "Packages": packages}]}
        packages[0]["SrcEpoch"] = None
        SCAN.verify_source_mappings(report, mappings)
        for package in packages:
            with self.subTest(name=package["Name"]):
                source = package.pop("SrcName")
                with self.assertRaisesRegex(ValueError, "lost or changed"):
                    SCAN.verify_source_mappings(report, mappings)
                package["SrcName"] = source
        packages[-1]["SrcEpoch"] = None
        with self.assertRaisesRegex(ValueError, "lost or changed"):
            SCAN.verify_source_mappings(report, mappings)
        packages[-1]["SrcEpoch"] = 1
        packages.pop()
        with self.assertRaisesRegex(ValueError, "omitted"):
            SCAN.verify_source_mappings(report, mappings)

    def test_malformed_or_conflicting_attested_debian_identity_is_refused(self):
        package = self.spdx["packages"][0]
        reference = package["externalRefs"][0]
        original = reference["referenceLocator"]
        for bad in [original + "&upstream=other", original.replace("upstream=aom", "upstream="),
                    original.replace("upstream=aom", "upstream=aom%40"), original.replace("libaom3@", "other@")]:
            with self.subTest(purl=bad):
                reference["referenceLocator"] = bad
                with self.assertRaises(ValueError):
                    SCAN.scanner_spdx(self.spdx)
        package["externalRefs"] = []
        with self.assertRaisesRegex(ValueError, "missing"):
            SCAN.scanner_spdx(self.spdx)

    def test_duplicate_attested_debian_package_is_refused(self):
        self.spdx["packages"].append(dict(self.spdx["packages"][0]))
        with self.assertRaisesRegex(ValueError, "Duplicate"):
            SCAN.scanner_spdx(self.spdx)


if __name__ == "__main__":
    unittest.main()
