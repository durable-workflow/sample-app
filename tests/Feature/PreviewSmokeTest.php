<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Smoke checks for the routes a fresh Codespaces preview is expected to
 * render: the welcome page and the Waterline dashboard. These go beyond
 * a 200 status check — they assert that the response actually contains
 * the markup a working render produces, and that asset URLs do not
 * point at a forwarded dev-server port that the preview origin cannot
 * reach.
 */
class PreviewSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The Waterline dashboard sits behind an authorization callback that,
        // in non-local environments, requires the requesting user to be on
        // the gate's allow-list. This smoke check is verifying the rendered
        // chrome of the dashboard, not its authorization rules — so allow
        // the request through unconditionally for the duration of the test.
        if (class_exists(\Waterline\Waterline::class)) {
            \Waterline\Waterline::auth(static fn () => true);
        }
    }

    /**
     * Simulate a request reaching Laravel via the Codespaces preview edge:
     * inbound HTTP from the proxy, X-Forwarded-Proto: https, X-Forwarded-Host
     * pointing at the preview hostname.
     */
    private function withPreviewProxyHeaders(): array
    {
        return [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'codespace-fake-80.app.github.dev',
            'HTTP_X_FORWARDED_PORT' => '443',
        ];
    }

    public function test_root_page_renders_without_unreachable_vite_dev_server_assets(): void
    {
        // Pretend Vite hot mode is active locally and we're inside Codespaces.
        $hotFile = public_path('hot');
        $hotFileBackup = is_file($hotFile) ? file_get_contents($hotFile) : null;
        @file_put_contents($hotFile, "http://[::1]:15173\n");

        $previousCodespace = getenv('CODESPACE_NAME');
        putenv('CODESPACE_NAME=codespace-fake');
        $_ENV['CODESPACE_NAME'] = 'codespace-fake';
        $_SERVER['CODESPACE_NAME'] = 'codespace-fake';

        try {
            $response = $this->withServerVariables($this->withPreviewProxyHeaders())->get('/');

            $response->assertStatus(200);

            $body = $response->getContent();

            // The page must carry styling that the browser can resolve from
            // the preview origin alone — either inline styles or a same-origin
            // @vite-rendered <link>. It must not point at a forwarded port.
            $this->assertMatchesRegularExpression(
                '/<style[\s>]|<link[^>]+rel=["\']stylesheet/i',
                $body,
                'Welcome page is missing any usable stylesheet.'
            );

            $this->assertStringNotContainsString(
                '-15173.app.github.dev',
                $body,
                'Welcome page injected a forwarded-Vite-port URL the preview origin cannot reach.'
            );
            $this->assertStringNotContainsString(
                ':15173',
                $body,
                'Welcome page injected a localhost Vite dev-server URL the browser cannot reach.'
            );
        } finally {
            if ($hotFileBackup === null) {
                @unlink($hotFile);
            } else {
                @file_put_contents($hotFile, $hotFileBackup);
            }
            if ($previousCodespace === false) {
                putenv('CODESPACE_NAME');
                unset($_ENV['CODESPACE_NAME'], $_SERVER['CODESPACE_NAME']);
            } else {
                putenv("CODESPACE_NAME={$previousCodespace}");
                $_ENV['CODESPACE_NAME'] = $previousCodespace;
                $_SERVER['CODESPACE_NAME'] = $previousCodespace;
            }
        }
    }

    public function test_root_page_emits_https_asset_urls_when_behind_https_proxy(): void
    {
        $response = $this->withServerVariables($this->withPreviewProxyHeaders())->get('/');

        $response->assertStatus(200);

        $body = $response->getContent();

        // Any absolute asset URL the page emits for itself must reflect the
        // proxy's https scheme — never plain http back to the preview host,
        // which would be blocked as mixed content.
        $this->assertDoesNotMatchRegularExpression(
            '#http://codespace-fake-80\.app\.github\.dev#i',
            $body,
            'Welcome page emitted plain-http URL under an https proxy (mixed content).'
        );
    }

    public function test_waterline_dashboard_renders_real_chrome(): void
    {
        $response = $this->withServerVariables($this->withPreviewProxyHeaders())->get('/waterline');

        // If the Waterline package isn't installed in this checkout, skip
        // rather than fail — the smoke check is meaningful only when the
        // dashboard route is registered.
        if ($response->status() === 404) {
            $this->markTestSkipped('Waterline routes are not registered in this build.');
        }

        $response->assertStatus(200);

        $body = $response->getContent();

        // The dashboard layout must include its own published CSS/JS bundle
        // references — without them the document is a 200 but renders a
        // blank shell.
        $this->assertStringContainsString(
            'vendor/waterline/app',
            $body,
            'Waterline dashboard did not reference its published asset bundle.'
        );

        // The Waterline mount point must be present so the SPA shell can boot.
        $this->assertStringContainsString(
            'id="waterline"',
            $body,
            'Waterline dashboard did not render its SPA mount point.'
        );

        // No forwarded Vite host in the dashboard either.
        $this->assertStringNotContainsString(
            '-15173.app.github.dev',
            $body,
            'Waterline dashboard injected a forwarded-Vite-port URL.'
        );
    }
}
