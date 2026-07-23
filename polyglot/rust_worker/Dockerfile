FROM rust:1.86-bookworm AS build

ARG DURABLE_WORKFLOW_RUST_SDK_VERSION
ARG APACHE_AVRO_RUST_VERSION=0.21.0

WORKDIR /build

COPY Cargo.toml Cargo.lock ./
COPY src ./src

RUN set -eux; \
    test -n "$DURABLE_WORKFLOW_RUST_SDK_VERSION"; \
    cargo add "durable-workflow@=${DURABLE_WORKFLOW_RUST_SDK_VERSION}"; \
    cargo build --release --locked; \
    cargo tree --locked -i "durable-workflow@${DURABLE_WORKFLOW_RUST_SDK_VERSION}"; \
    cargo tree --locked -i "apache-avro@${APACHE_AVRO_RUST_VERSION}"

FROM debian:bookworm-slim

ARG DURABLE_WORKFLOW_RUST_SDK_VERSION
ARG APACHE_AVRO_RUST_VERSION=0.21.0

ENV DURABLE_WORKFLOW_RUST_SDK_VERSION=${DURABLE_WORKFLOW_RUST_SDK_VERSION} \
    APACHE_AVRO_RUST_VERSION=${APACHE_AVRO_RUST_VERSION}

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY --from=build /build/target/release/durable-workflow-polyglot-rust-worker /usr/local/bin/polyglot-rust-worker

USER 65532:65532

ENTRYPOINT ["polyglot-rust-worker"]
