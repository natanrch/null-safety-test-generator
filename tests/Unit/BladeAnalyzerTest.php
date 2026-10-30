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
                'protectedAccessCount' => 1,
            ],
            [
                'root' => 'objects',
                'alias' => 'item',
                'accesses' => [
                    ['type' => 'property', 'name' => 'date'],
                ],
                'protectedAccessCount' => 1,
            ],
            [
                'root' => 'objects',
                'alias' => 'item',
                'accesses' => [
                    ['type' => 'property', 'name' => 'date'],
                    ['type' => 'method', 'name' => 'format'],
                ],
                'protectedAccessCount' => 1,
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

    public function test_it_recursively_analyzes_an_extended_layout(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('extends-layout.blade.php')
        );

        $this->assertContains([
            'root' => 'object',
            'accesses' => [
                ['type' => 'property', 'name' => 'relation'],
                ['type' => 'property', 'name' => 'layoutName'],
            ],
        ], $result);
    }

    public function test_it_resolves_bound_properties_in_blade_components(): void
    {
        $result = (new BladeAnalyzer())->analyze(
            $this->viewPath('component-parent.blade.php')
        );

        $this->assertSame([
            [
                'root' => 'post',
                'alias' => 'author',
                'accesses' => [
                    ['type' => 'property', 'name' => 'author'],
                    ['type' => 'property', 'name' => 'profile'],
                    ['type' => 'property', 'name' => 'name'],
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

    public function test_it_analyzes_unescaped_output(): void
    {
        $result = (new BladeAnalyzer())->analyze($this->viewPath('raw-output.blade.php'));
        $this->assertContains([
            'root' => 'object',
            'accesses' => [
                ['type' => 'property', 'name' => 'relation'],
                ['type' => 'property', 'name' => 'name'],
            ],
        ], $result);
        $this->assertContains([
            'root' => 'object',
            'accesses' => [['type' => 'property', 'name' => 'title']],
            'usage' => 'function_argument',
        ], $result);
    }

    public function test_it_maps_include_parameters_to_the_original_model(): void
    {
        $result = (new BladeAnalyzer())->analyze($this->viewPath('includes/parameters.blade.php'));
        $this->assertContains([
            'root' => 'post', 'alias' => 'item',
            'accesses' => [
                ['type' => 'property', 'name' => 'author'],
                ['type' => 'property', 'name' => 'profile'],
                ['type' => 'property', 'name' => 'name'],
            ],
        ], $result);
    }

    public function test_it_passes_foreach_context_to_includes(): void
    {
        $result = (new BladeAnalyzer())->analyze($this->viewPath('includes/loop-context.blade.php'));
        $this->assertContains([
            'root' => 'posts', 'alias' => 'item',
            'accesses' => [
                ['type' => 'property', 'name' => 'profile'],
                ['type' => 'property', 'name' => 'name'],
            ],
        ], $result);
    }

    public function test_it_resolves_conditional_include_views_and_parameters(): void
    {
        $result = (new BladeAnalyzer())->analyze($this->viewPath('includes/conditional.blade.php'));
        foreach (['author', 'editor'] as $relation) {
            $this->assertContains([
                'root' => 'post', 'alias' => 'item',
                'accesses' => [
                    ['type' => 'property', 'name' => $relation],
                    ['type' => 'property', 'name' => 'profile'],
                    ['type' => 'property', 'name' => 'name'],
                ],
            ], $result);
        }
    }

    public function test_it_preserves_php_aliases_across_nested_includes_and_stops_cycles(): void
    {
        $result = (new BladeAnalyzer())->analyze($this->viewPath('includes/nested-context.blade.php'));
        $this->assertContains([
            'root' => 'post', 'alias' => 'item',
            'accesses' => [
                ['type' => 'property', 'name' => 'author'],
                ['type' => 'property', 'name' => 'profile'],
                ['type' => 'property', 'name' => 'name'],
            ],
        ], $result);
        $this->assertNotContains('ignored', array_column($result, 'root'));
        $this->assertCount(2, $result);
    }

    public function test_it_analyzes_attribute_directives(): void
    {
        $result = (new BladeAnalyzer())->analyze($this->viewPath('attribute-directives.blade.php'));
        foreach (['author', 'editor', 'reviewer', 'owner', 'manager', 'creator', 'publisher'] as $relation) {
            $this->assertContains([
                'root' => 'post',
                'accesses' => [
                    ['type' => 'property', 'name' => $relation],
                    ['type' => 'property', 'name' => 'active'],
                ],
            ], $result);
        }
    }

    public function test_a_guarded_blade_generates_only_the_unprotected_relationship_scenario(): void
    {
        $accesses = (new BladeAnalyzer())->analyze($this->viewPath('guarded-only.blade.php'));
        $resolver = new \Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver(
            new \Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver()
        );
        foreach ($accesses as &$access) {
            $access['class'] = \Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost::class;
            $access['type'] = 'object';
            $access['resolvedAccesses'] = $resolver->resolve($access['class'], $access['accesses']);
        }
        unset($access);
        $scenarios = (new \Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator())->generate([
            'accesses' => $accesses,
        ]);
        $this->assertSame([['author', 'profile']], array_column($scenarios, 'path'));
    }

    public function test_it_marks_only_the_guarded_prefix_and_preserves_unguarded_accesses(): void
    {
        $result = (new BladeAnalyzer())->analyze($this->viewPath('guarded-accesses.blade.php'));
        foreach (['author', 'editor', 'owner', 'reviewer'] as $relation) {
            $matches = array_values(array_filter($result, static fn (array $access): bool =>
                ($access['accesses'][0]['name'] ?? null) === $relation
                && count($access['accesses']) > 1
            ));
            $this->assertSame(1, $matches[0]['protectedAccessCount'] ?? 0);
        }
        $this->assertContains([
            'root' => 'post',
            'accesses' => [
                ['type' => 'property', 'name' => 'author'],
                ['type' => 'property', 'name' => 'name'],
            ],
        ], $result);
    }

    public function test_include_guards_cross_nested_files_without_leaking_to_other_calls(): void
    {
        $result = (new BladeAnalyzer())->analyze($this->viewPath('includes/guard-parent.blade.php'));
        $expected = [
            'root' => 'post', 'alias' => 'item',
            'accesses' => [
                ['type' => 'property', 'name' => 'author'],
                ['type' => 'property', 'name' => 'profile'],
                ['type' => 'property', 'name' => 'name'],
            ],
        ];
        $this->assertContains([...$expected, 'protectedAccessCount' => 1], $result);
        $this->assertContains($expected, $result);
    }

    public function test_conditional_includes_propagate_their_own_guards(): void
    {
        $result = (new BladeAnalyzer())->analyze($this->viewPath('includes/guard-conditional.blade.php'));
        foreach (['author', 'editor'] as $relation) {
            $this->assertContains([
                'root' => 'post', 'alias' => 'item',
                'accesses' => [
                    ['type' => 'property', 'name' => $relation],
                    ['type' => 'property', 'name' => 'profile'],
                    ['type' => 'property', 'name' => 'name'],
                ],
                'protectedAccessCount' => 1,
            ], $result);
        }
    }

    private function viewPath(string $fileName): string
    {
        return __DIR__ . '/../Fixtures/views/blade/' . $fileName;
    }
}
