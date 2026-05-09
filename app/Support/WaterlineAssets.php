<?php

namespace App\Support;

class WaterlineAssets
{
    /**
     * Return a human-readable warning when published Waterline assets drift
     * from the version installed in vendor/, or null when assets are in sync
     * (or the package is not installed).
     *
     * The Waterline dashboard layout already shows its own banner when
     * Waterline::assetsAreCurrent() returns false, but on builds where that
     * check is short-circuited the user may never see the dashboard's
     * warning. Mirroring the check here means the welcome page surfaces it
     * regardless of how the dashboard renders.
     *
     * Path arguments are exposed so callers (typically tests) can compare
     * arbitrary manifest pairs; the defaults resolve to the real on-disk
     * locations the application uses at runtime.
     */
    public static function driftMessage(?string $publishedManifest = null, ?string $packageManifest = null): ?string
    {
        $publishedManifest ??= public_path('vendor/waterline/mix-manifest.json');
        $packageManifest ??= base_path('vendor/durable-workflow/waterline/public/mix-manifest.json');

        if (! is_file($packageManifest)) {
            return null;
        }

        if (! is_file($publishedManifest)) {
            return 'Published Waterline assets are missing.';
        }

        $published = @file_get_contents($publishedManifest);
        $package = @file_get_contents($packageManifest);

        if ($published === false || $package === false) {
            return null;
        }

        if ($published !== $package) {
            return 'Published Waterline assets are out of date with the installed Waterline package.';
        }

        return null;
    }
}
