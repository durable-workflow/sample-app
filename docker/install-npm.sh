#!/bin/sh
set -eu

node /usr/local/share/sample-app/verify-node-trust.mjs
npm install --global --ignore-scripts --no-audit --no-fund npm@12.2.1
hash -r
test "$(npm --version)" = 12.2.1

# The current npm release still bundles the affected undici 6.29.0 for node-gyp.
# Replace that exact bundled component with the fixed compatible upstream patch.
npm_root="$(npm root --global)/npm"
temporary="$(mktemp -d)"
trap 'rm -rf "$temporary"' EXIT HUP INT TERM
node --input-type=module - "$temporary/undici.tgz" <<'JS'
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { writeFileSync } from 'node:fs';
const response = await fetch('https://registry.npmjs.org/undici/-/undici-6.29.1.tgz', { signal: AbortSignal.timeout(120000) });
assert(response.ok, `undici download failed: ${response.status}`);
const archive = Buffer.from(await response.arrayBuffer());
assert.equal(createHash('sha256').update(archive).digest('hex'), '29c4b8e0b2d86cb8795571c1959012ce057a14ecfd34858335f47c31e770a04f');
writeFileSync(process.argv[2], archive);
JS
node - "$npm_root" <<'JS'
const assert = require('node:assert/strict');
const root = process.argv[2];
assert.equal(require(`${root}/node_modules/undici/package.json`).version, '6.29.0');
const nodeGyp = require(`${root}/node_modules/node-gyp/package.json`);
assert.equal(nodeGyp.version, '13.0.0');
assert(require(`${root}/node_modules/semver`).satisfies('6.29.1', nodeGyp.dependencies.undici));
JS
rm -rf "$npm_root/node_modules/undici"
mkdir "$npm_root/node_modules/undici"
tar --extract --gzip --file="$temporary/undici.tgz" --strip-components=1 --directory="$npm_root/node_modules/undici"
node - "$npm_root" <<'JS'
const assert = require('node:assert/strict');
const fs = require('node:fs');
const root = process.argv[2];
assert.equal(require(`${root}/node_modules/undici/package.json`).version, '6.29.1');
const metadata = JSON.parse(fs.readFileSync(`${root}/package.json`, 'utf8'));
metadata.version = '12.2.1+sample.1';
fs.writeFileSync(`${root}/package.json`, JSON.stringify(metadata, null, 2) + '\n');
JS
test "$(npm --version)" = 12.2.1+sample.1
