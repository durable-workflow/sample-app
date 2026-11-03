#!/usr/bin/env bash
# Move every Rust SDK pin in the Sample App to one published crates.io version:
# the qualified artifact tuple, both Cargo manifests and lock files, and the
# README's tuple summary. Run it from a checkout with cargo on PATH; it only
# resolves the lock files and compiles nothing.
set -euo pipefail

if [[ $# -ne 1 || ! "$1" =~ ^4\.[0-9]+\.[0-9]+$ ]]; then
  printf 'Usage: %s <stable 4.x durable-workflow crate version>\n' "${0##*/}" >&2
  exit 2
fi
version="$1"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tuple="$repo_root/polyglot/qualified-artifact-tuple.json"

node - "$tuple" "$version" <<'NODE'
const fs = require('node:fs');
const [path, version] = process.argv.slice(2);
const tuple = JSON.parse(fs.readFileSync(path, 'utf8'));
tuple.artifacts['sdk-rust'] = version;
fs.writeFileSync(path, `${JSON.stringify(tuple, null, 2)}\n`);
NODE

for manifest_dir in polyglot/rust_worker playground/templates/rust; do
  manifest="$repo_root/$manifest_dir/Cargo.toml"
  sed -i.bak -E "s/^durable-workflow = \"[^\"]+\"$/durable-workflow = \"${version}\"/" "$manifest"
  rm -f "$manifest.bak"
  grep -qx "durable-workflow = \"${version}\"" "$manifest"
  cargo update --manifest-path "$manifest" -p durable-workflow --precise "$version"
done

readme="$repo_root/README.md"
sed -i.bak -E "s/Rust SDK [0-9]+\.[0-9]+\.[0-9]+/Rust SDK ${version}/" "$readme"
rm -f "$readme.bak"

"$repo_root/scripts/resolve-current-artifacts.sh" | grep -x "DURABLE_WORKFLOW_RUST_SDK_VERSION=${version}"
