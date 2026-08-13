<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OnboardingVersionPinsTest extends TestCase
{
    public function test_guides_do_not_claim_an_exact_prerelease_as_current(): void
    {
        foreach (['README.md', 'docs/sandbox-orchestration.md'] as $path) {
            $content = file_get_contents(dirname(__DIR__, 2).'/'.$path);

            self::assertIsString($content);
            self::assertDoesNotMatchRegularExpression(
                '/\bv?\d+\.\d+\.\d+-(?:alpha|beta|rc)\.\d+\b|\b\d+\.\d+\.\d+(?:a|b|rc)\d+\b/i',
                $content,
                $path,
            );
            if ($path === 'README.md') {
                self::assertStringContainsString(
                    'https://durable-workflow.com/install-sdk.sh',
                    $content,
                );
                self::assertStringNotContainsString('durable-workflow[prometheus]~=', $content);
            }
        }
    }
}
