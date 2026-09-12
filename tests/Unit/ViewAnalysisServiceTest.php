<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\BladeAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerViewAnalyzer;
use Natan\NullSafetyTestGenerator\Services\ViewAnalysisService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\AnotherFakeObject;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithView;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeObject;
use PHPUnit\Framework\TestCase;

class ViewAnalysisServiceTest extends TestCase
{
    public function test_it_combines_controller_variables_with_blade_accesses(): void
    {
        $methodAnalyzer = new ControllerMethodAnalyzer();

        $analyzer = new ViewAnalysisService(
            new ControllerViewAnalyzer($methodAnalyzer),
            new BladeAnalyzer()
        );

        $result = $analyzer->analyze(
            FakeControllerWithView::class,
            'show',
            __DIR__ . '/../Fixtures/views/objects/show.blade.php'
        );

        $this->assertSame([
            'view' => 'fixtures.objects.show',
            'accesses' => [
                [
                    'root' => 'object',
                    'class' => FakeObject::class,
                    'type' => 'object',
                    'accesses' => [
                        [
                            'type' => 'property',
                            'name' => 'name',
                        ],
                    ],
                ],
                [
                    'root' => 'otherObject',
                    'class' => AnotherFakeObject::class,
                    'type' => 'object',
                    'accesses' => [
                        [
                            'type' => 'property',
                            'name' => 'name',
                        ],
                    ],
                ],
                [
                    'root' => 'objects',
                    'class' => AnotherFakeObject::class,
                    'type' => 'collection',
                    'alias' => 'item',
                    'accesses' => [
                        [
                            'type' => 'property',
                            'name' => 'name',
                        ],
                    ],
                ],
            ],
        ], $result);
    }
}
