#!/usr/bin/env python3
"""Verify downloaded OCI attestations before scanning an exact platform SBOM."""

import argparse
import base64
import hashlib
import json
import pathlib
import re
from urllib.parse import parse_qs, urlsplit

SPDX = "https://spdx.dev/Document"
SLSA = "https://slsa.dev/provenance/v1"


def require(condition, message):
    if not condition:
        raise ValueError(message)


def verified_json(raw, descriptor):
    digest = descriptor["digest"]
    require(re.fullmatch(r"sha256:[a-f0-9]{64}", digest), "Invalid SHA-256 descriptor")
    require(hashlib.sha256(raw).hexdigest() == digest[7:], "Attestation content digest mismatch")
    if "size" in descriptor:
        require(len(raw) == descriptor["size"], "Attestation content size mismatch")
    return json.loads(raw)


def statement_for(manifest, predicate_type, image_digest, fetch):
    layers = [layer for layer in manifest["layers"]
              if layer.get("annotations", {}).get("in-toto.io/predicate-type") == predicate_type]
    require(len(layers) == 1, f"Expected one {predicate_type} attestation")
    layer = layers[0]
    require(layer["mediaType"] == "application/vnd.in-toto+json", "Unsupported attestation encoding")
    statement = verified_json(fetch(layer["digest"]), layer)
    require(statement["_type"] in ("https://in-toto.io/Statement/v0.1", "https://in-toto.io/Statement/v1"),
            "Unsupported in-toto statement")
    require(statement["predicateType"] == predicate_type, "Attestation predicate mismatch")
    subjects = statement["subject"]
    require(subjects and all(subject["digest"] == {"sha256": image_digest[7:]} for subject in subjects),
            "Attestation does not name the exact platform image")
    return statement["predicate"]


def verify_platform(index_raw, index_digest, platform, fetch, expected_revision=None):
    require(platform in ("linux/amd64", "linux/arm64"), "Unsupported platform")
    index = verified_json(index_raw, {"digest": index_digest})
    images = [entry for entry in index["manifests"]
              if entry.get("platform", {}).get("os") == "linux"
              and entry["platform"].get("architecture") == platform.split("/")[1]]
    require(len(images) == 1, "Expected one exact platform image")
    image_digest = images[0]["digest"]
    require(re.fullmatch(r"sha256:[a-f0-9]{64}", image_digest), "Invalid platform image digest")
    attestations = [entry for entry in index["manifests"]
                    if entry.get("annotations", {}).get("vnd.docker.reference.type") == "attestation-manifest"
                    and entry["annotations"].get("vnd.docker.reference.digest") == image_digest]
    require(len(attestations) == 1, "Expected one attestation manifest bound to the platform")
    manifest = verified_json(fetch(attestations[0]["digest"]), attestations[0])
    spdx = statement_for(manifest, SPDX, image_digest, fetch)
    provenance = statement_for(manifest, SLSA, image_digest, fetch)

    metadata = provenance["runDetails"]["metadata"]["buildkit_metadata"]
    vcs = metadata["vcs"]
    require(vcs["source"] == "https://github.com/durable-workflow/sample-app", "Unexpected source repository")
    revision = vcs["revision"]
    require(re.fullmatch(r"[a-f0-9]{40}", revision), "Missing exact source revision")
    require(expected_revision is None or revision == expected_revision, "Unexpected source revision")
    require(vcs["localdir:dockerfile"] == ".devcontainer/docker", "Unexpected Dockerfile source directory")
    infos = [info for info in metadata["source"]["infos"]
             if info["filename"] == "Dockerfile" and info["language"] == "Dockerfile"]
    require(len(infos) == 1, "Missing embedded development Dockerfile")
    dockerfile = base64.b64decode(infos[0]["data"], validate=True).decode("utf-8")
    bases = re.findall(r"^FROM\s+(\S+)", dockerfile, flags=re.MULTILINE | re.IGNORECASE)
    require(bases and re.fullmatch(r"php:8\.\d+\.\d+-cli-bookworm@sha256:[a-f0-9]{64}", bases[-1]),
            "Final image is not an immutable PHP Bookworm base")
    php_base = bases[-1]
    base_digest = php_base.split("@", 1)[1]
    dependencies = provenance["buildDefinition"]["resolvedDependencies"]
    require(any(dependency["uri"].startswith("pkg:docker/php@")
                and dependency["digest"] == {"sha256": base_digest[7:]}
                and parse_qs(urlsplit(dependency["uri"]).query).get("platform") == [platform]
                and parse_qs(urlsplit(dependency["uri"]).query).get("digest") == [base_digest]
                for dependency in dependencies), "PHP base is absent from the resolved platform dependencies")

    require(spdx["spdxVersion"].startswith("SPDX-2."), "Unsupported SPDX document")
    require(spdx["packages"], "Empty package inventory")
    # BuildKit can omit OS metadata. Require both attested source/base proof and
    # an actual Debian 12 inventory marker before supplying Trivy's override.
    base_files = [package for package in spdx["packages"] if package["name"] == "base-files"]
    require(any(package.get("versionInfo", "").startswith("12.")
                and any(reference.get("referenceType") == "purl"
                        and reference["referenceLocator"].startswith("pkg:deb/debian/base-files@")
                        and re.fullmatch(r"debian-12(?:\.\d+)?", parse_qs(urlsplit(reference["referenceLocator"]).query)
                                         .get("distro", [""])[0])
                        for reference in package.get("externalRefs", []))
                for package in base_files), "SPDX does not confirm Debian 12")
    return spdx, {"index_digest": index_digest, "platform": platform,
                  "image_digest": image_digest, "revision": revision,
                  "distro": "debian/12", "php_base": php_base}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", type=pathlib.Path, help="index.json and blobs named SHA256.json")
    parser.add_argument("index_digest")
    parser.add_argument("platform", choices=("linux/amd64", "linux/arm64"))
    parser.add_argument("--revision")
    parser.add_argument("--output", required=True, type=pathlib.Path)
    args = parser.parse_args()

    def fetch(digest):
        require(re.fullmatch(r"sha256:[a-f0-9]{64}", digest), "Invalid blob digest")
        return (args.directory / f"{digest[7:]}.json").read_bytes()

    try:
        spdx, identity = verify_platform((args.directory / "index.json").read_bytes(), args.index_digest,
                                         args.platform, fetch, args.revision)
        args.output.write_text(json.dumps(spdx) + "\n", encoding="utf-8")
    except (ValueError, KeyError, OSError, TypeError) as error:
        parser.exit(1, f"Devcontainer attestation refused: {error}\n")
    print(json.dumps(identity, sort_keys=True))


if __name__ == "__main__":
    main()
