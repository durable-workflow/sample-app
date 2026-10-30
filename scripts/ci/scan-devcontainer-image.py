#!/usr/bin/env python3
"""Verify both public registries and scan both exact platform SBOMs before promotion."""

import argparse
import copy
import datetime as dt
import hashlib
import importlib.util
import json
import os
import pathlib
import re
import subprocess
import sys
import tempfile
import tomllib
import uuid
from urllib.parse import parse_qsl, unquote

sys.dont_write_bytecode = True
DIRECTORY = pathlib.Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("attestations", DIRECTORY / "verify-devcontainer-attestations.py")
ATTESTATIONS = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ATTESTATIONS)
REPOSITORIES = {"ghcr.io/durable-workflow/sample-app-devcontainer", "docker.io/durableworkflow/sample-app-devcontainer"}


def purl_key(value):
    return unquote(value.split("?", 1)[0].split("#", 1)[0])


def expected_cargo_inventory():
    lock_path = DIRECTORY.parents[1] / "playground/templates/rust/Cargo.lock"
    raw = lock_path.read_bytes()
    packages = tomllib.loads(raw.decode())["package"]
    return {"pkg:cargo/" + package["name"] + "@" + package["version"]
            for package in packages if package.get("source")}, hashlib.sha256(raw).hexdigest()


def verify_cargo_inventory(spdx, expected, lock_digest, policy):
    if policy["sbom_creator"] not in spdx.get("creationInfo", {}).get("creators", []):
        raise ValueError("Platform SBOM does not identify the pinned generator version")
    files = [file for file in spdx.get("files", [])
             if file.get("fileName", "").removeprefix("./").removeprefix("/") == policy["cargo_lock_path"]]
    if len(files) != 1:
        raise ValueError("SBOM must identify exactly one retained Cargo.lock file")
    hashes = [checksum["checksumValue"] for checksum in files[0].get("checksums", [])
              if checksum.get("algorithm") == "SHA256"]
    if hashes != [lock_digest]:
        raise ValueError("Attested Cargo.lock checksum disagrees with the source lock digest")
    seen = set()
    for package in spdx["packages"]:
        for reference in package.get("externalRefs", []):
            if reference.get("referenceType") != "purl" or not reference["referenceLocator"].startswith("pkg:cargo/"):
                continue
            key = purl_key(reference["referenceLocator"])
            if key in seen or package.get("sourceInfo") != policy["cargo_source"]:
                raise ValueError("Cargo inventory contains duplicate packages or an unreviewed source graph")
            name, version = key.removeprefix("pkg:cargo/").rsplit("@", 1)
            if name != package.get("name") or version != package.get("versionInfo"):
                raise ValueError("Cargo package identity disagrees with its attested PURL")
            seen.add(key)
    if not expected or seen != expected:
        raise ValueError("Platform SBOM omitted or changed the authoritative Cargo lock inventory")
    return len(seen)


def scanner_spdx(spdx):
    """Translate attested Debian source qualifiers into Trivy's SPDX dialect.

    Trivy 0.76.1 ignores Syft's upstream PURL qualifier. Its SPDX decoder reads
    source package identity from `built package from: NAME VERSION` instead.
    Keep the verified original untouched for policy/evidence and check every
    translated identity against the scanner's returned package inventory.
    """
    converted = copy.deepcopy(spdx)
    mappings = {}
    for package in converted["packages"]:
        references = [ref["referenceLocator"] for ref in package.get("externalRefs", [])
                      if ref.get("referenceType") == "purl" and ref["referenceLocator"].startswith("pkg:deb/debian/")]
        if not references:
            if package.get("sourceInfo", "").startswith("acquired package info from DPKG DB:"):
                raise ValueError("Debian package is missing its attested PURL")
            continue
        if len(references) != 1:
            raise ValueError("Ambiguous Debian package identity")
        purl = references[0]
        key = purl_key(purl)
        name, version = key.removeprefix("pkg:deb/debian/").rsplit("@", 1)
        if name != package["name"] or version != package["versionInfo"]:
            raise ValueError("Debian PURL disagrees with the attested package")
        qualifiers = parse_qsl(purl.partition("?")[2].split("#", 1)[0], keep_blank_values=True)
        if len(dict(qualifiers)) != len(qualifiers):
            raise ValueError("Duplicate Debian PURL qualifiers")
        upstream = dict(qualifiers).get("upstream", name)
        source_name, separator, source_version = upstream.partition("@")
        if not separator:
            source_version = version
        if not re.fullmatch(r"[a-z0-9][a-z0-9+.-]*", source_name) or not re.fullmatch(r"[0-9][A-Za-z0-9.+:~-]*", source_version):
            raise ValueError("Invalid Debian source package identity")
        mapping = {"name": name, "version": version, "source_name": source_name, "source_version": source_version}
        if key in mappings:
            raise ValueError("Duplicate or conflicting Debian source package identities")
        mappings[key] = mapping
        package["sourceInfo"] = f"built package from: {source_name} {source_version}"
    if not mappings:
        raise ValueError("Attested SBOM has no Debian source inventory")
    return converted, mappings


