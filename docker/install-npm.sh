#!/bin/sh
set -eu

node /usr/local/share/sample-app/verify-node-trust.mjs
npm install --global --ignore-scripts --no-audit --no-fund npm@12.2.1
hash -r
test "$(npm --version)" = 12.2.1
