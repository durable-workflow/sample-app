# Bookworm Brotli verification

`version` pins Debian's reviewed Bookworm security package for
[CVE-2026-111497](https://security-tracker.debian.org/tracker/CVE-2026-111497).
`install-vendor.sh` installs that exact runtime and development package version
through APT's authenticated package metadata. Both image families use it before
running network clients and verify the decoder again after later package and
browser installation.

The verifier build uses the same immutable PHP base as the final image. It
compiles the existing small system-library checker against the vendor headers
and libraries. Verification checks the fixed error-name marker and an ordinary
valid-data encode/decode round trip. It does not execute malformed-stream or
upstream test corpora.

The final PHP guard checks the exact package, refuses stray decoder copies, and
inspects dynamic consumers in the image's tool, application and browser roots.
Existing Node checks separately verify its embedded decoder. Both final image
recipes and their native qualification run these guards.

Debian retains the upstream version string while backporting the fix. Keep
scanner results alongside the vendor package revision and actual runtime checks.
Do not claim an upstream version upgrade or suppress a finding without review.
