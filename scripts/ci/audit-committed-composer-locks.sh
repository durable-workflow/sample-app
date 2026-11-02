#!/usr/bin/env bash
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
composer_lock_list="$(mktemp)"
trap 'rm -f "$composer_lock_list"' EXIT
git ls-files -z -- composer.lock '**/composer.lock' > "$composer_lock_list"

if [[ ! -s "$composer_lock_list" ]]; then
  printf '%s\n' 'No committed Composer locks were found.' >&2
  exit 1
fi

audit_count=0
audit_failed=0
while IFS= read -r -d '' composer_lock; do
  audit_count=$((audit_count + 1))
  if (( audit_count > 64 )); then
    printf '%s\n' 'The Composer lock inventory exceeds the 64-lock audit bound.' >&2
    exit 1
  fi
  if [[ -L "$composer_lock" || ! -f "$composer_lock" ]]; then
    printf 'Composer lock must be a regular committed file: %s\n' "$composer_lock" >&2
    exit 1
  fi

  printf '\nAuditing %s\n' "$composer_lock"
  if ! composer --no-plugins --no-scripts audit --locked --no-interaction \
      --working-dir="$(dirname -- "$composer_lock")"; then
    audit_failed=1
  fi
done < "$composer_lock_list"

printf '\nAudited %s committed Composer locks.\n' "$audit_count"
exit "$audit_failed"
