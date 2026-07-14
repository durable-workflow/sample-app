<?php

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/polyglot/conformance/artifacts', function () {
    abort_unless(app()->environment('testing'), 404);

    $version = static function (string $package): ?string {
        try {
            $installed = InstalledVersions::getPrettyVersion($package);
        } catch (\Throwable) {
            return null;
        }

        return is_string($installed) && $installed !== '' ? $installed : null;
    };

    $manifest = static function (string $path): array {
        if (! is_file($path)) {
            return [
                'present' => false,
                'sha256' => null,
                'entries' => null,
            ];
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            return [
                'present' => false,
                'sha256' => null,
                'entries' => null,
            ];
        }

        $entries = json_decode($contents, true);

        return [
            'present' => true,
            'sha256' => hash('sha256', $contents),
            'entries' => is_array($entries) ? $entries : null,
        ];
    };

    $publishedWaterlineManifest = $manifest(public_path('vendor/waterline/mix-manifest.json'));
    $packageWaterlineManifest = $manifest(base_path('vendor/durable-workflow/waterline/public/mix-manifest.json'));

    return response()->json([
        'schema' => 'durable-workflow.sample-app.polyglot-artifacts',
        'artifacts' => [
            'sdk-php' => [
                'artifact' => 'durable-workflow/sdk',
                'version' => $version('durable-workflow/sdk'),
                'role' => 'framework-neutral client and remote worker SDK',
            ],
            'workflow' => [
                'artifact' => 'durable-workflow/workflow',
                'version' => $version('durable-workflow/workflow'),
                'role' => 'embedded Laravel workflow engine',
            ],
            'waterline' => [
                'artifact' => 'durable-workflow/waterline',
                'version' => $version('durable-workflow/waterline'),
            ],
            'apache-avro-php' => [
                'artifact' => 'apache/avro',
                'version' => $version('apache/avro'),
            ],
        ],
        'assets' => [
            'waterline' => [
                'published_manifest' => $publishedWaterlineManifest,
                'package_manifest' => $packageWaterlineManifest,
                'current' => $publishedWaterlineManifest['present']
                    && $packageWaterlineManifest['present']
                    && $publishedWaterlineManifest['sha256'] === $packageWaterlineManifest['sha256'],
            ],
        ],
    ]);
});
