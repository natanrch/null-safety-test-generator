<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerViewAnalyzer;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\AnotherFakeObject;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithView;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeObject;
use PHPUnit\Framework\TestCase;

class ControllerViewAnalyzerTest extends TestCase
{
    public function test_it_identifies_the_view_and_its_analyzed_variables(): void
    {
        $analyzer = new ControllerViewAnalyzer(
            new ControllerMethodAnalyzer()
        );

        $result = $analyzer->analyze(
            FakeControllerWithView::class,
            'show'
        );

        $this->assertSame([
            'view' => 'fixtures.objects.show',
            'variables' => [
                'object' => [
                    'class' => FakeObject::class,
                    'type' => 'object',
                ],
                'otherObject' => [
                    'class' => AnotherFakeObject::class,
                    'type' => 'object',
                ],
                'objects' => [
                    'class' => AnotherFakeObject::class,
                    'type' => 'collection',
                ],
            ],
        ], $result);
    }

    public function test_it_ignores_analyzed_variables_not_passed_to_the_view(): void
    {
        $analyzer = new ControllerViewAnalyzer(
            new ControllerMethodAnalyzer()
        );

        $result = $analyzer->analyze(
            FakeControllerWithView::class,
            'show'
        );

        $this->assertArrayNotHasKey(
            'unusedObject',
            $result['variables']
        );
    }
}
