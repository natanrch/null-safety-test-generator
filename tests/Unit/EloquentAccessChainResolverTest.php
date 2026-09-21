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
}
