<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeAuthor;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeProfile;
use PHPUnit\Framework\TestCase;

class EloquentRelationshipResolverTest extends TestCase
{
    public function test_it_identifies_an_eloquent_relationship_in_an_access_chain(): void
    {
        $resolver = new EloquentRelationshipResolver();

        $result = $resolver->resolve(
            FakePost::class,
            'author'
        );

        $this->assertSame([
            'model' => FakePost::class,
            'property' => 'author',
            'kind' => 'relationship',
            'relation' => 'belongsTo',
            'relatedClass' => FakeAuthor::class,
        ], $result);
    }

    public function test_it_identifies_a_has_one_relationship(): void
    {
        $resolver = new EloquentRelationshipResolver();

        $result = $resolver->resolve(
            FakeAuthor::class,
            'profile'
        );

        $this->assertSame([
            'model' => FakeAuthor::class,
            'property' => 'profile',
            'kind' => 'relationship',
            'relation' => 'hasOne',
            'relatedClass' => FakeProfile::class,
        ], $result);
    }

    public function test_it_identifies_a_has_many_relationship(): void
    {
        $resolver = new EloquentRelationshipResolver();

        $result = $resolver->resolve(
            FakeAuthor::class,
            'posts'
        );

        $this->assertSame([
            'model' => FakeAuthor::class,
            'property' => 'posts',
            'kind' => 'relationship',
            'relation' => 'hasMany',
            'relatedClass' => FakePost::class,
        ], $result);
    }

    public function test_it_identifies_a_relationship_inside_a_chained_query(): void
    {
        $result = (new EloquentRelationshipResolver())->resolve(
            FakeAuthor::class,
            'orderedPosts'
        );

        $this->assertSame([
            'model' => FakeAuthor::class,
            'property' => 'orderedPosts',
            'kind' => 'relationship',
            'relation' => 'hasMany',
            'relatedClass' => FakePost::class,
        ], $result);
    }
}
