#!/usr/bin/env python3
"""Repack pip with its upstream-compatible urllib3 security refresh (#280)."""

import argparse
import base64
import csv
import hashlib
import io
import json
import os
import pathlib
import shutil
import subprocess
import sys
import sysconfig
import tempfile
import zipfile


def verify_record(path):
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        records = [name for name in names if name.endswith(".dist-info/RECORD")]
        if len(records) != 1 or len(names) != len(set(names)):
            raise ValueError("Wheel has ambiguous files or RECORD")
        record = records[0]
        rows = list(csv.reader(io.StringIO(archive.read(record).decode())))
        if len(rows) != len(names) or {row[0] for row in rows} != set(names):
            raise ValueError("Wheel RECORD does not cover its exact files")
        for name, digest, size in rows:
            if name == record:
                if digest or size:
                    raise ValueError("RECORD must not hash itself")
                continue
            raw = archive.read(name)
            actual = base64.urlsafe_b64encode(hashlib.sha256(raw).digest()).rstrip(b"=").decode()
            if digest != "sha256=" + actual or size != str(len(raw)):
                raise ValueError(f"Invalid wheel RECORD entry: {name}")
    return len(rows)


def replace_once(path, old, new):
    text = path.read_text(encoding="utf-8")
    if text.count(old) != 1:
        raise ValueError(f"Unexpected upstream version metadata: {path.name}")
    path.write_text(text.replace(old, new), encoding="utf-8")


def build(inputs, patch, output):
    config = json.loads(inputs.read_text(encoding="utf-8"))
    output.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="sample-pip-") as temporary:
        root = pathlib.Path(temporary)
        wheels = {}
        for name, artifact in config["artifacts"].items():
            target = root / artifact["filename"]
            subprocess.run(["curl", "--fail", "--silent", "--show-error", "--location",
                            "--proto", "=https", "--proto-redir", "=https", "--max-time", "120",
                            artifact["url"], "--output", str(target)], check=True)
            if hashlib.sha256(target.read_bytes()).hexdigest() != artifact["sha256"]:
                raise ValueError(f"Unqualified Python tooling artifact: {name}")
            wheels[name] = target

        # Run the pinned PyPA wheel CLI without using old ensurepip for network
        # access, installing build tools globally, or retaining them in the image.
        tools = root / "wheel-tools"
        tools.mkdir()
        for name in ("wheel", "packaging"):
            with zipfile.ZipFile(wheels[name]) as archive:
                archive.extractall(tools)
        environment = dict(os.environ, PYTHONPATH=str(tools), PYTHONDONTWRITEBYTECODE="1",
                           SOURCE_DATE_EPOCH=str(config["source_date_epoch"]))

        def wheel(*arguments):
            subprocess.run([sys.executable, "-m", "wheel", *map(str, arguments)], env=environment, check=True)

        wheel("unpack", wheels["pip"], "--dest", root)
        unpacked = root / f"pip-{config['pip_upstream_version']}"
        # The exact upstream wheel SHA fixes the complete patch base. Zero
        # context keeps patch-format blank lines out of whitespace checks.
        subprocess.run(["git", "apply", "--check", "--unidiff-zero", "-p2", str(patch.resolve())], cwd=unpacked, check=True)
        subprocess.run(["git", "apply", "--unidiff-zero", "-p2", str(patch.resolve())], cwd=unpacked, check=True)

        upstream = config["pip_upstream_version"]
        version = config["pip_version"]
        replace_once(unpacked / "pip/__init__.py", f'__version__ = "{upstream}"', f'__version__ = "{version}"')
        dist_info = unpacked / f"pip-{upstream}.dist-info"
        replace_once(dist_info / "METADATA", f"\nVersion: {upstream}\n", f"\nVersion: {version}\n")
        dist_info.rename(unpacked / f"pip-{version}.dist-info")
        wheel("pack", unpacked, "--dest-dir", output)
        result = output / f"pip-{version}-py3-none-any.whl"
        entries = verify_record(result)
        with zipfile.ZipFile(result) as archive:
            vendor = archive.read("pip/_vendor/vendor.txt").decode()
            bom = json.loads(archive.read("pip/_vendor/bom.cdx.json"))
            expected = config["urllib3_version"]
            if f"urllib3=={expected}" not in vendor or not any(
                component["name"] == "urllib3" and component["version"] == expected
                for component in bom["components"]
            ):
                raise ValueError("Patched wheel vendor requirements and SBOM disagree")
        shutil.copyfile(wheels["setuptools"], output / wheels["setuptools"].name)
        print(json.dumps({"pip": version, "urllib3": expected,
                          "record_entries": entries, "setuptools": config["setuptools_version"]}))


def seed_environments(output):
    directory = pathlib.Path(sysconfig.get_config_var("WHEEL_PKG_DIR") or "")
    if directory != pathlib.Path("/usr/share/python-wheels"):
        raise ValueError("Expected Debian's ensurepip wheel directory")
    replacements = []
    for project in ("pip", "setuptools"):
        original = list(directory.glob(f"{project}-*.whl"))
        fixed = list(output.glob(f"{project}-*.whl"))
        if len(original) != 1 or len(fixed) != 1:
            raise ValueError(f"Ambiguous ensurepip seed wheel: {project}")
        owner = subprocess.check_output(["dpkg-query", "--search", str(original[0])], text=True)
        if not owner.startswith(f"python3-{project}-whl: "):
            raise ValueError(f"Unexpected ensurepip seed package: {project}")
        replacements.append((original[0], fixed[0]))
    for original, fixed in replacements:
        diverted = original.with_suffix(".whl.distribution")
        # Register the local replacement so package upgrades cannot overwrite
        # the maintained seed. Do not ship the retired vulnerable archive.
        subprocess.run(["dpkg-divert", "--local", "--rename", "--add", "--divert",
                        str(diverted), str(original)], check=True)
        diverted.unlink()
        shutil.copyfile(fixed, directory / fixed.name)


def clean_retired_seeds():
    directory = pathlib.Path("/usr/share/python-wheels")
    for project in ("pip", "setuptools"):
        files = subprocess.check_output(["dpkg-query", "--listfiles", f"python3-{project}-whl"], text=True)
        originals = [pathlib.Path(line) for line in files.splitlines()
                     if pathlib.Path(line).parent == directory
                     and pathlib.Path(line).name.startswith(f"{project}-") and line.endswith(".whl")]
        if len(originals) != 1:
            raise ValueError(f"Ambiguous distribution seed file: {project}")
        original = originals[0]
        actual = subprocess.check_output(["dpkg-divert", "--truename", str(original)], text=True).strip()
        diverted = original.with_suffix(".whl.distribution")
        if actual != str(diverted) or original.exists():
            raise ValueError(f"Ensurepip seed diversion was lost: {project}")
        # A later package reconciliation can refresh the retired archive at
        # its diverted path. It must not be retained in the published image.
        diverted.unlink(missing_ok=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("output", nargs="?", type=pathlib.Path)
    parser.add_argument("--seed", action="store_true", help="Replace Debian ensurepip seeds inside the image")
    parser.add_argument("--clean-seeds", action="store_true", help="Remove retired seed archives after final apt operations")
    args = parser.parse_args()
    if args.clean_seeds:
        clean_retired_seeds()
    elif args.output is not None:
        output = args.output.resolve()
        directory = pathlib.Path(__file__).resolve().parent
        build(directory / "tools.json", directory / "pip-urllib3.patch", output)
        if args.seed:
            seed_environments(output)
    else:
        parser.error("output is required when building Python tooling")
