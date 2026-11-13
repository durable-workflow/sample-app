#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
minor="${1:?Usage: setup-php-container.sh 8.4|8.5}"
image="$(python3 - "$root/scripts/ci/php-container-images.json" "$minor" <<'PY'
import json,re,sys
images=json.load(open(sys.argv[1]))
image=images[sys.argv[2]]
assert re.fullmatch(r'php:8\.[45]\.\d+-cli-bookworm@sha256:[0-9a-f]{64}', image)
print(image)
PY
)"
test -f "$root/.central-apt-source/docker/apt/require-fixed-bookworm-apt.sh"
test "$(git -C "$root/.central-apt-source" rev-parse HEAD)" = 18783be6bef737cc778e4c7282a8797e39daa61a
echo '2a36a2417e8947357ea6f6cbc2f6c53a834f3c069f8013a34d6d861b7123d576  '"$root/.central-apt-source/docker/apt/require-fixed-bookworm-apt.sh" | sha256sum --check --strict

runtime="sample-app-php-ci:${minor}-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}"
docker build --file "$root/docker/php-ci/Dockerfile" --build-arg "PHP_IMAGE=$image" --tag "$runtime" "$root"
docker run --rm --init --name "sample-app-php-ci-probe-${minor}-${GITHUB_RUN_ID:-local}" \
    --cpus 1 --memory 512m --pids-limit 64 --network none "$runtime" \
    php -r '
        foreach (["mbstring", "intl", "pdo_mysql", "redis", "bcmath", "gd", "zip", "curl", "pcntl"] as $extension) {
            if (!extension_loaded($extension)) { throw new RuntimeException("Missing extension: ".$extension); }
        }
        if (extension_loaded("xdebug") || extension_loaded("pcov")) { throw new RuntimeException("Coverage must remain none."); }
        if (php_ini_loaded_file() !== "/usr/local/etc/php/php.ini") { throw new RuntimeException("Production INI must be loaded."); }
        echo json_encode(["php" => PHP_VERSION, "extensions" => get_loaded_extensions(), "ini" => php_ini_loaded_file(), "coverage" => "none"]), PHP_EOL;
    '
docker image inspect "$runtime" --format 'php_ci_image={{.Id}}'

state="${RUNNER_TEMP:?RUNNER_TEMP is required}/sample-app-php-ci"
mkdir -p "$state/bin" "$state/tmp" "$state/composer-home/cache"
ln -sf "$root/scripts/ci/run-php-container.sh" "$state/bin/php"
ln -sf "$root/scripts/ci/run-php-container.sh" "$state/bin/composer"
{
    echo "SAMPLE_APP_PHP_CI_IMAGE=$runtime"
    echo "SAMPLE_APP_PHP_CI_ROOT=$root"
    echo "SAMPLE_APP_PHP_CI_STATE=$state"
    echo "COMPOSER_HOME=$state/composer-home"
    echo "COMPOSER_CACHE_DIR=$state/composer-home/cache"
    echo "TMPDIR=$state/tmp"
} >> "${GITHUB_ENV:?GITHUB_ENV is required}"
echo "$state/bin" >> "${GITHUB_PATH:?GITHUB_PATH is required}"
