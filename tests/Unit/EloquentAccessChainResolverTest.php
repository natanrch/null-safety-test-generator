<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeAuthor;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeProfile;
use PHPUnit\Framework\TestCase;

class EloquentAccessChainResolverTest extends TestCase
{
    public function test_it_resolves_nested_relationships_in_an_access_chain(): void
    {
        $resolver = new EloquentAccessChainResolver(
            new EloquentRelationshipResolver()
        );

        $result = $resolver->resolve(
            FakePost::class,
            [
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
            ]
        );

        $this->assertSame([
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
        ], $result);
    }

    public function test_it_resolves_a_chained_has_many_as_a_relationship(): void
    {
        $result = (new EloquentAccessChainResolver(
            new EloquentRelationshipResolver()
        ))->resolve(FakeAuthor::class, [
            [
                'type' => 'property',
                'name' => 'orderedPosts',
            ],
        ]);

        $this->assertSame([
            [
                'model' => FakeAuthor::class,
                'property' => 'orderedPosts',
                'kind' => 'relationship',
                'relation' => 'hasMany',
                'relatedClass' => FakePost::class,
            ],
        ], $result);
    }
}
