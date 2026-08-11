<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodespacesOnboardingContractTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function deploymentPaths(): iterable
    {
        yield 'service mode' => ['service-mode', 'scripts/polyglot.sh'];
        yield 'embedded Laravel' => ['embedded-laravel', 'composer run dev'];
    }

    #[DataProvider('deploymentPaths')]
    public function test_each_codespaces_deployment_path_leads_with_its_supported_command(
        string $path,
        string $command,
    ): void {
        $onboarding = $this->codespacesOnboarding();
        $sections = $this->deploymentPathSections($onboarding);

        $this->assertArrayHasKey($path, $sections);
        $this->assertStringContainsString($command, $sections[$path]);
    }

    public function test_codespaces_onboarding_exposes_two_top_level_deployment_paths(): void
    {
        $onboarding = $this->codespacesOnboarding();

        preg_match_all('/^### /m', $onboarding, $topLevelPathHeadings);

        $this->assertSame(
            ['service-mode', 'embedded-laravel'],
            array_keys($this->deploymentPathSections($onboarding)),
        );
        $this->assertCount(2, $topLevelPathHeadings[0]);
    }

    public function test_application_shaped_service_journey_is_nested_under_the_primary_service_path(): void
    {
        $serviceMode = $this->deploymentPathSections($this->codespacesOnboarding())['service-mode'];
        $primaryCommand = strpos($serviceMode, 'scripts/polyglot.sh');
        $variationHeading = strpos($serviceMode, '#### ');
        $variationCommand = strpos($serviceMode, 'scripts/service-mode.sh');

        $this->assertIsInt($primaryCommand);
        $this->assertIsInt($variationHeading);
        $this->assertIsInt($variationCommand);
        $this->assertGreaterThan($primaryCommand, $variationHeading);
        $this->assertGreaterThan($variationHeading, $variationCommand);
        $this->assertStringContainsString(
            'polyglot/README.md#complete-runtime-matrix',
            $serviceMode,
        );
    }

    private function codespacesOnboarding(): string
    {
        $readme = (string) file_get_contents($this->repoPath('README.md'));
        preg_match_all('/^## /m', $readme, $sectionHeadings, PREG_OFFSET_CAPTURE);

        $this->assertGreaterThanOrEqual(2, count($sectionHeadings[0]));

        $start = $sectionHeadings[0][0][1];
        $end = $sectionHeadings[0][1][1];

        return substr($readme, $start, $end - $start);
    }

    /**
     * @return array<string, string>
     */
    private function deploymentPathSections(string $onboarding): array
    {
        preg_match_all(
            '/^<!-- codespaces-path: (?<path>[a-z-]+) -->\R(?=### )/m',
            $onboarding,
            $markers,
            PREG_OFFSET_CAPTURE,
        );

        $sections = [];
        foreach ($markers['path'] as $index => [$path]) {
            $start = $markers[0][$index][1];
            $end = $markers[0][$index + 1][1] ?? strlen($onboarding);
            $sections[$path] = substr($onboarding, $start, $end - $start);
        }

        return $sections;
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
