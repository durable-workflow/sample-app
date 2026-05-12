FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

RUN pip install --no-cache-dir "durable-workflow==0.4.22"

COPY activities.py ./activities.py
# Bake the polyglot smoke scripts into the image so the smoke service does
# not depend on a bind mount that some CI runners do not honor in all
# configurations. The python_worker activity service ignores them.
COPY scripts ./scripts

CMD ["python", "activities.py"]
