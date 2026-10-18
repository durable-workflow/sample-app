<?php

namespace Tests\Unit;

use App\Support\WaterlineAssets;
use PHPUnit\Framework\TestCase;

class WaterlineAssetsTest extends TestCase
{
    private string $tmpDir;

    public function test_bundled_assets_match_the_installed_package(): void
    {
        $root = dirname(__DIR__, 2);
        $package = $root.'/vendor/durable-workflow/waterline/public';
        $this->assertFileExists($package.'/mix-manifest.json');
        $assets = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $package,
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($assets as $asset) {
            $relative = substr($asset->getPathname(), strlen($package) + 1);
            foreach (['public/vendor/waterline', 'microservice/public/vendor/waterline'] as $directory) {
                $published = $root.'/'.$directory.'/'.$relative;
                $this->assertFileExists($published);
                $this->assertSame(
                    hash_file('sha256', $asset->getPathname()),
                    hash_file('sha256', $published),
                    $directory.'/'.$relative,
                );
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/waterline-assets-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    public function test_no_warning_when_package_is_not_installed(): void
    {
        $published = $this->tmpDir.'/published.json';
        $package = $this->tmpDir.'/missing.json';
        file_put_contents($published, '{}');

        $this->assertNull(WaterlineAssets::driftMessage($published, $package));
    }

    public function test_warning_when_package_present_but_published_is_missing(): void
    {
        $published = $this->tmpDir.'/missing.json';
        $package = $this->tmpDir.'/package.json';
        file_put_contents($package, '{}');

        $message = WaterlineAssets::driftMessage($published, $package);

        $this->assertIsString($message);
        $this->assertStringContainsString('missing', strtolower($message));
    }

    public function test_no_warning_when_manifests_match(): void
    {
        $contents = '{"/app.js":"/app.js?id=abc"}';
        $published = $this->tmpDir.'/published.json';
        $package = $this->tmpDir.'/package.json';
        file_put_contents($published, $contents);
        file_put_contents($package, $contents);

        $this->assertNull(WaterlineAssets::driftMessage($published, $package));
    }

    public function test_warning_when_manifests_drift(): void
    {
        $published = $this->tmpDir.'/published.json';
        $package = $this->tmpDir.'/package.json';
        file_put_contents($published, '{"/app.js":"/app.js?id=old"}');
        file_put_contents($package, '{"/app.js":"/app.js?id=new"}');

        $message = WaterlineAssets::driftMessage($published, $package);

        $this->assertIsString($message);
        $this->assertStringContainsString('out of date', strtolower($message));
    }
}
