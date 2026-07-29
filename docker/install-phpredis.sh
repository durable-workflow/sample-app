#!/bin/sh

set -eu

readonly PHPREDIS_VERSION='6.3.0'
readonly PHPREDIS_COMMIT='df4fab2de7fc327c54c94a13af2b9542e4fbd720'
readonly PHPREDIS_SHA256='79ecabd899e50a6efa56d9fc28a987a25d78deecc32fdd0fa9840b1b3d83740e'
readonly PHPREDIS_ARCHIVE="phpredis-${PHPREDIS_COMMIT}.tar.gz"
readonly PHPREDIS_ARCHIVE_PATH="/tmp/${PHPREDIS_ARCHIVE}"
readonly PHPREDIS_SOURCE_PATH='/usr/src/php/ext/redis'
readonly PHPREDIS_SOURCE_URL="https://codeload.github.com/phpredis/phpredis/tar.gz/${PHPREDIS_COMMIT}"

cleanup() {
    rm -f "$PHPREDIS_ARCHIVE_PATH"
    rm -rf "$PHPREDIS_SOURCE_PATH"
}

trap cleanup EXIT HUP INT TERM

curl \
    --fail \
    --location \
    --silent \
    --show-error \
    --connect-timeout 10 \
    --max-time 120 \
    --retry 5 \
    --retry-all-errors \
    --retry-delay 2 \
    --retry-max-time 180 \
    --remove-on-error \
    --output "$PHPREDIS_ARCHIVE_PATH" \
    "$PHPREDIS_SOURCE_URL"

printf '%s  %s\n' "$PHPREDIS_SHA256" "$PHPREDIS_ARCHIVE_PATH" \
    | sha256sum --check --strict -

mkdir -p "$PHPREDIS_SOURCE_PATH"
tar \
    --extract \
    --gzip \
    --file "$PHPREDIS_ARCHIVE_PATH" \
    --directory "$PHPREDIS_SOURCE_PATH" \
    --strip-components=1

docker-php-ext-install redis

installed_version="$(php -r 'echo phpversion("redis") ?: "";')"
if [ "$installed_version" != "$PHPREDIS_VERSION" ]; then
    printf 'Installed phpredis version %s does not match pinned version %s\n' \
        "$installed_version" "$PHPREDIS_VERSION" >&2
    exit 1
fi
