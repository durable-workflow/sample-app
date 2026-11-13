#!/usr/bin/env bash
set -euo pipefail

command="${0##*/}"
case "$command" in php|composer) ;; *) echo 'Invoke this launcher as php or composer.' >&2; exit 2 ;; esac
root="${SAMPLE_APP_PHP_CI_ROOT:?PHP CI repository root is required}"
state="${SAMPLE_APP_PHP_CI_STATE:?PHP CI state directory is required}"
image="${SAMPLE_APP_PHP_CI_IMAGE:?PHP CI image is required}"

options=(--rm --init --interactive --name "sample-app-php-ci-${command}-${BASHPID}"
    --cpus 2 --memory 2g --pids-limit 512 --network host
    --cap-drop ALL --security-opt no-new-privileges
    --user "$(id -u):$(id -g)" --workdir "$PWD"
    --mount "type=bind,source=$root,target=$root"
    --mount "type=bind,source=$state,target=$state")

# Composer's guard is a composite action outside the repository checkout.
if [[ -n "${GITHUB_ACTION_PATH:-}" ]]; then
    options+=(--mount "type=bind,source=$GITHUB_ACTION_PATH,target=$GITHUB_ACTION_PATH,readonly")
fi
# A local worktree has its Git metadata outside the source mount.
git_directory="$(git -C "$root" rev-parse --path-format=absolute --git-common-dir)"
if [[ "$git_directory" != "$root/"* ]]; then
    options+=(--mount "type=bind,source=$git_directory,target=$git_directory,readonly")
fi

declare -A mounted=()
for variable in SSL_CERT_FILE CURL_CA_BUNDLE GIT_SSL_CAINFO COMPOSER_CAFILE \
    REQUESTS_CA_BUNDLE PIP_CERT NPM_CONFIG_CAFILE CARGO_HTTP_CAINFO CARGO_HTTP_PROXY_CAINFO \
    SSL_CERT_DIR GIT_SSL_CAPATH COMPOSER_CAPATH; do
    if [[ -n "${!variable:-}" ]]; then
        selected="${!variable}"
        if [[ -z "${mounted[$selected]:-}" ]]; then
            options+=(--mount "type=bind,source=$selected,target=$selected,readonly")
            mounted[$selected]=1
        fi
        options+=(--env "$variable")
    fi
done

# Pass the CI configuration and the playground's separate synthetic role credentials.
while IFS= read -r variable; do
    case "$variable" in
        APP_*|DB_*|SHARED_DB_*|REDIS_*|CACHE_*|QUEUE_*|SESSION_*|MAIL_*|BCRYPT_ROUNDS|\
        COMPOSER_HOME|COMPOSER_CACHE_DIR|COMPOSER_MAX_PARALLEL_*|COMPOSER_IPRESOLVE|\
        SAMPLE_APP_ROOT|SAMPLE_APP_PLAYGROUND_SCENARIO|SAMPLE_APP_PLAYGROUND_WORKFLOW_ID_PREFIX|\
        DURABLE_WORKFLOW_RUNTIME_URL|DURABLE_WORKFLOW_SERVER_URL|DURABLE_WORKFLOW_NAMESPACE|\
        DURABLE_WORKFLOW_TASK_QUEUE|DURABLE_WORKFLOW_WORKER_ID|\
        DURABLE_WORKFLOW_WORKER_TOKEN|DURABLE_WORKFLOW_CLIENT_TOKEN|\
        DW_CI_CA_SHA256|GITHUB_ACTIONS|CI|TMPDIR|TZ)
            options+=(--env "$variable") ;;
    esac
done < <(compgen -e)

# The host verifier discovers the launcher, but must inspect the real PHAR.
arguments=()
for argument in "$@"; do
    if [[ "$argument" == "$state/bin/composer" ]]; then
        argument=/usr/local/bin/composer
    fi
    arguments+=("$argument")
done
exec docker run "${options[@]}" "$image" "$command" "${arguments[@]}"
