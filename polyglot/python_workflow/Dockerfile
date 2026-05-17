FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

RUN pip install --no-cache-dir 'durable-workflow==0.4.36'

COPY workflow.py ./workflow.py

CMD ["python", "workflow.py"]
