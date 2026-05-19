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

    return response()->json([
        'schema' => 'durable-workflow.sample-app.polyglot-artifacts',
        'artifacts' => [
            'workflow' => [
                'artifact' => 'durable-workflow/workflow',
                'version' => $version('durable-workflow/workflow'),
            ],
            'waterline' => [
                'artifact' => 'durable-workflow/waterline',
                'version' => $version('durable-workflow/waterline'),
            ],
        ],
    ]);
});
