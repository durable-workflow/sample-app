# Bookworm Brotli correction

The pinned PHP base uses Debian's Brotli 1.0.9 package. This directory builds a
same-base Debian backport of Google's
[CVE-2026-111497 fix](https://github.com/google/brotli/commit/50a9b70a4d5e1443fc40927952276b68fffa83a0).
The patched decoder and header must match upstream
[1.0.10](https://github.com/google/brotli/releases/tag/v1.0.10) exactly.

APT authenticates the source index. `source.sha256` binds its three source
artifacts, and the script verifies the vendored patch and both resulting files.
The runtime and development packages keep the honest upstream version 1.0.9
and use revision `1.0.9-2+b6+dw1`. It sorts below the next Debian binary rebuild
and security revision. When Debian publishes its correction, review and replace
this backport with that vendor package.

The build deliberately does not execute upstream test corpora. Verification
checks the added error-name marker and encodes/decodes ordinary valid data.
The final PHP guard checks the exact package, refuses stray decoder copies, and
inspects dynamic consumers in the image's tool, application and browser roots.
Existing Node checks separately verify its embedded decoder's fixed release.
Both final image recipes and their native qualification run these guards.

A scanner that checks only the upstream `1.0.9` string may still report the CVE.
Keep that result alongside the patch hashes, package revision and actual runtime
checks. Do not claim an upstream version upgrade or suppress it without review.
