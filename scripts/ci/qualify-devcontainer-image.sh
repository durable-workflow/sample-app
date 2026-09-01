#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose_file="${repo_root}/.devcontainer/docker/docker-compose.yml"
image="${1:?Usage: qualify-devcontainer-image.sh IMAGE PLATFORM}"
platform="${2:?Usage: qualify-devcontainer-image.sh IMAGE PLATFORM}"
max_seconds="${DEVCONTAINER_MAX_STARTUP_SECONDS:-600}"
require_attestations="${DEVCONTAINER_REQUIRE_PUBLISHED_ATTESTATIONS:-1}"
require_anonymous_pull="${DEVCONTAINER_REQUIRE_ANONYMOUS_PULL:-0}"
skip_image_pull="${DEVCONTAINER_SKIP_IMAGE_PULL:-0}"
expected_revision="${DEVCONTAINER_EXPECTED_REVISION:-}"

source "${repo_root}/scripts/ci/devcontainer-identity.sh"

case "$platform" in
  linux/amd64) expected_machine=x86_64 ;;
  linux/arm64) expected_machine=aarch64 ;;
  *) printf 'Unsupported qualification platform: %s\n' "$platform" >&2; exit 2 ;;
esac

if [[ "$(uname -m)" != "$expected_machine" ]]; then
  printf 'Qualification for %s requires a native %s runner.\n' "$platform" "$expected_machine" >&2
  exit 1
fi

if [[ "$require_anonymous_pull" == "1" ]]; then
  docker_config="${DOCKER_CONFIG:-${HOME}/.docker}/config.json"
  python3 - "$docker_config" <<'PY'
import json
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
if not path.is_file():
    raise SystemExit(f'anonymous pull requires an explicit Docker config: {path}')
config = json.loads(path.read_text(encoding='utf-8'))
if config.get('auths') or config.get('credsStore') or config.get('credHelpers'):
    raise SystemExit('anonymous pull Docker config contains credentials')
PY
fi

started_at="$(date +%s)"
tracked_before="$(git -C "$repo_root" status --porcelain --untracked-files=no)"
if [[ -n "$tracked_before" ]]; then
  printf 'Devcontainer qualification requires a clean tracked checkout.\n%s\n' "$tracked_before" >&2
  exit 1
fi

if [[ "$skip_image_pull" == "1" ]]; then
  docker image inspect "$image" >/dev/null
else
  docker pull --platform "$platform" "$image"
fi

if [[ "$require_attestations" == "1" ]]; then
  manifest="$(mktemp)"
  docker buildx imagetools inspect "$image" --raw > "$manifest"
  python3 - "$manifest" <<'PY'
import json
import sys

manifest = json.load(open(sys.argv[1], encoding='utf-8'))
entries = manifest.get('manifests', [])
platforms = {
    (entry.get('platform') or {}).get('architecture')
    for entry in entries
    if (entry.get('platform') or {}).get('os') == 'linux'
}
if not {'amd64', 'arm64'} <= platforms:
    raise SystemExit(f'published image is missing platforms: {platforms}')
attestations = [
    entry for entry in entries
    if (entry.get('annotations') or {}).get('vnd.docker.reference.type') == 'attestation-manifest'
]
if len(attestations) < 2:
    raise SystemExit('published image does not expose per-platform attestations')
PY
  rm -f "$manifest"
fi

source_label="$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.source" }}' "$image")"
revision_label="$(docker image inspect --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$image")"
[[ "$source_label" == 'https://github.com/durable-workflow/sample-app' ]]
if [[ -n "$expected_revision" ]]; then
  [[ "$revision_label" == "$expected_revision" ]]
fi

suffix="$(printf '%s-%s' "$image" "$platform" | sha256sum | cut -c1-12)"
export COMPOSE_PROJECT_NAME="sample-app-devcontainer-${suffix}"
export DOCKER_DEFAULT_PLATFORM="$platform"
export SAMPLE_APP_DEVCONTAINER_IMAGE="$image"
export SAMPLE_APP_DEVCONTAINER_PULL_POLICY=never
export SAMPLE_APP_UID="$(id -u)"
export DB_DATABASE=sample
export DB_USERNAME=laravel
export DB_PASSWORD=password
export APP_PORT=18080
export MICROSERVICE_PORT=18001
export VITE_PORT=15173
export FORWARD_DB_PORT=13306
export FORWARD_REDIS_PORT=16379

compose=(docker compose --file "$compose_file")

cleanup() {
  local status=$?
  trap - EXIT
  if (( status != 0 )); then
    "${compose[@]}" ps >&2 || true
    "${compose[@]}" logs --no-color --timestamps --tail=200 >&2 || true
  fi
  "${compose[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
  exit "$status"
}
trap cleanup EXIT

"${compose[@]}" pull mysql redis
"${compose[@]}" up --detach --no-build mysql redis
"${compose[@]}" up --detach --no-build laravel microservice
run_in_ready_devcontainer laravel .devcontainer/post-create.sh
"${compose[@]}" up --detach --no-build --wait

run_in_ready_devcontainer laravel bash -euc '
  for command in php composer python3 rustc cargo dw rg node npm docker; do
    command -v "$command" >/dev/null
  done
  docker compose version >/dev/null
  curl --fail --silent http://localhost/up >/dev/null
  curl --fail --silent http://localhost/ >/dev/null
  composer validate --strict --check-lock --no-check-all --no-interaction
  cargo check --bins --locked --offline --manifest-path=playground/templates/rust/Cargo.toml >/dev/null
  scripts/playground doctor
'
"${compose[@]}" exec -T --user laravel laravel node docker/playwright-smoke.js
"${compose[@]}" exec -T laravel sshd -t
"${compose[@]}" exec -T mysql mariadb \
  --user=laravel --password=password --database=sample \
  --batch --skip-column-names --execute='SELECT 1' | grep -Fx 1

tracked_after="$(git -C "$repo_root" status --porcelain --untracked-files=no)"
if [[ "$tracked_after" != "$tracked_before" ]]; then
  printf 'Codespaces setup modified tracked files.\n%s\n' "$tracked_after" >&2
  exit 1
fi

elapsed=$(( $(date +%s) - started_at ))
if (( elapsed > max_seconds )); then
  printf 'Codespaces startup took %ss; limit is %ss.\n' "$elapsed" "$max_seconds" >&2
  exit 1
fi

printf 'Devcontainer qualification passed for %s in %ss.\n' "$platform" "$elapsed"
