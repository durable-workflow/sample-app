<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class IssueTemplateVersionGuidanceTest extends TestCase
{
    public function test_workflow_version_fields_use_release_neutral_placeholders(): void
    {
        foreach (['bug_report.yml', 'sample_request.yml'] as $templateName) {
            $template = Yaml::parseFile($this->repoPath(".github/ISSUE_TEMPLATE/{$templateName}"));
            $versionField = $this->bodyField($template, 'durable_workflow_version');

            $this->assertNotNull($versionField, "{$templateName} must collect the Workflow package version.");
            $this->assertTrue($versionField['validations']['required'] ?? false);

            $placeholder = $versionField['attributes']['placeholder'] ?? null;

            $this->assertIsString($placeholder);
            $this->assertNotSame('', trim($placeholder));
            $this->assertDoesNotMatchRegularExpression(
                '/\b\d+\.\d+\.\d+(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?\b/',
                $placeholder,
                "{$templateName} must not hard-code a release in its Workflow version hint.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>|null
     */
    private function bodyField(array $template, string $id): ?array
    {
        foreach ($template['body'] ?? [] as $field) {
            if (($field['id'] ?? null) === $id) {
                return $field;
            }
        }

        return null;
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