def verify_source_mappings(report, mappings):
    expected = {}
    for row in mappings.values():
        version = row["source_version"]
        epoch, separator, remainder = version.partition(":")
        epoch, version = (int(epoch), remainder) if separator else (0, version)
        upstream, separator, release = version.rpartition("-")
        upstream, release = (upstream, release) if separator else (version, "")
        expected[(row["name"], row["version"])] = (row["source_name"], epoch, upstream, release)
    seen = set()
    for result in report.get("Results", []):
        if result.get("Type") != "debian":
            continue
        for package in result.get("Packages", []):
            key = (package["Name"], package["Version"])
            identity = (package.get("SrcName"), package.get("SrcEpoch") or 0,
                        package.get("SrcVersion"), package.get("SrcRelease") or "")
            if key not in expected or identity != expected[key]:
                raise ValueError("Scanner lost or changed an attested Debian source identity")
            seen.add(key)
    if seen != set(expected):
        raise ValueError("Scanner omitted an attested Debian package")


def assess_report(report, spdx, policy):
    if report.get("SchemaVersion") != 2 or report.get("Metadata", {}).get("OS", {}).get("Family") != "debian":
        raise ValueError("Scanner did not identify the source-proven Debian inventory")
    if report["Metadata"]["OS"].get("Name", "").split(".")[0] != "12":
        raise ValueError("Scanner distribution disagrees with the attested source")
    package_counts = {result.get("Type"): len(result.get("Packages", [])) for result in report.get("Results", [])}
    if any(not package_counts.get(kind) for kind in policy["required_package_types"]):
        raise ValueError("Scanner omitted a required package inventory")
    components = {}
    for package in spdx["packages"]:
        for reference in package.get("externalRefs", []):
            if reference.get("referenceType") == "purl":
                components.setdefault(purl_key(reference["referenceLocator"]), []).append(package)
    blocked, exempted, reviewed_critical, unfixed = [], [], [], []
    for result in report["Results"]:
        for finding in result.get("Vulnerabilities", []):
            if finding["Severity"] not in ("HIGH", "CRITICAL"):
                continue
            key = purl_key(finding.get("PkgIdentifier", {}).get("PURL", ""))
            row = {"id": finding["VulnerabilityID"], "package": finding["PkgName"],
                   "version": finding["InstalledVersion"], "fixed": finding.get("FixedVersion") or "",
                   "severity": finding["Severity"], "purl": key}
            matches = components.get(key, [])
            exact_version = key.rsplit("@", 1)[-1] == row["version"]
            waiver = next((rule for rule in policy["exceptions"]
                           if row["id"] in rule["ids"] and key == rule["purl"]
                           and any(parent in components for parent in rule["requires_packages"]) and matches
                           and exact_version and all(package.get("sourceInfo") in rule["sources"] for package in matches)), None)
            residual = next((rule for rule in policy["reviewed_unfixed_critical"]
                             if row["id"] in rule["ids"] and key == rule["purl"]
                             and rule["distribution"] == "debian/12" and matches and exact_version
                             and all(package.get("sourceInfo") in rule["sources"] for package in matches)), None)
            if waiver:
                exempted.append(dict(row, reason=waiver["reason"], evidence=waiver["evidence"],
                                     vendor_evidence=waiver.get("vendor_evidence", [])))
            elif row["severity"] == "CRITICAL" and not row["fixed"] and residual:
                reviewed_critical.append(dict(row, reason=residual["reason"], evidence=residual["evidence"],
                                              vendor_evidence=residual["vendor_evidence"]))
            elif row["fixed"] or row["severity"] == "CRITICAL":
                blocked.append(row)
            else:
                unfixed.append(row)
    return {"packages": package_counts, "blocked": blocked, "component_negatives": exempted,
            "reviewed_unfixed_critical": reviewed_critical, "unfixed": unfixed}


