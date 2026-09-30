<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\BladeAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerViewAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Services\ViewAnalysisService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\AnotherFakeObject;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithView;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeObject;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakePostControllerWithView;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithRequestInput;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\FakeControllerWithPagination;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeAuthor;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeProfile;
use PHPUnit\Framework\TestCase;

class ViewAnalysisServiceTest extends TestCase
{
    public function test_it_combines_controller_variables_with_blade_accesses(): void
    {
        $analyzer = $this->createAnalyzer();

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
                    'resolvedAccesses' => [
                        [
                            'model' => FakeObject::class,
                            'property' => 'name',
                            'kind' => 'attribute',
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
                    'resolvedAccesses' => [
                        [
                            'model' => AnotherFakeObject::class,
                            'property' => 'name',
                            'kind' => 'attribute',
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
                    'resolvedAccesses' => [
                        [
                            'model' => AnotherFakeObject::class,
                            'property' => 'name',
                            'kind' => 'attribute',
                        ],
                    ],
                ],
            ],
        ], $result);
    }

    public function test_it_resolves_eloquent_relationships_in_view_accesses(): void
    {
        $analyzer = $this->createAnalyzer();

        $result = $analyzer->analyze(
            FakePostControllerWithView::class,
            'show',
            __DIR__ . '/../Fixtures/views/posts/show.blade.php'
        );

        $this->assertSame([
            'view' => 'fixtures.posts.show',
            'accesses' => [
                [
                    'root' => 'post',
                    'class' => FakePost::class,
                    'type' => 'object',
                    'accesses' => [
                        [
                            'type' => 'property',
                            'name' => 'author',
                        ],
                        [
                            'type' => 'property',
                            'name' => 'profile',
                        ],
                        [
                            'type' => 'property',
                            'name' => 'name',
                        ],
                    ],
                    'resolvedAccesses' => [
                        [
                            'model' => FakePost::class,
                            'property' => 'author',
                            'kind' => 'relationship',
                            'relation' => 'belongsTo',
                            'relatedClass' => FakeAuthor::class,
                        ],
                        [
                            'model' => FakeAuthor::class,
                            'property' => 'profile',
                            'kind' => 'relationship',
                            'relation' => 'hasOne',
                            'relatedClass' => FakeProfile::class,
                        ],
                        [
                            'model' => FakeProfile::class,
                            'property' => 'name',
                            'kind' => 'attribute',
                        ],
                    ],
                ],
            ],
        ], $result);
    }

    public function test_it_preserves_request_input_in_the_combined_analysis(): void
    {
        $result = $this->createAnalyzer()->analyze(
            FakeControllerWithRequestInput::class,
            'create',
            __DIR__ . '/../Fixtures/views/objects/request.blade.php'
        );

        $this->assertSame([
            'source' => 'request',
            'parameter' => 'object_id',
            'valueFrom' => 'model_key',
        ], $result['accesses'][0]['input']);
    }

    public function test_it_produces_a_scenario_for_an_absent_request_parameter(): void
    {
        $analysis = $this->createAnalyzer()->analyze(
            FakeControllerWithRequestInput::class,
            'create',
            __DIR__ . '/../Fixtures/views/objects/request.blade.php'
        );
        $scenarios = (new NullScenarioGenerator())->generate($analysis);

        $this->assertSame(
            'missing_request_parameter',
            $scenarios[0]['strategy']
        );
        $this->assertSame(
            'object_id',
            $scenarios[0]['input']['parameter']
        );
    }

    public function test_it_produces_an_empty_scenario_for_a_paginated_view(): void
    {
        $analysis = $this->createAnalyzer()->analyze(
            FakeControllerWithPagination::class,
            'index',
            __DIR__ . '/../Fixtures/views/objects/index.blade.php'
        );
        $scenarios = (new NullScenarioGenerator())->generate($analysis);

        $this->assertSame(
            'paginatedObjects',
            $scenarios[0]['root']
        );
        $this->assertSame(
            'empty_root_collection',
            $scenarios[0]['strategy']
        );
        $this->assertSame([], $scenarios[0]['path']);
    }

