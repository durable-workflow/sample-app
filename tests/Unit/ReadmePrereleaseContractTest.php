<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReadmePrereleaseContractTest extends TestCase
{
    public function test_prerelease_instructions_follow_the_pinned_sdk_and_resolver_behavior(): void
    {
        $readme = (string) file_get_contents($this->repoPath('README.md'));
        $pinnedArtifacts = $this->resolvePinnedArtifacts();
        $pythonSdkVersion = $pinnedArtifacts['DURABLE_WORKFLOW_PYTHON_SDK_VERSION'] ?? null;

        $this->assertIsString($pythonSdkVersion);
        $this->assertSame(1, preg_match(
            "/pip install 'durable-workflow\\[prometheus\\]==(?<version>[^']+)'/",
            $readme,
            $installMatch,
        ));
        $this->assertSame($this->pep440Prerelease($pythonSdkVersion), $installMatch['version']);

        $this->assertSame(
            [
                'beta' => $this->componentVersionPolicy('beta'),
                'rc' => $this->componentVersionPolicy('rc'),
                'mixed' => $this->mixedChannelPolicy(),
            ],
            $this->documentedResolverPolicy($readme),
        );
    }

    /**
     * @return array<string, string>
     */
    private function resolvePinnedArtifacts(): array
    {
        $command = sprintf(
            'env -i PATH=%s DURABLE_WORKFLOW_ARTIFACT_SOURCE=pinned bash %s',
            escapeshellarg((string) getenv('PATH')),
            escapeshellarg($this->repoPath('scripts/resolve-current-artifacts.sh')),
        );

        exec($command, $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));

        $assignments = [];
        foreach ($output as $line) {
            [$name, $value] = explode('=', $line, 2);
            $assignments[$name] = $value;
        }

        return $assignments;
    }

    private function pep440Prerelease(string $version): string
    {
        $pep440Version = preg_replace(
            ['/-alpha\./', '/-beta\./', '/-rc\./'],
            ['a', 'b', 'rc'],
            $version,
        );

        $this->assertIsString($pep440Version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(?:a|b|rc)\d+$/', $pep440Version);

        return $pep440Version;
    }

    private function componentVersionPolicy(string $channel): string
    {
        $tuple = $this->artifactTuple("2.0.0-{$channel}.1");
        $tuple['server'] = "2.0.0-{$channel}.2";

        return $this->resolverAccepts($tuple) ? 'component-specific' : 'synchronized';
    }

    private function mixedChannelPolicy(): string
    {
        $tuple = $this->artifactTuple('2.0.0-rc.1');
        $tuple['server'] = '2.0.0-beta.1';

        return $this->resolverAccepts($tuple) ? 'accepted' : 'rejected';
    }

    /**
     * @return array<string, string>
     */
    private function artifactTuple(string $version): array
    {
        return array_fill_keys(
            ['server', 'cli', 'sdk-php', 'sdk-python', 'sdk-rust', 'workflow', 'waterline'],
            $version,
        );
    }

    /**
     * @param  array<string, string>  $tuple
     */
    private function resolverAccepts(array $tuple): bool
    {
        $fixturePath = tempnam(sys_get_temp_dir(), 'sample-app-artifacts-');
        $this->assertIsString($fixturePath);

        try {
            file_put_contents(
                $fixturePath,
                json_encode(['artifacts' => $tuple], JSON_THROW_ON_ERROR),
            );
            $command = sprintf(
                'env -i PATH=%s DURABLE_WORKFLOW_ARTIFACT_TUPLE_FILE=%s bash %s 2>/dev/null',
                escapeshellarg((string) getenv('PATH')),
                escapeshellarg($fixturePath),
                escapeshellarg($this->repoPath('scripts/resolve-current-artifacts.sh')),
            );

            exec($command, $output, $exitCode);

            return $exitCode === 0;
        } finally {
            @unlink($fixturePath);
        }
    }

    /**
     * @return array<string, string>
     */
    private function documentedResolverPolicy(string $readme): array
    {
        $this->assertSame(1, preg_match(
            '/<!-- durable-workflow-artifact-channel-policy:start -->(?<policy>.*?)'
                .'<!-- durable-workflow-artifact-channel-policy:end -->/s',
            $readme,
            $policyMatch,
        ));

        preg_match_all(
            '/^\|\s*`(?<channel>[a-z-]+)`\s*\|\s*`(?<policy>[a-z-]+)`\s*\|$/m',
            $policyMatch['policy'],
            $rows,
            PREG_SET_ORDER,
        );

        $policy = [];
        foreach ($rows as $row) {
            $policy[$row['channel']] = $row['policy'];
        }

        return $policy;
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