def database_metadata(path, now=None):
    metadata = json.loads(path.read_text())
    now = now or dt.datetime.now(dt.timezone.utc)
    updated = dt.datetime.fromisoformat(metadata["UpdatedAt"].replace("Z", "+00:00"))
    next_update = dt.datetime.fromisoformat(metadata["NextUpdate"].replace("Z", "+00:00"))
    if metadata["Version"] != 2 or not -300 <= (now - updated).total_seconds() <= 86400 or next_update <= now:
        raise ValueError("Vulnerability DB is expired, incompatible, or has an invalid timestamp")
    return metadata


def container(image, arguments, mounts, timeout, entrypoint=None, network="bridge"):
    if os.getuid() == 0:
        raise ValueError("Run registry/scanner tooling as a non-root user")
    name = "sample-devcontainer-scan-" + uuid.uuid4().hex
    command = ["docker", "run", "--rm", "--name", name, "--network", network, "--user", f"{os.getuid()}:{os.getgid()}",
               "--label", "durable.task=" + name]
    for source, target, readonly in mounts:
        command += ["--mount", f"type=bind,src={source},dst={target}" + (",readonly" if readonly else "")]
    if entrypoint:
        command += ["--entrypoint", entrypoint]
    try:
        result = subprocess.run(command + [image] + arguments, capture_output=True, timeout=timeout)
        if result.returncode:
            raise RuntimeError(f"{image} failed: {result.stderr.decode(errors='replace')[-1500:]}")
        return result.stdout
    finally:
        # Also removes a container left running when its Docker client times out.
        subprocess.run(["docker", "rm", "--force", name], capture_output=True, timeout=30)


def registry_reference(value):
    if value.startswith("durableworkflow/"):
        value = "docker.io/" + value
    repository = value.split("@", 1)[0] if "@" in value else value.rsplit(":", 1)[0]
    if repository not in REPOSITORIES:
        raise ValueError("Expected a public Sample App devcontainer registry reference")
    return repository, value


