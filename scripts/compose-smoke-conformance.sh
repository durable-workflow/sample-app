#!/usr/bin/env bash
set -euo pipefail

# The combined entry point owns setup so the exact artifact tuple is resolved,
# built, and migrated once before deterministic smoke and the strict matrix.
export SAMPLE_APP_CONFORMANCE_SMOKE_FIRST=1

exec scripts/compose-conformance.sh "$@"
