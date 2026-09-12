<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeObject;
use PHPUnit\Framework\TestCase;

class ControllerMethodAnalyzerTest extends TestCase
{
    public function test_it_identifies_the_class_of_a_controller_method_parameter(): void
    {
        $analyzer = new ControllerMethodAnalyzer();

        $result = $analyzer->getParameterClasses(
            FakeController::class,
            'show'
        );

        $this->assertSame([
            'object' => FakeObject::class,
        ], $result);
    }
}