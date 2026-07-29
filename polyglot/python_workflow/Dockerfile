FROM python:3.12-slim

ARG DURABLE_WORKFLOW_PYTHON_SDK_VERSION
ARG FASTAVRO_PYTHON_VERSION=1.12.2

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    DURABLE_WORKFLOW_PYTHON_SDK_VERSION=${DURABLE_WORKFLOW_PYTHON_SDK_VERSION}

WORKDIR /app

RUN set -eux; \
    test -n "$DURABLE_WORKFLOW_PYTHON_SDK_VERSION"; \
    pip install --no-cache-dir \
        "fastavro==${FASTAVRO_PYTHON_VERSION}" \
        "durable-workflow==${DURABLE_WORKFLOW_PYTHON_SDK_VERSION}"

COPY workflow.py ./workflow.py
COPY replay_fixtures ./replay_fixtures

RUN python workflow.py --replay-fixtures

CMD ["python", "workflow.py"]