def scan(images, output, policy, expected_revision=None, cache_directory=None, tool_network="bridge"):
    output.mkdir(parents=True, exist_ok=True)
    cache = (cache_directory or output / "cache").resolve()
    cache.mkdir(parents=True, exist_ok=True)
    identities = []
    with tempfile.TemporaryDirectory(prefix="attestations-", dir=output) as temporary:
        work = pathlib.Path(temporary)
        anonymous = work / "anonymous.json"
        anonymous.write_text('{"auths":{}}\n')

        def oras(arguments):
            return container(policy["oras_image"], arguments + ["--registry-config", "/anonymous.json"],
                             [(anonymous, "/anonymous.json", True)], 180, "oras", tool_network)

        registries = [registry_reference(image) for image in images]
        if {repository for repository, _ in registries} != REPOSITORIES or len(registries) != 2:
            raise ValueError("Both distinct public registries must be checked anonymously")
        resolved = [json.loads(oras(["manifest", "fetch", "--descriptor", reference])) for _, reference in registries]
        if resolved[0]["digest"] != resolved[1]["digest"]:
            raise ValueError("Public registry index digests disagree")
        index_digest = resolved[0]["digest"]
        if not re.fullmatch(r"sha256:[a-f0-9]{64}", index_digest):
            raise ValueError("Registry did not resolve an immutable SHA-256 index")
        platform_inputs = {}
        for repository, _ in registries:
            def fetch(digest, kind="blob"):
                raw = oras([kind, "fetch", "--output", "-", repository + "@" + digest])
                if "sha256:" + hashlib.sha256(raw).hexdigest() != digest:
                    raise ValueError("Registry content digest mismatch")
                return raw
            index_raw = fetch(index_digest, "manifest")
            index = ATTESTATIONS.verified_json(index_raw, {"digest": index_digest})
            manifests = {}
            for entry in index["manifests"]:
                if entry.get("annotations", {}).get("vnd.docker.reference.type") == "attestation-manifest":
                    manifests[entry["digest"]] = fetch(entry["digest"], "manifest")
            for platform in ("linux/amd64", "linux/arm64"):
                spdx, identity = ATTESTATIONS.verify_platform(index_raw, index_digest, platform,
                    lambda digest: manifests[digest] if digest in manifests else fetch(digest), expected_revision)
                key = platform.split("/")[1]
                if key in platform_inputs and platform_inputs[key] != spdx:
                    raise ValueError("Public platform SBOMs disagree")
                platform_inputs[key] = spdx
                identities.append(dict(identity, repository=repository))

        summaries = []
        expected_cargo, cargo_lock_digest = expected_cargo_inventory()
        for architecture, spdx in platform_inputs.items():
            cargo_packages = verify_cargo_inventory(spdx, expected_cargo, cargo_lock_digest, policy)
            sbom = work / f"{architecture}.spdx.json"
            converted, mappings = scanner_spdx(spdx)
            sbom.write_text(json.dumps(converted))
            raw = container(policy["trivy_image"], ["sbom", "--cache-dir", "/cache", "--timeout", "5m",
                "--db-repository", policy["database_repository"], "--list-all-pkgs", "--scanners", "vuln",
                "--ignorefile", "/dev/null", "--severity", "HIGH,CRITICAL", "--distro", "debian/12",
                "--format", "json", "/input.spdx.json"], [(cache, "/cache", False), (sbom, "/input.spdx.json", True)], 360,
                network=tool_network)
            report = json.loads(raw)
            (output / f"{architecture}.trivy.json").write_bytes(raw)
            verify_source_mappings(report, mappings)
            database = database_metadata(cache / "db/metadata.json")
            assessment = assess_report(report, spdx, policy)
            summaries.append(dict(assessment, platform="linux/" + architecture, database=database,
                                  debian_source_mappings=mappings, cargo_packages=cargo_packages,
                                  cargo_lock_sha256=cargo_lock_digest))
        summary = {"index_digest": index_digest, "scanner": policy["trivy_image"],
                   "identities": identities, "platforms": summaries}
        (output / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
        print(json.dumps(summary, sort_keys=True))
        if any(result["blocked"] for result in summaries):
            raise ValueError("Candidate has actionable fixed high/critical or unreviewed unpatched critical findings")
        return index_digest


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("images", nargs=2)
    parser.add_argument("--output", required=True, type=pathlib.Path)
    parser.add_argument("--revision")
    parser.add_argument("--cache-directory", type=pathlib.Path)
    parser.add_argument("--tool-network", choices=("bridge", "host"), default="bridge",
                        help="Docker network for public registry/scanner clients")
    parser.add_argument("--github-output", type=pathlib.Path)
    args = parser.parse_args()
    try:
        policy = json.loads((DIRECTORY / "devcontainer-security.json").read_text())
        digest = scan(args.images, args.output.resolve(), policy, args.revision, args.cache_directory, args.tool_network)
        if args.github_output:
            with args.github_output.open("a") as stream:
                stream.write(f"index_digest={digest}\n")
    except (ValueError, KeyError, OSError, RuntimeError, subprocess.SubprocessError) as error:
        parser.exit(1, f"Devcontainer security qualification refused: {error}\n")
