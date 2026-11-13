<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * CVE-2026-109518 (durable-workflow/.github#295): vulnerable APT can follow a
 * redirect on a retried package download and install a swapped package as
 * root. Every networked APT path runs with retries off, or behind the central
 * fixed-Bookworm-APT guard on a pinned parent.
 */
final class AptNoRetriesContractTest extends TestCase
{
    private const NO_RETRIES = 'Acquire::Retries "0";';

    private const APT = '/\bapt(?:-get)?\b[^\n]{0,80}\b(?:update|install|upgrade|dist-upgrade)\b|--with-deps|install-deps/';

    public function test_every_apt_capable_workflow_job_disables_retries_first(): void
    {
        $unprotected = [];
        foreach (glob($this->path('.github/workflows').'/*.yml') as $file) {
            $workflow = Yaml::parseFile($file);
            foreach ($workflow['jobs'] ?? [] as $name => $job) {
                $finding = $this->firstUnprotectedStep($job['steps'] ?? []);
                if ($finding !== null) {
                    $unprotected[] = basename($file).':'.$name.': '.$finding;
                }
            }
        }

        $this->assertSame([], $unprotected);
    }

    public function test_the_workflow_check_refuses_a_late_or_missing_line(): void
    {
        $setup = ['uses' => 'shivammathur/setup-php@'.str_repeat('a', 40)];
        $line = ['run' => "echo '".self::NO_RETRIES."' | sudo tee /etc/apt/apt.conf.d/99-no-retries >/dev/null"];

        $this->assertNull($this->firstUnprotectedStep([$line, $setup]));
        $this->assertNotNull($this->firstUnprotectedStep([$setup, $line]));
        $this->assertNotNull($this->firstUnprotectedStep([['run' => 'npx playwright install --with-deps chromium']]));
    }

    public function test_every_image_stage_disables_retries_or_runs_the_fixed_apt_guard_before_apt(): void
    {
        $unprotected = [];
        foreach (['Dockerfile', '.devcontainer/docker/Dockerfile', ...glob($this->path('polyglot').'/*/Dockerfile')] as $file) {
            $path = str_starts_with($file, '/') ? $file : $this->path($file);
            $stages = preg_split('/^FROM /m', (string) file_get_contents($path));
            foreach (array_slice($stages, 1) as $index => $stage) {
                if ($this->stageRunsAptUnprotected($stage)) {
                    $unprotected[] = str_replace($this->path(''), '', $path).' stage '.($index + 1);
                }
            }
        }

        $this->assertSame([], $unprotected);
    }

    public function test_the_image_check_refuses_apt_before_the_line(): void
    {
        $this->assertTrue($this->stageRunsAptUnprotected("debian:bookworm-slim\nRUN apt-get update\n"));
        $this->assertTrue($this->stageRunsAptUnprotected(
            "debian:bookworm-slim\nRUN apt-get update\nRUN echo '".self::NO_RETRIES."' > /etc/apt/apt.conf.d/99-no-retries\n",
        ));
        $this->assertFalse($this->stageRunsAptUnprotected(
            "debian:bookworm-slim\nRUN echo '".self::NO_RETRIES."' > /etc/apt/apt.conf.d/99-no-retries\nRUN apt-get update\n",
        ));
    }

    /** @param list<array<string, mixed>> $steps */
    private function firstUnprotectedStep(array $steps): ?string
    {
        foreach ($steps as $step) {
            $run = (string) ($step['run'] ?? '');
            if (str_contains($run, self::NO_RETRIES)) {
                return null;
            }
            if (str_starts_with((string) ($step['uses'] ?? ''), 'shivammathur/setup-php@') || preg_match(self::APT, $run) === 1) {
                return (string) ($step['name'] ?? $step['uses'] ?? $run);
            }
        }

        return null;
    }

    private function stageRunsAptUnprotected(string $stage): bool
    {
        $stage = (string) preg_replace('/^\s*#.*$/m', '', $stage);
        $apt = preg_match('/apt-get (?:update|install|upgrade)|--with-deps|\bsh \S*install-vendor\.sh/', $stage, $match, PREG_OFFSET_CAPTURE);
        if ($apt !== 1) {
            return false;
        }
        $first = $match[0][1];
        foreach ([self::NO_RETRIES, 'require-fixed-bookworm-apt.sh \\', 'require-fixed-bookworm-apt.sh;'] as $protection) {
            $offset = strpos($stage, $protection);
            if ($offset !== false && $offset < $first) {
                return false;
            }
        }

        return true;
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2).($relative === '' ? '/' : '/'.$relative);
    }
}
