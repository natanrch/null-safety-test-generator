<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\WriteControllerAccessAnalyzer;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Profile;
use PHPUnit\Framework\TestCase;

class WriteControllerAccessAnalyzerTest extends TestCase
{
    public function test_it_resolves_a_relationship_chain_through_an_assigned_variable(): void
    {
        $analyzer = new WriteControllerAccessAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        );

        $result = $analyzer->analyze(
            PostController::class,
            'processAuthorProfile'
        );
        $chain = array_values(array_filter(
            $result,
            static fn (array $access): bool => count($access['accesses']) === 3
        ))[0];

        $this->assertSame('post', $chain['root']);
        $this->assertSame(['author', 'profile', 'name'], array_column(
            $chain['accesses'],
            'name'
        ));
        $this->assertSame('belongsTo', $chain['resolvedAccesses'][0]['relation']);
        $this->assertSame(Author::class, $chain['resolvedAccesses'][0]['relatedClass']);
        $this->assertSame('hasOne', $chain['resolvedAccesses'][1]['relation']);
        $this->assertSame(Profile::class, $chain['resolvedAccesses'][1]['relatedClass']);
        $this->assertSame(Post::class, $chain['class']);
        $this->assertSame('attribute', $chain['resolvedAccesses'][2]['kind']);
    }
}
