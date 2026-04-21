<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows\Simple;

use App\Workflows\Simple\SimpleOtherActivity;
use ReflectionClass;
use Tests\TestCase;

class SimpleOtherActivityTest extends TestCase
{
    public function test_activity(): void
    {
        $activity = (new ReflectionClass(SimpleOtherActivity::class))->newInstanceWithoutConstructor();

        $result = $activity->handle('other');

        $this->assertSame('other', $result);
    }
}
