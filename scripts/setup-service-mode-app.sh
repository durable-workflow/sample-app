#!/usr/bin/env bash

set -euo pipefail

role="${1:?Usage: setup-service-mode-app.sh worker|observer}"

composer_flags=(
    --with-all-dependencies
    --no-dev
    --no-scripts
    --no-autoloader
    --prefer-dist
    --no-interaction
)

case "$role" in
    worker)
        : "${DURABLE_WORKFLOW_PHP_SDK_VERSION:?Resolve the current PHP SDK version first}"
        : "${DURABLE_WORKFLOW_WORKFLOW_VERSION:?Resolve the current Workflow version first}"
        composer remove --no-update durable-workflow/waterline
        composer require --no-update \
            "durable-workflow/sdk:${DURABLE_WORKFLOW_PHP_SDK_VERSION}" \
            "durable-workflow/workflow:${DURABLE_WORKFLOW_WORKFLOW_VERSION}"
        composer update durable-workflow/sdk durable-workflow/workflow "${composer_flags[@]}"

        # The service worker registers only framework-neutral SDK handlers. Its
        # transient application copy does not boot the observer-only Waterline
        # provider; the Workflow package remains for the root app's shared routes.
        php -r '
$path = "bootstrap/providers.php";
$source = file_get_contents($path);
if (! is_string($source)) {
    throw new RuntimeException("Could not read {$path}.");
}
$source = str_replace("    App\\Providers\\WaterlineServiceProvider::class,\n", "", $source);
file_put_contents($path, $source);
'
        ;;
    observer)
        : "${DURABLE_WORKFLOW_WORKFLOW_VERSION:?Resolve the current Workflow version first}"
        : "${DURABLE_WORKFLOW_WATERLINE_VERSION:?Resolve the current Waterline version first}"
        composer require --no-update \
            "durable-workflow/sdk:^2.0@RC" \
            "durable-workflow/workflow:${DURABLE_WORKFLOW_WORKFLOW_VERSION}" \
            "durable-workflow/waterline:${DURABLE_WORKFLOW_WATERLINE_VERSION}"
        composer update \
            durable-workflow/sdk \
            durable-workflow/workflow \
            durable-workflow/waterline \
            "${composer_flags[@]}"
        ;;
    *)
        echo "Unknown service-mode application role: ${role}" >&2
        exit 2
        ;;
esac

composer dump-autoload --no-dev --optimize --no-interaction
