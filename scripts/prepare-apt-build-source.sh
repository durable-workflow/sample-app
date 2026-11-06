#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_dir="$repo_root/.central-apt-source"
expected_commit=18783be6bef737cc778e4c7282a8797e39daa61a

if [[ ! -e "$source_dir" ]]; then
  source_temp="$(mktemp -d "$repo_root/.central-apt-source.XXXXXX")"
  trap 'rm -rf -- "$source_temp"' EXIT
  source_env=(PATH=/usr/bin:/bin:/usr/local/bin GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_TERMINAL_PROMPT=0)
  for variable in SSL_CERT_FILE SSL_CERT_DIR GIT_SSL_CAINFO GIT_SSL_CAPATH CURL_CA_BUNDLE; do
    if [[ -n "${!variable:-}" ]]; then source_env+=("$variable=${!variable}"); fi
  done
  source_git() {
    env -i "${source_env[@]}" git \
      -c credential.helper= -c core.askPass= -c core.hooksPath=/dev/null \
      -c http.extraHeader= -c http.followRedirects=false \
      -c http.lowSpeedLimit=1024 -c http.lowSpeedTime=45 "$@"
  }
  source_git init --quiet "$source_temp"
  source_git -C "$source_temp" fetch --quiet --depth=1 \
    https://github.com/durable-workflow/.github.git "$expected_commit"
  source_git -C "$source_temp" checkout --quiet --detach FETCH_HEAD
  mv -- "$source_temp" "$source_dir"
  trap - EXIT
fi
test "$(git -C "$source_dir" rev-parse HEAD)" = "$expected_commit"
cd "$source_dir"
printf '%s\n' \
  '2a36a2417e8947357ea6f6cbc2f6c53a834f3c069f8013a34d6d861b7123d576  docker/apt/require-fixed-bookworm-apt.sh' \
  '73f8aa7d627dd63224fa1bde3db840424befa4a01f71f0b1d8b743615a4952dd  .github/actions/bootstrap-patched-pip/export-trust.py' \
  | sha256sum --check --strict -
