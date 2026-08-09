#!/usr/bin/env sh

set -eu

runtime_dir="${SERVICE_MODE_PYTHON_RUNTIME_DIR:-/runtime}"
python_binary="${SERVICE_MODE_PYTHON_BINARY:-python}"
semantic_version="${DURABLE_WORKFLOW_PYTHON_SDK_VERSION:?Resolve the current Python SDK version first}"

prerelease="${semantic_version#*-}"
case "$prerelease" in
    alpha.*)
        python_version="${semantic_version%%-*}a${prerelease#alpha.}"
        ;;
    beta.*)
        python_version="${semantic_version%%-*}b${prerelease#beta.}"
        ;;
    rc.*)
        python_version="${semantic_version%%-*}rc${prerelease#rc.}"
        ;;
    *)
        echo "Unsupported Python SDK version: ${semantic_version}" >&2
        exit 2
        ;;
esac

installed=""
if [ -x "${runtime_dir}/bin/python" ]; then
    installed="$(
        "${runtime_dir}/bin/python" -c \
            'import importlib.metadata as m; print(m.version("durable-workflow"))' \
            2>/dev/null || true
    )"
fi

if [ "$installed" = "$python_version" ]; then
    echo "Reusing durable-workflow ${python_version} from ${runtime_dir}."
    exit 0
fi

find "$runtime_dir" -mindepth 1 -maxdepth 1 -exec rm -rf -- '{}' +
"$python_binary" -m venv "$runtime_dir"
"${runtime_dir}/bin/pip" install \
    --no-input \
    --disable-pip-version-check \
    "durable-workflow==${python_version}"

installed="$(
    "${runtime_dir}/bin/python" -c \
        'import importlib.metadata as m; print(m.version("durable-workflow"))'
)"
if [ "$installed" != "$python_version" ]; then
    echo "Installed Python SDK ${installed}; expected ${python_version}." >&2
    exit 1
fi

chmod -R a+rX "$runtime_dir"
