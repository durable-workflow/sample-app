# Development image Go tools

The Docker CLI comes from the pinned official Docker image. Compose 5.6.1 and
gosu 1.20 are rebuilt from exact upstream source commits with the pinned official
Go 1.27.3 image on each native image-builder architecture. Source archives are
SHA-256 checked before extraction; module downloads retain Go checksum checks,
and compilation uses `-mod=readonly`, `CGO_ENABLED=0` and `GOTOOLCHAIN=local`.

Upstream Compose 5.6.1 still embeds `golang.org/x/net` 0.58.0. The
zero-context patch selects 0.62.0, fixing CVE-2026-78669 and the HTML-parser
CVE-2026-78661. Its dependency closure selects `x/crypto` 0.58.0, `x/sys`
0.49.0, `x/term` 0.47.0, `x/text` 0.43.0 and `x/sync` 0.24.0, with recorded
`go.sum` checksums from the public Go checksum database. The exact upstream
archive hash protects the patch base. The resulting Compose version is
explicitly `5.6.1+sample.2`.

Upstream gosu 1.20 fixes its user parser through `moby/sys/user` 0.4.1, but its
prebuilt assets still use Go 1.24.6. Building the unchanged source with the current
compiler removes the old embedded toolchain inventory while retaining upstream
module versions and the normal privilege-drop behavior. Its own version reports
1.20 and the actual compiler; build metadata identifies the source-built main
module as `(devel)`, rather than claiming an upstream release binary.

Both final platform inventories must pass the development-image security gate.
Native startup, user switching, Docker socket/Compose configuration and complete
image qualification remain required; a compiler upgrade alone is not acceptance.
