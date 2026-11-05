# Development image build inputs

The development recipe uses the same pinned PHP 8.4.27 Bookworm index for its
Brotli builder and final stage, on both amd64 and arm64. Its shared APT preflight
comes from the accepted immutable source in
[central `docker/apt`](https://github.com/durable-workflow/.github/tree/18783be6bef737cc778e4c7282a8797e39daa61a/docker/apt).
That source records the exact parent descriptors and publisher SPDX package
inventory; installed-image acceptance is a separate result.

Both image workflows check out that exact central commit without persisted
credentials into `.central-apt-source`, then verify its actual HEAD and the
preflight file's SHA-256 before preparing Buildx. The disposable checkout is
Git-ignored; its Git metadata is excluded from the Docker context. The Dockerfile
copies the central file directly from that local context into each PHP stage.
A missing checkout or file fails the build; there is no external-image fallback
or repository-local guard implementation.

The preflight runs before each stage's first RUN reaches the Brotli APT helper,
then again before the final stage's direct APT block and Playwright dependency
installation. It uses stock shell/dpkg and needs no Python or package acquisition
to establish the installed native APT/libapt pair.
The shared startup qualification invokes it again inside the completed image
after application preparation, retaining an explicit final installed-pair result.

The PR workflow builds untrusted native candidates with publication disabled.
The seven-job publisher remains a separate explicit main-only dispatch, including
its existing scan, anonymous/native qualification and promotion gates. Publisher
inventory does not establish installed-image acceptance. Publication and
production deployment retain their separate decisions. See
[#318](https://github.com/durable-workflow/sample-app/issues/318) for the current
adoption and effect decisions.
