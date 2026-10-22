<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BrotliImageContractTest extends TestCase
{
    public function test_backport_is_bound_to_the_reviewed_upstream_patch(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertSame(
            '45a46c6b5df8f7b103f343d515c43e8856d9eb057f707e8667a559f1d1e04817',
            hash_file('sha256', $root.'/docker/brotli/CVE-2026-111497.patch'),
        );
        $patch = file_get_contents($root.'/docker/brotli/CVE-2026-111497.patch');
        preg_match_all('~^diff --git a/(\S+) b/(\S+)$~m', $patch, $files);
        $this->assertSame(['c/dec/decode.c', 'c/include/brotli/decode.h'], $files[1]);
    }

    public function test_local_revision_yields_to_the_next_vendor_update(): void
    {
        $version = trim(file_get_contents(dirname(__DIR__, 2).'/docker/brotli/version'));
        foreach ([['gt', '1.0.9-2+b6'], ['lt', '1.0.9-2+b7'], ['lt', '1.0.9-2+deb12u1']] as [$comparison, $vendor]) {
            $process = proc_open(['dpkg', '--compare-versions', $version, $comparison, $vendor], [], $pipes);
            $this->assertIsResource($process);
            $this->assertSame(0, proc_close($process), $version.' '.$comparison.' '.$vendor);
        }
    }

    public function test_both_images_install_same_base_packages_before_network_clients(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['Dockerfile', '.devcontainer/docker/Dockerfile'] as $path) {
            $source = file_get_contents($root.'/'.$path);
            preg_match('~^FROM (\S+) AS brotli-build$~m', $source, $builder);
            preg_match_all('~^FROM (php:\S+)~m', $source, $runtimes);
            $this->assertCount(2, $runtimes[1]);
            $this->assertSame($builder[1], $runtimes[1][1]);
            $runtime = substr($source, strrpos($source, "\nFROM php:"));
            $install = strpos($runtime, 'RUN dpkg -i ');
            $check = strpos($runtime, 'php /usr/local/share/sample-app/brotli/verify-runtime.php');
            $download = strpos($runtime, 'apt-get update');
            foreach ([$install, $check, $download] as $position) {
                $this->assertIsInt($position);
            }
            $this->assertLessThan($check, $install);
            $this->assertLessThan($download, $check);
            $this->assertGreaterThan(strrpos($runtime, 'apt-get install'), strrpos($runtime, 'php /usr/local/share/sample-app/brotli/verify-runtime.php'));
        }
    }

    public function test_native_image_qualification_inspects_the_actual_decoder(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['.github/workflows/smoke.yml', 'scripts/ci/qualify-devcontainer-image.sh'] as $path) {
            $this->assertStringContainsString('php /usr/local/share/sample-app/brotli/verify-runtime.php', file_get_contents($root.'/'.$path));
        }
    }
}
