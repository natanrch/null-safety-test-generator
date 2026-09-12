<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\AnotherFakeObject;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeObject;
use PHPUnit\Framework\TestCase;

class ControllerMethodAnalyzerTest extends TestCase
{
    public function test_it_identifies_object_passed_as_parameter(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getObjectClasses(
            FakeController::class,
            'show'
        );

        $this->assertArrayHasKey('object', $result);

        $this->assertSame(
            FakeObject::class,
            $result['object']
        );
    }

    public function test_it_identifies_object_loaded_inside_method(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getObjectClasses(
            FakeController::class,
            'show'
        );

        $this->assertArrayHasKey('otherObject', $result);

        $this->assertSame(
            AnotherFakeObject::class,
            $result['otherObject']
        );
    }

    public function test_it_identifies_parameter_and_locally_loaded_object_classes(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getObjectClasses(
            FakeController::class,
            'show'
        );

        $this->assertSame([
            'object' => FakeObject::class,
            'otherObject' => AnotherFakeObject::class,
        ], $result);
    }
}