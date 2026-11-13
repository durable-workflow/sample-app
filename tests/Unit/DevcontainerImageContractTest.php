<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DevcontainerImageContractTest extends TestCase
{
    public function test_codespaces_uses_the_published_image_and_persistent_service_state(): void
    {
        $config = json_decode(
            (string) file_get_contents($this->path('.devcontainer/devcontainer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $compose = Yaml::parseFile($this->path('.devcontainer/docker/docker-compose.yml'));
        $services = $compose['services'];

        $this->assertSame(['docker/docker-compose.yml'], $config['dockerComposeFile']);
        $this->assertSame('laravel', $config['service']);
        $this->assertSame('/var/www/html', $config['workspaceFolder']);
        $this->assertArrayNotHasKey('build', $services['laravel']);
        $this->assertSame(
            '${SAMPLE_APP_DEVCONTAINER_IMAGE:-ghcr.io/durable-workflow/sample-app-devcontainer:main}',
            $services['laravel']['image'],
        );
        $this->assertSame($services['laravel']['image'], $services['microservice']['image']);
        $this->assertArrayHasKey('laravel-mysql', $compose['volumes']);
        $this->assertArrayHasKey('laravel-redis', $compose['volumes']);
        $this->assertArrayHasKey('laravel-vendor', $compose['volumes']);
        $this->assertArrayHasKey('microservice-vendor', $compose['volumes']);
    }

    public function test_prepared_image_contains_the_advertised_development_toolchain(): void
    {
        $dockerfile = (string) file_get_contents($this->path('.devcontainer/docker/Dockerfile'));
        $postCreate = (string) file_get_contents($this->path('.devcontainer/post-create.sh'));

        foreach (['php', 'composer', 'python3', 'rustc', 'cargo', 'node', 'npm', 'docker', 'rg'] as $tool) {
            $this->assertStringContainsString($tool, $dockerfile);
        }

        $this->assertStringContainsString('verify-devcontainer-image', $dockerfile);
        $this->assertStringContainsString('scripts/playground doctor', $postCreate);
        $this->assertStringNotContainsString('apt-get', $postCreate);
        $this->assertStringNotContainsString('rustup', $postCreate);
    }

    public function test_qualification_runs_the_real_codespaces_startup_once(): void
    {
        $script = (string) file_get_contents($this->path('scripts/ci/qualify-devcontainer-image.sh'));

        $this->assertStringContainsString('DEVCONTAINER_MAX_STARTUP_SECONDS:-600', $script);
        $this->assertStringContainsString('up --detach --no-build laravel microservice', $script);
        $this->assertStringContainsString('run_in_ready_devcontainer laravel .devcontainer/post-create.sh', $script);
        $this->assertStringContainsString('scripts/playground doctor', $script);
        $this->assertStringContainsString('node docker/playwright-smoke.js', $script);
        $this->assertStringContainsString('command -v "$command"', $script);
        $this->assertStringContainsString('cargo check --bins --locked --offline', $script);
        $this->assertStringContainsString('down --volumes --remove-orphans', $script);
        $this->assertStringNotContainsString('upload-artifact', $script);
        $this->assertStringNotContainsString('evidence_type', $script);
    }

    public function test_image_checks_effective_trust_before_network_installation(): void
    {
        $dockerfile = (string) file_get_contents($this->path('.devcontainer/docker/Dockerfile'));
        preg_match_all('/^FROM (\S+)/mi', $dockerfile, $inputs);
        $this->assertNotEmpty($inputs[1]);
        foreach ($inputs[1] as $input) {
            $this->assertMatchesRegularExpression('/^[^@]+:[^@]+@sha256:[a-f0-9]{64}$/D', $input);
        }
        $runtime = substr($dockerfile, strrpos($dockerfile, "\nFROM "));
        $verifier = 'php /usr/local/share/sample-app/verify-php-trust.php';
        $firstCheck = strpos($runtime, $verifier);
        $firstDownload = strpos($runtime, 'apt-get update');
        $lastInstall = strrpos($runtime, 'apt-get install');
        $recheck = strrpos($runtime, $verifier);
        $nodeCheck = strpos($runtime, 'node /usr/local/share/sample-app/verify-node-trust.mjs');
        $cliDownload = strpos($runtime, 'https://durable-workflow.com/install.sh');

        foreach ([$firstCheck, $firstDownload, $lastInstall, $recheck, $nodeCheck, $cliDownload] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($firstDownload, $firstCheck);
        $this->assertGreaterThan($lastInstall, $recheck);
        $this->assertLessThan($cliDownload, $nodeCheck);
    }

    public function test_pull_request_build_cannot_publish_or_use_registry_credentials(): void
    {
        $workflow = (string) file_get_contents($this->path('.github/workflows/devcontainer-image-pr.yml'));

        $this->assertStringContainsString("pull_request:\n    branches: [main]", $workflow);
        $this->assertStringContainsString('no-cache: true', $workflow);
        $this->assertStringContainsString('push: false', $workflow);
        $this->assertStringContainsString('qualify-devcontainer-image.sh', $workflow);
        $this->assertStringNotContainsString('docker/login-action', $workflow);
        $this->assertStringNotContainsString('secrets.', $workflow);
        $this->assertStringNotContainsString('packages: write', $workflow);

        $jobs = Yaml::parse($workflow)['jobs'];
        $matrix = $jobs['qualify']['strategy']['matrix']['include'];
        $this->assertSame(['linux/amd64', 'linux/arm64'], array_column($matrix, 'platform'));
        $this->assertSame(['ubuntu-24.04', 'ubuntu-24.04-arm'], array_column($matrix, 'runner'));
        $this->assertFalse($jobs['qualify']['strategy']['fail-fast']);
        $this->assertSame('always()', $jobs['validate']['if']);
        $this->assertSame(['qualify'], $jobs['validate']['needs']);
        $gate = $jobs['validate']['steps'][0];
        $this->assertSame('${{ needs.qualify.result }}', $gate['env']['QUALIFICATION_RESULT']);
        $this->assertSame('test "$QUALIFICATION_RESULT" = success', $gate['run']);
    }

    public function test_composer_jobs_check_trust_before_dependency_resolution(): void
    {
        $workflow = Yaml::parseFile($this->path('.github/workflows/ci.yml'));

        foreach (['test', 'microservice-test'] as $job) {
            $steps = $workflow['jobs'][$job]['steps'];
            $setup = null;
            $bootstrap = null;
            foreach ($steps as $index => $step) {
                if (str_starts_with($step['uses'] ?? '', 'durable-workflow/.github/.github/actions/bootstrap-patched-pip@')) {
                    $bootstrap = $index;
                    $this->assertSame('52283e2e8ea9a2cf7e3865ee30f2a72a38b098a2', explode('@', $step['uses'])[1]);
                    $this->assertStringContainsString('composer,curl,git', $step['with']['clients']);
                }
                if (str_contains($step['run'] ?? '', 'scripts/ci/setup-php-container.sh')) {
                    $setup = $index;
                    break;
                }
            }
            $this->assertNotNull($bootstrap);
            $this->assertNotNull($setup);
            $this->assertLessThan($setup, $bootstrap);
            $this->assertStringContainsString('"${{ matrix.php }}"', $steps[$setup]['run']);
            $images = json_decode((string) file_get_contents($this->path('scripts/ci/php-container-images.json')), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['8.4', '8.5'], array_keys($images));
            foreach ($images as $minor => $image) {
                $this->assertMatchesRegularExpression('/^php:'.preg_quote($minor, '/').'\.[0-9]+-cli-bookworm@sha256:[0-9a-f]{64}$/', $image);
            }
            $this->assertSame(
                'durable-workflow/.github/.github/actions/verify-composer-trust@52283e2e8ea9a2cf7e3865ee30f2a72a38b098a2',
                $steps[$setup + 1]['uses'],
            );
            $this->assertSame($job === 'microservice-test' ? 'microservice' : '.', $steps[$setup + 1]['with']['working-directory'] ?? '.');
        }
    }

    public function test_image_builds_bind_the_shared_preflight_before_buildx(): void
    {
        $commit = '18783be6bef737cc778e4c7282a8797e39daa61a';
        $hash = '2a36a2417e8947357ea6f6cbc2f6c53a834f3c069f8013a34d6d861b7123d576';
        foreach (['devcontainer-image-pr.yml' => 'qualify', 'devcontainer-image.yml' => 'build'] as $file => $job) {
            $steps = Yaml::parseFile($this->path('.github/workflows/'.$file))['jobs'][$job]['steps'];
            $checkouts = array_filter($steps, static fn (array $step): bool => ($step['with']['repository'] ?? null) === 'durable-workflow/.github');
            $this->assertCount(1, $checkouts);
            $index = array_key_first($checkouts);
            $this->assertSame('actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1', $steps[$index]['uses']);
            $this->assertSame($commit, $steps[$index]['with']['ref']);
            $this->assertSame('.central-apt-source', $steps[$index]['with']['path']);
            $this->assertFalse($steps[$index]['with']['persist-credentials']);
            $verification = $steps[$index + 1]['run'];
            $this->assertStringContainsString('test "$(git -C .central-apt-source rev-parse HEAD)" = '.$commit, $verification);
            $this->assertStringContainsString($hash.'  .central-apt-source/docker/apt/require-fixed-bookworm-apt.sh', $verification);
            $this->assertStringContainsString('sha256sum --check --strict', $verification);
            foreach ($steps as $position => $step) {
                if (str_starts_with($step['uses'] ?? '', 'docker/setup-buildx-action@')) {
                    $this->assertLessThan($position, $index + 1);
                }
            }
        }
    }

    public function test_main_publication_builds_both_architectures_before_promotion(): void
    {
        $workflow = Yaml::parseFile($this->path('.github/workflows/devcontainer-image.yml'));
        $jobs = $workflow['jobs'];

        $this->assertSame(['workflow_dispatch'], array_keys($workflow['on']));
        foreach ($jobs as $job) {
            $this->assertSame("github.ref == 'refs/heads/main'", $job['if']);
        }

        $this->assertSame(['build'], $jobs['assemble']['needs']);
        $this->assertSame(['assemble'], $jobs['scan']['needs']);
        $this->assertSame(['assemble', 'scan'], $jobs['qualify']['needs']);
        $this->assertSame(['assemble', 'scan', 'qualify'], $jobs['promote']['needs']);

        $matrix = $jobs['build']['strategy']['matrix']['include'];
        $this->assertSame(['linux/amd64', 'linux/arm64'], array_column($matrix, 'platform'));
        $this->assertSame(['ubuntu-24.04', 'ubuntu-24.04-arm'], array_column($matrix, 'runner'));

        $source = (string) file_get_contents($this->path('.github/workflows/devcontainer-image.yml'));
        $this->assertStringContainsString('ghcr.io/durable-workflow/sample-app-devcontainer', $source);
        $this->assertStringContainsString('durableworkflow/sample-app-devcontainer', $source);
        $this->assertStringContainsString('provenance: mode=max', $source);
        $policy = json_decode(file_get_contents($this->path('scripts/ci/devcontainer-security.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('generator='.$policy['sbom_generator_image'], $source);
        $this->assertStringContainsString('SELECT_CATALOGERS=image,file,+sbom-cataloger,+rust-cargo-lock-cataloger,+bitnami-cataloger,+conan-info-cataloger,+javascript-package-cataloger,+php-composer-installed-cataloger,+ruby-installed-gemspec-cataloger', $source);
        // docker/build-push-action re-parses the sbom input as CSV and rejoins the
        // fields with bare commas before buildx parses it again. The catalog
        // selection must survive both passes as a single field.
        $buildStep = array_values(array_filter($jobs['build']['steps'], fn (array $step): bool => ($step['name'] ?? null) === 'Build and publish immutable architecture image'))[0];
        $rejoined = implode(',', array_map('trim', str_getcsv('type=sbom,'.$buildStep['with']['sbom'], ',', '"', '')));
        $buildxFields = str_getcsv($rejoined, ',', '"', '');
        $selection = array_values(array_filter($buildxFields, fn (string $field): bool => str_starts_with($field, 'SELECT_CATALOGERS=')));
        $this->assertCount(1, $selection);
        $this->assertStringContainsString(',file,+sbom-cataloger,+rust-cargo-lock-cataloger,', $selection[0]);
        $this->assertNotContains('file', $buildxFields);
        $this->assertStringContainsString('DEVCONTAINER_REQUIRE_ANONYMOUS_PULL', $source);
        $this->assertStringContainsString('Publish main channel', $source);
        $this->assertStringContainsString('scripts/ci/scan-devcontainer-image.py', $source);
        $this->assertStringContainsString('needs.scan.outputs.index_digest', $source);
        $this->assertStringContainsString('public main index differs from the scanned and qualified digest', $source);
        $scan = $jobs['scan'];
        $this->assertStringNotContainsString('secrets.', json_encode($scan, JSON_THROW_ON_ERROR));
        $this->assertSame('always()', $scan['steps'][2]['if']);
        $this->assertSame('actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9', $scan['steps'][2]['uses']);
        $this->assertSame('${{ runner.temp }}/devcontainer-security/*.json', $scan['steps'][2]['with']['path']);
        $this->assertStringNotContainsString('recover_revision_tag', $source);
    }

    public function test_registry_writes_require_the_main_publication_environment(): void
    {
        $jobs = Yaml::parseFile($this->path('.github/workflows/devcontainer-image.yml'))['jobs'];
        $writers = array_filter($jobs, static fn (array $job): bool => ($job['permissions']['packages'] ?? null) === 'write');
        $this->assertSame(['build', 'assemble', 'promote'], array_keys($writers));
        foreach ($writers as $job) {
            $this->assertSame('devcontainer-publication', $job['environment'] ?? null);
            $this->assertSame("github.ref == 'refs/heads/main'", $job['if']);
        }
    }

    private function path(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }
}
