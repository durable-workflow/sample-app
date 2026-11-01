<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BrotliImageContractTest extends TestCase
{
    public function test_vendor_installer_binds_both_packages_to_the_reviewed_version(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().'/sample-brotli-'.bin2hex(random_bytes(8));
        mkdir($directory.'/bin', 0700, true);
        $version = trim(file_get_contents($root.'/docker/brotli/version'));
        file_put_contents($directory.'/version', $version);
        file_put_contents($directory.'/bin/apt-get', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> \"\$BROTLI_TEST_ARGUMENTS\"\n");
        chmod($directory.'/bin/apt-get', 0700);
        try {
            $environment = array_replace(getenv(), [
                'PATH' => $directory.'/bin:'.getenv('PATH'),
                'BROTLI_TEST_ARGUMENTS' => $directory.'/arguments',
            ]);
            $process = proc_open(['sh', $root.'/docker/brotli/install-vendor.sh', $directory, 'gcc', 'libc6-dev'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
            $this->assertIsResource($process);
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output);
            $this->assertSame(['update', 'install', '-y', '--no-install-recommends',
                'libbrotli1='.$version, 'libbrotli-dev='.$version, 'gcc', 'libc6-dev'],
                file($directory.'/arguments', FILE_IGNORE_NEW_LINES));
        } finally {
            foreach (['arguments', 'version', 'bin/apt-get'] as $path) {
                if (is_file($directory.'/'.$path)) {
                    unlink($directory.'/'.$path);
                }
            }
            rmdir($directory.'/bin');
            rmdir($directory);
        }
    }

    public function test_vendor_revision_contains_the_bookworm_security_fix(): void
    {
        $version = trim(file_get_contents(dirname(__DIR__, 2).'/docker/brotli/version'));
        foreach ([['gt', '1.0.9-2+b6'], ['ge', '1.0.9-2+deb12u1']] as [$comparison, $vendor]) {
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
            $install = strpos($runtime, 'RUN sh /usr/local/share/sample-app/brotli/install-vendor.sh ');
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

    public function test_package_builder_supplies_the_composer_archive_required_by_its_trust_guard(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['Dockerfile', '.devcontainer/docker/Dockerfile'] as $path) {
            $source = file_get_contents($root.'/'.$path);
            $start = strpos($source, ' AS brotli-build');
            $builder = substr($source, $start, strpos($source, "\nFROM ", $start) - $start);
            $this->assertStringContainsString('COPY --from=composer /usr/bin/composer /usr/local/bin/composer', $builder);
            $this->assertMatchesRegularExpression('~php /usr/local/share/sample-app/verify-(?:runtime|php)-trust\.php /usr/local/bin/composer~', $builder);
        }
    }

    public function test_full_inventory_uses_the_image_verification_role(): void
    {
        $root = dirname(__DIR__, 2);
        $qualifier = file_get_contents($root.'/scripts/ci/qualify-devcontainer-image.sh');
        $this->assertStringContainsString(
            '"${compose[@]}" exec -T --user root laravel php /usr/local/share/sample-app/brotli/verify-runtime.php',
            $qualifier,
        );
        $this->assertStringNotContainsString(
            '/brotli/verify-runtime.php',
            file_get_contents($root.'/.devcontainer/docker/verify-image.sh'),
        );
        $this->assertStringContainsString(
            '&& php /usr/local/share/sample-app/brotli/verify-runtime.php',
            file_get_contents($root.'/.devcontainer/docker/Dockerfile'),
        );
    }
}
