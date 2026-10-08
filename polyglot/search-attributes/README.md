# Rust search attributes

This experiment runs the published Rust SDK against a published Server.
PHP and Python SDKs inspect values and visibility queries. Rust authors all
seven typed values, parks at a signal, and updates and deletes attributes after
a fresh worker replays the original history.

```bash
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
SDK_SEARCH_ATTRIBUTES_COMPOSE_PROJECT_NAME=sample-app-search-attributes \
  scripts/sdk-search-attributes.sh
```

Requires Docker Compose and jq. The resolver selects the seven published
artifacts. The command builds registry-only SDK consumers, starts an isolated
MySQL/Redis/Server stack, and removes project containers, volumes, networks and
fixture images on exit. Use a unique project name for concurrent runs.
`SDK_SEARCH_ATTRIBUTES_RESULT_DIR` optionally retains raw observations. These
include exact attribute values and types, visibility queries, histories and
physical SIGKILL/replacement receipts. Normal logs identify the tuple and times.

The Rust workflow writes all seven types, including a 2048-byte Unicode string,
a 255-byte Unicode keyword and list entry, an integer larger than JavaScript's
exact range, a fractional float, a boolean and a datetime with microseconds.
Server schema administration registers the numeric type as `double`. The Rust
workflow and durable history use its canonical `float` identity.
PHP and Python inspect selected-run values and equality, integer/float range,
boolean, list membership and datetime queries. Nonmatching queries must return
no workflow. The UTF-8 limits are bytes at Server's public API boundary.

After the original upsert and signal wait are persisted, the worker receives
SIGKILL. The command requires exit137 without OOM and acknowledges a signal
while the worker remains absent. A distinct replacement must replay the same
run, original upsert and signal boundary, perform one new mutation and deletion,
and complete once. Both observer SDKs require the final values and matching
history. Deleted and replaced values must stop matching visibility queries.

After interruption that prevents the exit trap, remove that same project:

```bash
COMPOSE_PROJECT_NAME=sample-app-search-attributes \
SEARCH_ATTRIBUTES_UID=$(id -u) SEARCH_ATTRIBUTES_GID=$(id -g) \
SEARCH_ATTRIBUTES_PROOF_DIR=/path/to/the/printed/disposable/proof \
docker compose -f polyglot/docker-compose.yml \
  -f polyglot/docker-compose.search-attributes.yml down --volumes --remove-orphans
```

Rust currently has workflow-side typed updates. Schema administration and
visibility filters use the supported PHP and Python clients. Raw HTTP from a
Rust process is not a Rust SDK visibility API.

This focused experiment does not replace Server's PHP/Python search-attribute
runner or qualify Waterline, namespace isolation, load latency, continue-as-new
inheritance or application-code upgrades.
