<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\Simple;

use App\Workflows\Simple\SimpleActivity;
use ReflectionClass;
use Tests\TestCase;

class SimpleActivityTest extends TestCase
{
    public function test_activity(): void
    {
        $activity = (new ReflectionClass(SimpleActivity::class))->newInstanceWithoutConstructor();

        $result = $activity->handle();

        $this->assertSame('activity', $result);
    }
}
