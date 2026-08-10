#!/usr/bin/env bash

set -euo pipefail

[[ "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" == "8.4" ]]
[[ "$(composer --no-ansi --version | awk '{print $3}' | cut -d. -f1)" == "2" ]]
[[ "$(node --version | sed -E 's/^v([0-9]+).*/\1/')" == "22" ]]

for extension in bcmath curl gd intl mbstring pcntl pdo_mysql pdo_sqlite redis zip; do
    php --ri "$extension" >/dev/null
done

for executable in composer curl ffmpeg git mysql node npm npx playwright redis-cli ssh sshd; do
    command -v "$executable" >/dev/null
done

case "${DEVCONTAINER_SSH_HOST_KEY_STATE:-present}" in
    absent)
        if compgen -G '/etc/ssh/ssh_host_*_key' >/dev/null; then
            echo 'The published image must not contain shared SSH host private keys.' >&2
            exit 1
        fi
        ;;
    present)
        compgen -G '/etc/ssh/ssh_host_*_key' >/dev/null
        ;;
    *)
        echo "Unknown SSH host key verification state: ${DEVCONTAINER_SSH_HOST_KEY_STATE}" >&2
        exit 2
        ;;
esac

browser="$(find "${PLAYWRIGHT_BROWSERS_PATH}" -type f -perm -111 \
    \( -name chrome -o -name chromium -o -name headless_shell -o -name chrome-headless-shell \) \
    -print -quit)"

if [[ -z "$browser" ]]; then
    echo "No executable Playwright Chromium runtime found in ${PLAYWRIGHT_BROWSERS_PATH}." >&2
    exit 1
fi

"$browser" --version
timeout 30 "$browser" \
    --disable-gpu \
    --dump-dom \
    --headless \
    --no-sandbox \
    about:blank >/dev/null

ffmpeg_probe_dir="$(mktemp -d)"
trap 'rm -rf "$ffmpeg_probe_dir"' EXIT HUP INT TERM

timeout 30 ffmpeg \
    -hide_banner \
    -loglevel error \
    -f lavfi \
    -i 'testsrc=size=64x64:rate=10' \
    -f lavfi \
    -i 'sine=frequency=1000:sample_rate=44100' \
    -t 1 \
    -c:v libvpx \
    -c:a libvorbis \
    "$ffmpeg_probe_dir/input.webm"

timeout 30 ffmpeg \
    -hide_banner \
    -loglevel error \
    -i "$ffmpeg_probe_dir/input.webm" \
    -c:v libx264 \
    -preset fast \
    -crf 23 \
    -c:a aac \
    -b:a 128k \
    "$ffmpeg_probe_dir/output.mp4"

test -s "$ffmpeg_probe_dir/output.mp4"
