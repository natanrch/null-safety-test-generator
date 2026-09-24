<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\BladeAnalyzer;
use PHPUnit\Framework\TestCase;

class BladeAnalyzerTest extends TestCase
{
    public function test_it_identifies_simple_property_access(): void
    {
        $analyzer = new BladeAnalyzer();

        $result = $analyzer->analyze(
            $this->viewPath('simple-access.blade.php')
        );

        $this->assertSame([
            [
                'root' => 'object',
                'accesses' => [
                    [
                        'type' => 'property',
                        'name' => 'name',
                    ],
                ],
            ],
        ], $result);
    }

    public function test_it_identifies_chained_property_access(): void
    {
        $analyzer = new BladeAnalyzer();

        $result = $analyzer->analyze(
            $this->viewPath('chained-access.blade.php')
        );

        $this->assertSame([
            [
                'root' => 'object',
                'accesses' => [
                    [
                        'type' => 'property',
                        'name' => 'relation',
                    ],
                    [
                        'type' => 'property',
                        'name' => 'name',
                    ],
                ],
            ],
        ], $result);
    }

    public function test_it_identifies_method_call(): void
    {
        $analyzer = new BladeAnalyzer();

        $result = $analyzer->analyze(
            $this->viewPath('method-call.blade.php')
        );

        $this->assertSame([
            [
                'root' => 'object',
                'accesses' => [
                    [
                        'type' => 'property',
                        'name' => 'date',
                    ],
                    [
                        'type' => 'method',
                        'name' => 'format',
                    ],
                ],
            ],
        ], $result);
    }

    public function test_it_resolves_collection_items_created_by_foreach(): void
    {
        $analyzer = new BladeAnalyzer();

        $result = $analyzer->analyze(
            $this->viewPath('collection-loop.blade.php')
        );

        $this->assertSame([
            [
                'root' => 'objects',
                'alias' => 'item',
                'accesses' => [
                    [
                        'type' => 'property',
                        'name' => 'name',
                    ],
                ],
            ],
        ], $result);
    }

    public function test_it_analyzes_directives_and_nested_expressions(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('complex-expressions.blade.php')
        );

        $this->assertSame([
            [
                'root' => 'objects',
                'alias' => 'item',
                'accesses' => [
                    ['type' => 'property', 'name' => 'active'],
                ],
            ],
            [
                'root' => 'objects',
                'alias' => 'item',
                'accesses' => [
                    ['type' => 'property', 'name' => 'date'],
                ],
            ],
            [
                'root' => 'objects',
                'alias' => 'item',
                'accesses' => [
                    ['type' => 'property', 'name' => 'date'],
                    ['type' => 'method', 'name' => 'format'],
                ],
            ],
            [
                'root' => 'objects',
                'alias' => 'item',
                'accesses' => [
                    ['type' => 'property', 'name' => 'id'],
                ],
            ],
        ], $result);
    }

    public function test_it_recursively_analyzes_included_views_without_cycles(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('includes/parent.blade.php')
        );

        $this->assertSame([
            [
                'root' => 'object',
                'accesses' => [
                    ['type' => 'property', 'name' => 'title'],
                ],
            ],
            [
                'root' => 'object',
                'accesses' => [
                    ['type' => 'property', 'name' => 'relation'],
                    ['type' => 'property', 'name' => 'name'],
                ],
            ],
            [
                'root' => 'object',
                'accesses' => [
                    ['type' => 'property', 'name' => 'date'],
                    ['type' => 'method', 'name' => 'format'],
                ],
            ],
        ], $result);
    }

    private function viewPath(string $fileName): string
    {
        return __DIR__ . '/../Fixtures/views/blade/' . $fileName;
    }
}
