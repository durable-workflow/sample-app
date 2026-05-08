# Contributing a Sample

Thanks for considering a contribution to the Durable Workflow Sample
App. The sample app is the canonical place to show what a Durable
Workflow pattern looks like in a real Laravel project, so the bar for
new samples is "another engineer can read the workflow class, run one
artisan command, and learn the pattern from a real run".

This file is the repository-side mirror of the
[Contribute a Sample](https://durable-workflow.github.io/docs/2.0/contribute-a-sample)
guide on the docs site. The docs-site page is the canonical version;
this file stays terse and links back when contributors want the full
discussion.

## Before you write code

1. **Open a `sample-request` issue first.** The
   [`sample-request` template](https://github.com/durable-workflow/sample-app/issues/new/choose)
   captures the pattern surface, the public docs page that defines
   it, and the minimum Durable Workflow package version it needs.
   Maintainers and the contributor agree the sample is worth merging
   *before* a PR is opened, so nobody invests in scaffolding that
   later turns out to duplicate an existing sample.
2. **Pick a pattern surface that is not already covered.** The
   [Sample Index](README.md#sample-index) names every covered pattern.
3. **Read the closest existing workflow.** A new sample should look
   like the one next to it — same directory layout, same artisan
   command shape, same testing posture.

## What a merged sample includes

Each merged sample ships four artifacts in this repository:

1. A **workflow class** under `app/Workflows/<Pattern>/` (or
   `app/Workflows/Bug/<short-id>/` for a reproducer). Workflow code
   stays deterministic — clock reads behind `sideEffect()`, external
   work inside activities, waits behind signals, updates, timers, or
   message streams.
2. An **artisan command** named `app:<short-pattern>` that starts the
   workflow with realistic input.
3. A **`config/workflow_mcp.php` entry** that names the workflow class,
   pattern, command, required credentials, and arguments. The
   upstream-coverage lint refuses to mark a coverage row `covered`
   without this entry.
4. A **test under `tests/`** that exercises the workflow against the
   in-memory v2 worker and proves the workflow completes for the
   input the artisan command passes.

## What a merged sample requires on the docs site

Three docs surfaces move in the same change:

- A row in the [Sample Index](README.md#sample-index).
- A row in the docs-site
  [Sample gallery](https://durable-workflow.github.io/docs/2.0/sample-app#sample-gallery)
  that names the Waterline screen the run produces.
- A cross-link from the matching pattern page on the docs site
  (sagas, signals, message streams, child-workflows, …) to the
  workflow class.

A sample that lands without the docs-site mirror is held in `gap`
state by the upstream-coverage tracker until the docs PR catches up.
Bundle them in a single PR when you can.

## Quick checklist

Use this list when you open the PR. Maintainers run the same list
during review.

- [ ] `sample-request` issue exists and links to the public pattern
      docs page.
- [ ] Workflow class under `app/Workflows/<Pattern>/`.
- [ ] Artisan command registered with the `app:<short-pattern>` name.
- [ ] `config/workflow_mcp.php` entry with class, pattern, command,
      requires, and arguments.
- [ ] Test that exercises the workflow end to end.
- [ ] [Sample Index](README.md#sample-index) row.
- [ ] Docs-site gallery row.
- [ ] Cross-link from the matching pattern page.
- [ ] Public-boundary scan clean
      (`scripts/check-public-boundary.sh`).

## Bugs vs. samples

Bugs in the workflow engine itself or in the standalone Durable
Workflow server belong on the
[`workflow`](https://github.com/durable-workflow/workflow/issues/new/choose)
and [`server`](https://github.com/durable-workflow/server/issues/new/choose)
repos respectively. The
[issue chooser](.github/ISSUE_TEMPLATE/config.yml) routes those out so
they do not pollute the sample-app reproducer queue. A bug *that
reproduces in this app* lands here as a workflow under
`app/Workflows/Bug/<short-id>/` and stays covered by CI after the fix.

## Public-boundary discipline

This is a public repository. Do not add private tracker names,
workspace-only absolute paths, or loop/lane metadata to files or new
commit metadata. `scripts/check-public-boundary.sh` runs before every
push, and the same scan runs in CI on pull requests.
