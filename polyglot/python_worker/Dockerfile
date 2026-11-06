FROM python:3.12-slim-bookworm@sha256:935c3c5b12357a7e10caa9c1e6ca5692344d97cf229832ef584be2cec9fc03df

ARG DURABLE_WORKFLOW_CLI_VERSION
ARG DURABLE_WORKFLOW_PYTHON_SDK_VERSION
ARG FASTAVRO_PYTHON_VERSION=1.12.2

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    DURABLE_WORKFLOW_CLI_VERSION=${DURABLE_WORKFLOW_CLI_VERSION} \
    DURABLE_WORKFLOW_CLI_PIN=dw==${DURABLE_WORKFLOW_CLI_VERSION} \
    DURABLE_WORKFLOW_PYTHON_SDK_VERSION=${DURABLE_WORKFLOW_PYTHON_SDK_VERSION}

WORKDIR /app

COPY .central-apt-source/docker/apt/require-fixed-bookworm-apt.sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh
COPY .central-apt-source/.github/actions/bootstrap-patched-pip/export-trust.py /usr/local/share/sample-app/python/export_trust.py
COPY docker/python /usr/local/share/sample-app/python
COPY .devcontainer/docker/verify-python-trust.py /usr/local/share/sample-app/verify-python-trust.py

RUN set -eux; \
    /bin/sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh; \
    python3 /usr/local/share/sample-app/python/prepare-trust.py; \
    set -a; . /usr/local/share/sample-app/python-bootstrap/trust.env; set +a; \
    test -n "$DURABLE_WORKFLOW_CLI_VERSION"; \
    test -n "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION"; \
    apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl git \
    && rm -rf /var/lib/apt/lists/* \
    && curl --proto '=https' --proto-redir '=https' -fsSL https://durable-workflow.com/install.sh -o /tmp/dw-install.sh \
    && VERSION="${DURABLE_WORKFLOW_CLI_VERSION}" DURABLE_WORKFLOW_INSTALL_DIR=/usr/local/bin sh /tmp/dw-install.sh \
    && rm /tmp/dw-install.sh \
    && dw --version

RUN set -eux; \
    set -a; . /usr/local/share/sample-app/python-bootstrap/trust.env; set +a; \
    python3 /usr/local/share/sample-app/python/build-pip.py /tmp/sample-app-python-wheels; \
    python3 -I -m pip --isolated --disable-pip-version-check install \
        --no-index --no-deps --upgrade /tmp/sample-app-python-wheels/*.whl; \
    rm -rf /tmp/sample-app-python-wheels; \
    python3 /usr/local/share/sample-app/verify-python-trust.py --pip-only; \
    python3 -I -c 'import json, platform, pip; from importlib.metadata import version; from pip._vendor import urllib3; print(json.dumps({"python": platform.python_version(), "pip": pip.__version__, "urllib3": urllib3.__version__, "setuptools": version("setuptools")}))'; \
    python3 -I -m pip --isolated --disable-pip-version-check install \
        --cert "$SSL_CERT_FILE" --no-cache-dir \
        "fastavro==${FASTAVRO_PYTHON_VERSION}" \
        "durable-workflow==${DURABLE_WORKFLOW_PYTHON_SDK_VERSION}"; \
    python3 /usr/local/share/sample-app/verify-python-trust.py; \
    /bin/sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh; \
    apt-get purge -y --auto-remove git; \
    rm -rf /var/lib/apt/lists/*

COPY polyglot/python_worker/activities.py ./activities.py
# Bake the polyglot smoke scripts into the image so the smoke service does
# not depend on a bind mount that some CI runners do not honor in all
# configurations. The python_worker activity service ignores them.
COPY polyglot/python_worker/scripts ./scripts

CMD ["python", "activities.py"]
