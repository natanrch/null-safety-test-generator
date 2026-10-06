<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Inspectors\ControllerMethodExecutionInspector;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use PHPUnit\Framework\TestCase;

class ControllerMethodExecutionInspectorTest extends TestCase
{
    public function test_it_treats_a_method_containing_only_comments_as_empty(): void
    {
        $result = (new ControllerMethodExecutionInspector())
            ->hasExecutableStatements(
                PostController::class,
                'emptyAction'
            );

        $this->assertFalse($result);
    }

    public function test_it_recognizes_an_executable_controller_method(): void
    {
        $result = (new ControllerMethodExecutionInspector())
            ->hasExecutableStatements(
                PostController::class,
                'show'
            );

        $this->assertTrue($result);
    }

    public function test_it_returns_unknown_when_the_method_cannot_be_inspected(): void
    {
        $result = (new ControllerMethodExecutionInspector())
            ->hasExecutableStatements(
                PostController::class,
                'missingMethod'
            );

        $this->assertNull($result);
    }
}
