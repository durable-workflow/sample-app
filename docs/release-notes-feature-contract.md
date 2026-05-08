# Release-Notes Feature Contract

This file is the contract between an upstream Durable Workflow package
release and the Sample App. It answers two questions:

1. When does an upstream feature *require* a sample-app citation in
   its release notes?
2. What does that citation have to look like for the release-tag
   review to pass?

The contract is short on purpose. The point is that maintainers can
read it once, then use the checklist at the bottom of this page when
they tag a release.

## When a citation is required

An upstream feature's release-note entry must cite a sample-app
workflow when *any* of the following are true:

- the feature changes the public authoring surface in a way an
  external developer would discover by reading sample workflow code
  (new `Workflow::*` facade, new attribute, new function under
  `Workflow\V2`, new option on an existing function);
- the feature changes behavior an external developer would discover
  by reading the matching Waterline screen (a new typed history event,
  a new lineage relationship, a new run-status transition);
- the feature is the resolution of a sample-app bug reproducer that
  landed under `app/Workflows/Bug/<short-id>/` — the release notes
  cite the reproducer workflow class so external readers can rerun
  the fix against the exact same code.

A citation is *not* required for:

- internal refactors, performance fixes, or telemetry changes that
  are invisible from sample workflow code;
- changes to packages that the sample app does not exercise (for
  example, polyglot worker-protocol details that no sample workflow
  reaches);
- security-driven patches whose fix is a no-op from the workflow
  author's perspective. Those still ship in release notes, but the
  citation requirement does not block the tag.

When a feature is on the boundary (a new option that is invisible
unless someone uses it), default to citing the sample. A release with
one extra link is cheap; a release that ships without a citation a
reader needed is expensive to backfill.

## What a citation looks like

A release-note entry that cites a sample names three things:

1. **The workflow class** that exercises the feature, with its
   namespace path (for example,
   `App\Workflows\Ai\AiWorkflow`).
2. **The artisan command** a reader runs to see the feature in
   action (for example, `php artisan app:ai`).
3. **A link** to the workflow class in the sample-app repository on
   the `main` branch.

A worked example:

> Added `Workflow::outbox()->sendReference()` for durable
> reference-only outbox writes. See
> [`App\Workflows\Ai\AiWorkflow`](https://github.com/durable-workflow/sample-app/blob/main/app/Workflows/Ai/AiWorkflow.php)
> (`php artisan app:ai`) for the full pattern.

The link is what readers click to leave the snippet and run the
sample. Citations that name the class but do not link to it are
treated as drafts, not finished release-note entries.

## Cadence and gaps

The
[Sample-App Plan](https://github.com/durable-workflow/workflow/blob/v2/docs/sample-app/plan.md)
ties this contract to the Phase 4 cadence promise: the sample app's
pinned `durable-workflow/workflow` version moves within one release
cycle of every upstream tag, and the upstream-coverage manifest names
the surfaces the sample is expected to demonstrate. When an upstream
release introduces a feature surface the sample app does not yet
cover:

- the manifest entry stays `gap` until the matching sample lands;
- the release-note entry cites the open `sample-request` issue
  instead of a workflow class so readers see the gap is tracked;
- the cadence window starts at the upstream tag, so the sample lands
  in the next release cycle at the latest.

## Maintainer checklist (run before tagging an upstream release)

Use this list inside the release-tag PR for the workflow package, the
server, the CLI, or the SDK that the release covers.

- [ ] Each release-note entry that meets the "citation required"
      bar names the sample-app workflow class.
- [ ] Each citation links to the workflow class on
      `https://github.com/durable-workflow/sample-app` on the
      `main` branch.
- [ ] Each citation names the artisan command a reader runs.
- [ ] Any feature without a sample yet has an open
      `sample-request` issue and a `gap` row in
      `docs/upstream-coverage.yaml`; the release notes cite the
      issue instead of inventing a class name.
- [ ] The upstream-coverage manifest's `tracked_workflow_version`
      will move to the new tag in the same release cycle.

A release that ticks every box satisfies the Phase 5 release-note
contract for the sample-app plan and the Phase 4 cadence promise in
the same review.

## See also

- [Sample-App Plan, Phase 5](https://github.com/durable-workflow/workflow/blob/v2/docs/sample-app/plan.md)
  — the contract this file implements.
- [Contribute a Sample](https://durable-workflow.github.io/docs/2.0/contribute-a-sample)
  — what a sample needs to look like before it can be cited.
- [Sample Index](../README.md#sample-index) — the source of truth for
  which patterns the sample app currently demonstrates.
