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
}
