#!/bin/sh
# Same-base Bookworm backport of Google Brotli fix 50a9b70a4d5e1443fc40927952276b68fffa83a0.
# The patched decoder/header match upstream v1.0.10; upstream version metadata
# remains 1.0.9. The local revision is superseded by future Debian updates.
set -eu

brotli_inputs=$(dirname "$(readlink -f "$0")")
brotli_version=$(cat "$brotli_inputs/version")
brotli_work=/usr/src/sample-brotli-build
brotli_arch=$(dpkg --print-architecture)
brotli_multiarch=$(dpkg-architecture -qDEB_HOST_MULTIARCH)

dpkg --compare-versions "$brotli_version" gt 1.0.9-2+b6
dpkg --compare-versions "$brotli_version" lt 1.0.9-2+b7
dpkg --compare-versions "$brotli_version" lt 1.0.9-2+deb12u1
sed -i 's/^Types: deb$/Types: deb deb-src/' /etc/apt/sources.list.d/debian.sources
apt-get update
apt-get install -y --no-install-recommends dpkg-dev
mkdir -p "$brotli_work"
cd "$brotli_work"
apt-get source --download-only brotli=1.0.9-2
sha256sum -c "$brotli_inputs/source.sha256"
dpkg-source -x brotli_1.0.9-2.dsc brotli-1.0.9
cd brotli-1.0.9
apt-get build-dep -y --no-install-recommends -P nopython .

printf '%s  %s\n' \
    45a46c6b5df8f7b103f343d515c43e8856d9eb057f707e8667a559f1d1e04817 \
    "$brotli_inputs/CVE-2026-111497.patch" | sha256sum -c -
cp "$brotli_inputs/CVE-2026-111497.patch" debian/patches/
printf '\nCVE-2026-111497.patch\n' >> debian/patches/series
dpkg-source --before-build .
sha256sum -c "$brotli_inputs/patched-files.sha256"

{
    printf 'brotli (%s) bookworm-security; urgency=high\n\n' "$brotli_version"
    printf '  * Durable Workflow backport of upstream 50a9b70 (v1.0.10),\n'
    printf '    correcting CVE-2026-111497 without masking a vendor update.\n\n'
    printf ' -- Durable Workflow <support@durable-workflow.com>  Thu, 22 Oct 2026 00:00:00 +0000\n\n'
    cat debian/changelog
} > debian/changelog.new
mv debian/changelog.new debian/changelog
cat >> debian/rules <<'RULES'

override_dh_auto_configure:
	dh_auto_configure -- -DBROTLI_DISABLE_TESTS=ON

override_dh_auto_test:
	@printf '%s\n' 'Upstream corpora are not executed; marker and valid-data checks run separately.'
RULES

mkdir -p /out
cc -O2 -I c/include "$brotli_inputs/verify-system-brotli.c" \
    "/usr/lib/$brotli_multiarch/libbrotlidec.so.1" \
    "/usr/lib/$brotli_multiarch/libbrotlienc.so.1" -ldl -o /out/verify-system-brotli
set +e
/out/verify-system-brotli
brotli_baseline_exit=$?
set -e
test "$brotli_baseline_exit" -eq 1

DEB_BUILD_PROFILES=nopython DEB_BUILD_OPTIONS='nocheck noddebs parallel=1' \
    dpkg-buildpackage -B -uc -us -Pnopython
cp "$brotli_work/libbrotli1_${brotli_version}_${brotli_arch}.deb" /out/
cp "$brotli_work/libbrotli-dev_${brotli_version}_${brotli_arch}.deb" /out/
cp "$brotli_inputs/version" /out/version
cp "$brotli_inputs/verify-runtime.php" /out/verify-runtime.php
dpkg-deb --field "/out/libbrotli1_${brotli_version}_${brotli_arch}.deb" Package Version Architecture
