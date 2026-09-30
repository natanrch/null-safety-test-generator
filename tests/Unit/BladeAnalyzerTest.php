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

    public function test_it_marks_an_attribute_used_as_a_function_argument(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('function-argument.blade.php')
        );

        $this->assertSame('function_argument', $result[0]['usage']);
        $this->assertSame('name', $result[0]['accesses'][0]['name']);
    }

    public function test_it_marks_an_attribute_used_as_a_method_argument(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('method-argument.blade.php')
        );

        $attribute = array_values(array_filter(
            $result,
            static fn (array $access): bool =>
                ($access['root'] ?? null) === 'object'
        ))[0];

        $this->assertSame('method_argument', $attribute['usage']);
        $this->assertSame('name', $attribute['accesses'][0]['name']);
    }

    public function test_it_marks_an_attribute_used_as_a_static_method_argument(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('static-method-argument.blade.php')
        );

        $this->assertSame('static_method_argument', $result[0]['usage']);
        $this->assertSame('name', $result[0]['accesses'][0]['name']);
    }

    public function test_it_marks_an_attribute_used_in_a_binary_operation(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('binary-operation.blade.php')
        );

        $this->assertSame('binary_operation', $result[0]['usage']);
        $this->assertSame('price', $result[0]['accesses'][0]['name']);
    }

    public function test_it_marks_an_attribute_used_as_an_array(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('array-access.blade.php')
        );

        $this->assertSame('array_access', $result[0]['usage']);
        $this->assertSame('metadata', $result[0]['accesses'][0]['name']);
    }

    public function test_it_does_not_mark_null_coalescing_as_null_sensitive(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('null-coalescing.blade.php')
        );

        $this->assertArrayNotHasKey('usage', $result[0]);
        $this->assertSame('name', $result[0]['accesses'][0]['name']);
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

    public function test_it_resolves_collection_items_created_by_forelse(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('collection-forelse.blade.php')
        );

        $this->assertSame([
            [
                'root' => 'objects',
                'alias' => 'item',
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
            [
                'root' => 'fallback',
                'accesses' => [
                    [
                        'type' => 'property',
                        'name' => 'message',
                    ],
                ],
            ],
        ], $result);
    }

    public function test_it_preserves_access_chains_assigned_in_php_blocks(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('php-assignment-forelse.blade.php')
        );

        $this->assertSame([
            [
                'root' => 'post',
                'accesses' => [
                    ['type' => 'property', 'name' => 'author'],
                    ['type' => 'property', 'name' => 'posts'],
                ],
            ],
            [
                'root' => 'post',
                'alias' => 'relatedPost',
                'accesses' => [
                    ['type' => 'property', 'name' => 'author'],
                    ['type' => 'property', 'name' => 'posts'],
                    ['type' => 'property', 'name' => 'title'],
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
                'usage' => 'function_argument',
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

    public function test_it_ignores_single_and_multiline_blade_comments(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('blade-comments.blade.php')
        );

        $this->assertSame([
            [
                'root' => 'object',
                'accesses' => [
                    ['type' => 'property', 'name' => 'name'],
                ],
            ],
        ], $result);
    }

    private function viewPath(string $fileName): string
    {
        return __DIR__ . '/../Fixtures/views/blade/' . $fileName;
    }
}
