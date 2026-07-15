<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\Conformance;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ConformanceSetupMetricsTest extends TestCase
{
    public function test_setup_measurements_are_typed_for_json_metadata(): void
    {
        $environment = Env::getRepository();
        $values = [
            'SAMPLE_APP_SETUP_CACHE_STATE' => 'clean-cache',
            'SAMPLE_APP_SETUP_DURATION_MS' => '1234',
            'SAMPLE_APP_SETUP_PEAK_DISK_GROWTH_BYTES' => '5678',
            'SAMPLE_APP_SETUP_STACK_REUSED' => 'true',
            'SAMPLE_APP_SETUP_BUILD_INVOCATIONS' => '0',
        ];

        try {
            foreach ($values as $name => $value) {
                $environment->set($name, $value);
            }

            $method = new ReflectionMethod(Conformance::class, 'setupMetrics');

            $this->assertSame([
                'cache_state' => 'clean-cache',
                'duration_ms' => 1234,
                'peak_disk_growth_bytes' => 5678,
                'stack_reused' => true,
                'build_invocations' => 0,
            ], $method->invoke(new Conformance));
        } finally {
            foreach (array_keys($values) as $name) {
                $environment->clear($name);
            }
        }
    }
}
