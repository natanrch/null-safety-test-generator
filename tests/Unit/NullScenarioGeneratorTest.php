<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeAuthor;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeProfile;
use PHPUnit\Framework\TestCase;

class NullScenarioGeneratorTest extends TestCase
{
    public function test_it_generates_null_scenarios_for_relationships_and_attributes(): void
    {
        $generator = new NullScenarioGenerator();

        $result = $generator->generate([
            'view' => 'fixtures.posts.show',
            'accesses' => [
                [
                    'root' => 'post',
                    'class' => FakePost::class,
                    'type' => 'object',
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
        ]);

        $this->assertSame([
            [
                'root' => 'post',
                'rootClass' => FakePost::class,
                'rootType' => 'object',
                'path' => ['author'],
                'resolvedPath' => [
                    [
                        'model' => FakePost::class,
                        'property' => 'author',
                        'kind' => 'relationship',
                        'relation' => 'belongsTo',
                        'relatedClass' => FakeAuthor::class,
                    ],
                ],
                'target' => [
                    'model' => FakePost::class,
                    'property' => 'author',
                    'kind' => 'relationship',
                    'relation' => 'belongsTo',
                    'relatedClass' => FakeAuthor::class,
                ],
                'strategy' => 'missing_relationship',
            ],
            [
                'root' => 'post',
                'rootClass' => FakePost::class,
                'rootType' => 'object',
                'path' => ['author', 'profile'],
                'resolvedPath' => [
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
                ],
                'target' => [
                    'model' => FakeAuthor::class,
                    'property' => 'profile',
                    'kind' => 'relationship',
                    'relation' => 'hasOne',
                    'relatedClass' => FakeProfile::class,
                ],
                'strategy' => 'missing_relationship',
            ],
            [
                'root' => 'post',
                'rootClass' => FakePost::class,
                'rootType' => 'object',
                'path' => ['author', 'profile', 'name'],
                'resolvedPath' => [
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
                'target' => [
                    'model' => FakeProfile::class,
                    'property' => 'name',
                    'kind' => 'attribute',
                ],
                'strategy' => 'null_attribute',
            ],
        ], $result);
    }

    public function test_it_generates_an_empty_collection_scenario_for_has_many(): void
    {
        $generator = new NullScenarioGenerator();

        $result = $generator->generate([
            'view' => 'fixtures.authors.show',
            'accesses' => [
                [
                    'root' => 'author',
                    'class' => FakeAuthor::class,
                    'type' => 'object',
                    'resolvedAccesses' => [
                        [
                            'model' => FakeAuthor::class,
                            'property' => 'posts',
                            'kind' => 'relationship',
                            'relation' => 'hasMany',
                            'relatedClass' => FakePost::class,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame([
            [
                'root' => 'author',
                'rootClass' => FakeAuthor::class,
                'rootType' => 'object',
                'path' => ['posts'],
                'resolvedPath' => [
                    [
                        'model' => FakeAuthor::class,
                        'property' => 'posts',
                        'kind' => 'relationship',
                        'relation' => 'hasMany',
                        'relatedClass' => FakePost::class,
                    ],
                ],
                'target' => [
                    'model' => FakeAuthor::class,
                    'property' => 'posts',
                    'kind' => 'relationship',
                    'relation' => 'hasMany',
                    'relatedClass' => FakePost::class,
                ],
                'strategy' => 'empty_collection',
            ],
        ], $result);
    }

    public function test_it_preserves_the_resolved_relationship_path_for_factory_generation(): void
    {
        $generator = new NullScenarioGenerator();

        $authorRelationship = [
            'model' => FakePost::class,
            'property' => 'author',
            'kind' => 'relationship',
            'relation' => 'belongsTo',
            'relatedClass' => FakeAuthor::class,
        ];

        $profileRelationship = [
            'model' => FakeAuthor::class,
            'property' => 'profile',
            'kind' => 'relationship',
            'relation' => 'hasOne',
            'relatedClass' => FakeProfile::class,
        ];

        $nameAttribute = [
            'model' => FakeProfile::class,
            'property' => 'name',
            'kind' => 'attribute',
        ];

        $result = $generator->generate([
            'view' => 'fixtures.posts.show',
            'accesses' => [
                [
                    'root' => 'post',
                    'class' => FakePost::class,
                    'type' => 'object',
                    'resolvedAccesses' => [
                        $authorRelationship,
                        $profileRelationship,
                        $nameAttribute,
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            [
                $authorRelationship,
                $profileRelationship,
                $nameAttribute,
            ],
            $result[2]['resolvedPath'] ?? null
        );
    }

    public function test_it_preserves_request_input_metadata(): void
    {
        $result = (new NullScenarioGenerator())->generate([
            'view' => 'posts.create',
            'accesses' => [[
                'root' => 'post',
                'class' => FakePost::class,
                'type' => 'object',
                'input' => [
                    'source' => 'request',
                    'parameter' => 'post_id',
                    'valueFrom' => 'model_key',
                ],
                'resolvedAccesses' => [[
                    'model' => FakePost::class,
                    'property' => 'title',
                    'kind' => 'attribute',
                ]],
            ]],
        ]);

        $this->assertSame('missing_request_parameter', $result[0]['strategy']);
        $this->assertSame([
            'source' => 'request',
            'parameter' => 'post_id',
            'valueFrom' => 'model_key',
        ], $result[1]['input']);
    }

    public function test_it_generates_a_missing_request_parameter_scenario(): void
    {
        $result = (new NullScenarioGenerator())->generate([
            'view' => 'posts.create',
            'accesses' => [[
                'root' => 'post',
                'class' => FakePost::class,
                'type' => 'object',
                'input' => [
                    'source' => 'request',
                    'parameter' => 'post_id',
                    'valueFrom' => 'model_key',
                ],
                'resolvedAccesses' => [[
                    'model' => FakePost::class,
                    'property' => 'title',
                    'kind' => 'attribute',
                ]],
            ]],
        ]);

        $this->assertSame([
            'root' => 'post',
            'rootClass' => FakePost::class,
            'rootType' => 'object',
            'path' => [],
            'resolvedPath' => [],
            'target' => [
                'kind' => 'request_parameter',
                'parameter' => 'post_id',
            ],
            'strategy' => 'missing_request_parameter',
            'input' => [
                'source' => 'request',
                'parameter' => 'post_id',
                'valueFrom' => 'model_key',
            ],
        ], $result[0]);
    }

    public function test_it_generates_an_empty_scenario_for_a_root_collection(): void
    {
        $result = (new NullScenarioGenerator())->generate([
            'view' => 'posts.index',
            'accesses' => [[
                'root' => 'posts',
                'class' => FakePost::class,
                'type' => 'collection',
                'alias' => 'post',
                'resolvedAccesses' => [[
                    'model' => FakePost::class,
                    'property' => 'title',
                    'kind' => 'attribute',
                ]],
            ]],
        ]);

        $this->assertSame([
            'root' => 'posts',
            'rootClass' => FakePost::class,
            'rootType' => 'collection',
            'path' => [],
            'resolvedPath' => [],
            'target' => [
                'model' => FakePost::class,
                'kind' => 'collection',
            ],
            'strategy' => 'empty_root_collection',
        ], $result[0]);
        $this->assertSame('null_attribute', $result[1]['strategy']);
    }

    public function test_it_ignores_an_attribute_used_only_as_direct_output(): void
    {
        $result = $this->generateAttributeScenarios([
            ['type' => 'property', 'name' => 'title'],
        ]);

        $this->assertSame([], $result);
    }

    public function test_it_generates_a_scenario_for_a_dereferenced_attribute(): void
    {
        $result = $this->generateAttributeScenarios([
            ['type' => 'property', 'name' => 'published_at'],
            ['type' => 'method', 'name' => 'format'],
        ], null, 'published_at');

        $this->assertSame('null_attribute', $result[0]['strategy']);
    }

    public function test_it_generates_a_scenario_for_a_function_argument(): void
    {
        $result = $this->generateAttributeScenarios(
            [['type' => 'property', 'name' => 'title']],
            'function_argument'
        );

        $this->assertSame('null_attribute', $result[0]['strategy']);
    }

    public function test_it_generates_a_scenario_for_a_method_argument(): void
    {
        $result = $this->generateAttributeScenarios(
            [['type' => 'property', 'name' => 'title']],
            'method_argument'
        );

        $this->assertSame('null_attribute', $result[0]['strategy']);
    }

    public function test_it_generates_a_scenario_for_a_static_method_argument(): void
    {
        $result = $this->generateAttributeScenarios(
            [['type' => 'property', 'name' => 'title']],
            'static_method_argument'
        );

        $this->assertSame('null_attribute', $result[0]['strategy']);
    }

    public function test_it_generates_a_scenario_for_a_binary_operation(): void
    {
        $result = $this->generateAttributeScenarios(
            [['type' => 'property', 'name' => 'title']],
            'binary_operation'
        );

        $this->assertSame('null_attribute', $result[0]['strategy']);
    }

    public function test_it_generates_a_scenario_for_an_array_access(): void
    {
        $result = $this->generateAttributeScenarios(
            [['type' => 'property', 'name' => 'title']],
            'array_access'
        );

        $this->assertSame('null_attribute', $result[0]['strategy']);
    }

    public function test_it_ignores_an_attribute_protected_by_null_coalescing(): void
    {
        $result = $this->generateAttributeScenarios(
            [['type' => 'property', 'name' => 'title']],
            'null_coalescing'
        );

        $this->assertSame([], $result);
    }

    public function test_it_generates_only_the_nullable_object_attribute_in_a_value_object_chain(): void
    {
        $result = (new NullScenarioGenerator())->generate([
            'view' => 'posts.show',
            'accesses' => [[
                'root' => 'post',
                'class' => FakePost::class,
                'type' => 'object',
                'accesses' => [
                    ['type' => 'property', 'name' => 'address'],
                    ['type' => 'property', 'name' => 'street'],
                ],
                'resolvedAccesses' => [
                    [
                        'model' => FakePost::class,
                        'property' => 'address',
                        'kind' => 'attribute',
                        'valueClass' => FakeProfile::class,
                        'valueSource' => 'accessor',
                    ],
                    [
                        'model' => FakeProfile::class,
                        'property' => 'street',
                        'kind' => 'attribute',
                    ],
                ],
            ]],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame(['address'], $result[0]['path']);
        $this->assertSame('null_attribute', $result[0]['strategy']);
    }

    private function generateAttributeScenarios(
        array $accesses,
        ?string $usage = null,
        string $property = 'title'
    ): array {
        $analyzedAccess = [
            'root' => 'post',
            'class' => FakePost::class,
            'type' => 'object',
            'accesses' => $accesses,
            'resolvedAccesses' => [[
                'model' => FakePost::class,
                'property' => $property,
                'kind' => 'attribute',
            ]],
        ];

        if ($usage !== null) {
            $analyzedAccess['usage'] = $usage;
        }

        return (new NullScenarioGenerator())->generate([
            'view' => 'posts.show',
            'accesses' => [$analyzedAccess],
        ]);
    }
}
