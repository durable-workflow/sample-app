# Rust namespace execution

Run published Rust clients and workers in two namespaces with the same queue,
workflow type and activity type.

```bash
while IFS= read -r assignment; do export "$assignment"; done \
  < <(scripts/resolve-current-artifacts.sh)
SDK_NAMESPACES_COMPOSE_PROJECT_NAME=sample-app-sdk-namespaces scripts/sdk-namespaces.sh
```

Requires Docker Compose, jq and Node.js for the artifact resolver. The resolver freezes published packages and the
published Server digest. The command builds the existing Rust and observation
images, starts an isolated MySQL/Redis/Server stack, and removes project
containers, networks, volumes and fixture images on exit. Use a fresh project
name for concurrent runs. No product source overlay or customer runtime is used.

After an interruption that prevents the exit trap from running, remove the same
project with both Compose files:

```bash
COMPOSE_PROJECT_NAME=sample-app-sdk-namespaces docker compose \
  -f polyglot/docker-compose.yml -f polyglot/docker-compose.namespaces.yml \
  down --volumes --remove-orphans
```

The public Server API creates two disposable namespaces and namespace-bound
operator and worker credentials. Rust clients start four runs. Server reserves
workflow IDs across namespace boundaries, so successful runs use distinct IDs.
Attempting to reuse the other namespace's workflow ID must return
`workflow_id_reserved_in_namespace` with HTTP 409. Each Rust worker has only its
own worker credential. Callers have only their operator credential. A client
with both credentials also proves that control and worker requests select the
correct credential.
Its empty-queue credential probe returns immediately instead of occupying an
additional long-poll slot alongside the actual workers.

The SDK reports typed missing-role diagnostics when only the opposite role's
credential is configured. Supplying that credential for the wrong plane must
also produce an explicit Server authorization failure.

The experiment requires explicit authorization failures for wrong-role polls
and reads, and for cross-namespace reads, signals, starts, registration and
polls. A namespace-bound credential is also refused in the default namespace
and an unknown namespace. Looking up a workflow that exists only in the other
namespace returns `instance_not_found`. Denied signals cannot enter history,
and denied starts cannot leave a workflow behind. Operator credentials may
perform diagnostic registration, as documented by Server. That operation alone
does not grant worker polling authority.

After all four remote activities complete and workflows park at their declared
signal waits, both worker processes receive SIGKILL. Rust callers acknowledge
completion signals while the workers are absent. Fresh worker containers must
complete the original runs with their original activity and signal-wait
identities. The persisted results identify the expected namespace and worker,
with one signal application and one workflow completion per run.

JSON scenario records and the exact tuple appear in normal command and Actions
logs. A failed command includes project logs before cleanup. This covers Rust
namespace selection, role credentials, execution and recovery. Namespace
administration is performed by the public Server API, not a Rust CRUD API.

The public credential API rotates all four existing operator/worker credentials
while the Rust workers are absent. Credential IDs, actor subjects, roles,
namespace bindings and claims must remain unchanged. Retrying the same rotation
must preserve its original timestamp. Actual Rust clients using each old
credential must receive HTTP 401 with `unauthorized` for a control read or worker
poll. New operator credentials acknowledge the signals, and fresh Rust workers
use the new worker credentials to finish the original runs. The original start
actors and final signal/completion actors remain the same authenticated subjects.
Rotation receipts contain identity and authority metadata, without tokens.

## Query, cancellation and failed-workflow actors

After the original runs complete, real Rust callers query a selected original
run in each namespace. The result deliberately includes an application-supplied
`principal` claiming to be Mallory. That value is ordinary application data. The
gateway records Server's separate top-level audit principal from the actual
query response. It must identify the authenticated operator, match the selected
run and remain distinct from the forged application value. Queries must leave
the completed run's entire history unchanged.

Two additional Rust workflows fail deliberately, and two park at declared
signal waits before terminal cancellation through `Client::cancel_workflow`.
Failures must record the authenticated worker, and terminal cancellations the
authenticated operator. Each original run closes once. Cancellation preserves
the committed wait history and never resumes the workflow. Starts, queries,
terminal decisions and cancellations require successful gateway receipts with
the same forged metadata matrix. Cooperative cleanup and cancellation cascades
are separate cases in the child-workflow and timer examples.

## Anonymous actors

The final phase first requires a real Rust request without credentials to receive
HTTP 401 from the token-authenticated Server. After all named-credential checks
finish, it stops those workers and recreates only the disposable Server frontend
with `DW_AUTH_DRIVER=none`. An additional Rust worker and all Rust callers send
no authentication credentials. The same isolated backend preserves earlier
histories, and the whole project is removed on exit.

An anonymous Rust workflow executes a remote activity, reaches its declared
signal wait, receives a signal and completes its original run once. Its query
leaves history unchanged. Additional workflows fail deliberately or receive
terminal cancellation after a declared wait. All caller-controlled history
events and query audit metadata must record `{"type":"server","id":"anonymous"}`.
The published response also labels the auth-disabled role `Admin`.
The forged application query value remains ordinary data. Every successful
operation still carries the forged body/header matrix, and the gateway observes
that its actual Authorization header is absent without recording credential
values. Null, named and forged actors cannot stand in for anonymous attribution.
This phase checks auth-disabled self-hosting, not anonymous access to Cloud or
namespace isolation without authentication.

## Actor identity and forged metadata

All Rust requests pass through an isolated fixture gateway. It retains the
SDK's Authorization, namespace and protocol headers and its encoded workflow,
activity and signal payloads. It adds the principal contract's five forged
identity body fields to starts, signals and task completions, and eleven forged
identity or gateway headers to the requests. Forwarding tests inspect the
actual upstream request and ensure receipts exclude credentials.

The history observer requires `auth:runtime-token` with the namespace's operator
subject for `WorkflowStarted` and `SignalReceived`, and its worker subject for
`WorkflowCompleted`. Missing identities, forged values and foreign namespace
actors fail the command. The initial actor remains recorded through SIGKILL and
the replacement worker's completion. Activity execution and result identities
are checked separately. Internal events may have an explicit null principal.

The gateway records successful injected requests for all four starts, signals,
remote activity attempts and workflow completions. A denied request or poll
cannot substitute for a successful mutation. This exercises actual published
Rust client and worker calls with transport-level injection. It does not add a
custom-header SDK API or use raw HTTP as a replacement Rust implementation.

## Operator visibility

The published native CLI reads every original completed, failed and cancelled
Rust run in both JSON and human table formats. Its complete history must match
Server's preserved events, with each actor displayed on the correct table row.

An isolated Laravel host installs the frozen published Waterline and PHP SDK
packages. Waterline uses its read-only remote backend with the namespace's
rotated operator credential, then no credential in the anonymous phase.
Its selected-run API must preserve the original instance, run, namespace,
terminal status, complete timeline and command actors. The host cannot reach
Server's database. An installed-artifact probe records the actual package
versions, and history reads before and after inspection must be identical.
Raw JSON views and CLI tables join the retained scenario evidence.

These checks exercise the CLI and Waterline API against actual Rust executions.
They do not exercise Waterline's rendered browser interface.

Set `SDK_NAMESPACES_RESULT_DIR` to retain raw histories, gateway receipts and
physical worker-loss records. The hosted job retains these for 30 days. Runtime
containers, fixture images, volumes, networks and the disposable proof directory
are removed on exit. This focused case covers named runtime credentials,
start/signal/completion/query/failure/terminal-cancellation attribution,
credential rotation and cold replay.
Cooperative-cancellation attribution remains a separate principal-contract case.
The focused query case
observes Server's response metadata without adding a Rust raw-response API.
