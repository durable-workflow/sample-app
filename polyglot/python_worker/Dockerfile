FROM python:3.12-slim

ARG DURABLE_WORKFLOW_CLI_VERSION=0.1.76
ARG DURABLE_WORKFLOW_PYTHON_SDK_VERSION=0.4.84

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    DURABLE_WORKFLOW_CLI_VERSION=${DURABLE_WORKFLOW_CLI_VERSION} \
    DURABLE_WORKFLOW_CLI_PIN=dw==${DURABLE_WORKFLOW_CLI_VERSION} \
    DURABLE_WORKFLOW_PYTHON_SDK_VERSION=${DURABLE_WORKFLOW_PYTHON_SDK_VERSION}

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL https://durable-workflow.com/install.sh -o /tmp/dw-install.sh \
    && VERSION="${DURABLE_WORKFLOW_CLI_VERSION}" DURABLE_WORKFLOW_INSTALL_DIR=/usr/local/bin sh /tmp/dw-install.sh \
    && rm /tmp/dw-install.sh \
    && dw --version

RUN pip install --no-cache-dir "durable-workflow==${DURABLE_WORKFLOW_PYTHON_SDK_VERSION}"

COPY activities.py ./activities.py
# Bake the polyglot smoke scripts into the image so the smoke service does
# not depend on a bind mount that some CI runners do not honor in all
# configurations. The python_worker activity service ignores them.
COPY scripts ./scripts

CMD ["python", "activities.py"]