    public function test_it_produces_an_empty_collection_scenario_for_forelse(): void
    {
        $analysis = $this->createAnalyzer()->analyze(
            FakeControllerWithView::class,
            'show',
            __DIR__ . '/../Fixtures/views/objects/forelse.blade.php'
        );
        $scenarios = (new NullScenarioGenerator())->generate($analysis);

        $this->assertSame('objects', $scenarios[0]['root']);
        $this->assertSame(
            'empty_root_collection',
            $scenarios[0]['strategy']
        );
        $this->assertCount(1, $scenarios);
    }

    public function test_it_resolves_a_collection_assigned_inside_a_php_block(): void
    {
        $analysis = $this->createAnalyzer()->analyze(
            FakePostControllerWithView::class,
            'show',
            __DIR__
                . '/../Fixtures/views/blade/php-assignment-forelse.blade.php'
        );
        $scenarios = (new NullScenarioGenerator())->generate($analysis);

        $this->assertSame(
            ['author'],
            $scenarios[0]['path']
        );
        $this->assertSame(
            'missing_relationship',
            $scenarios[0]['strategy']
        );
        $this->assertSame(
            ['author', 'posts'],
            $scenarios[1]['path']
        );
        $this->assertSame(
            'empty_collection',
            $scenarios[1]['strategy']
        );
        $this->assertCount(2, $scenarios);
    }

    public function test_it_generates_a_scenario_for_an_attribute_used_by_a_function(): void
    {
        $analysis = $this->createAnalyzer()->analyze(
            FakeControllerWithView::class,
            'show',
            __DIR__ . '/../Fixtures/views/objects/function-argument.blade.php'
        );
        $scenarios = (new NullScenarioGenerator())->generate($analysis);

        $this->assertSame(
            'function_argument',
            $analysis['accesses'][0]['usage']
        );
        $this->assertCount(1, $scenarios);
        $this->assertSame('null_attribute', $scenarios[0]['strategy']);
        $this->assertSame(['name'], $scenarios[0]['path']);
    }

    public function test_it_shares_request_preconditions_with_other_scenarios(): void
    {
        $analysis = $this->createAnalyzer()->analyze(
            FakeControllerWithRequestInput::class,
            'withCollection',
            __DIR__ . '/../Fixtures/views/objects/forelse.blade.php'
        );
        $scenarios = (new NullScenarioGenerator())->generate($analysis);

        $emptyCollection = array_values(array_filter(
            $scenarios,
            static fn (array $scenario): bool =>
                ($scenario['strategy'] ?? null) === 'empty_root_collection'
        ))[0];

        $this->assertSame('objects', $emptyCollection['root']);
        $this->assertSame([
            [
                'root' => 'object',
                'class' => AnotherFakeObject::class,
                'input' => [
                    'source' => 'request',
                    'parameter' => 'object_id',
                    'valueFrom' => 'model_key',
                ],
            ],
        ], $emptyCollection['requestPreconditions']);
    }

    public function test_it_resolves_relationships_inside_a_bound_component(): void
    {
        $analysis = $this->createAnalyzer()->analyze(
            FakePostControllerWithView::class,
            'show',
            __DIR__ . '/../Fixtures/views/blade/component-parent.blade.php'
        );
        $scenarios = (new NullScenarioGenerator())->generate($analysis);

        $this->assertSame(['author'], $scenarios[0]['path']);
        $this->assertSame('missing_relationship', $scenarios[0]['strategy']);
        $this->assertSame(
            ['author', 'profile'],
            $scenarios[1]['path']
        );
        $this->assertSame('missing_relationship', $scenarios[1]['strategy']);
        $this->assertCount(2, $scenarios);
    }

    private function createAnalyzer(): ViewAnalysisService
    {
        return new ViewAnalysisService(
            new ControllerViewAnalyzer(
                new ControllerMethodAnalyzer()
            ),
            new BladeAnalyzer(),
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        );
    }
}
