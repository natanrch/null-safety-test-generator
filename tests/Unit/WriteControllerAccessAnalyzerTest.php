<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ModelPropagationAnalyzer;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\AssignedServiceController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\MethodInjectedServiceController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PromotedServiceController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\TypedPropertyServiceController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Profile;
use PHPUnit\Framework\TestCase;

class WriteControllerAccessAnalyzerTest extends TestCase
{
    public function test_it_resolves_a_relationship_chain_through_an_assigned_variable(): void
    {
        $analyzer = new ModelPropagationAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            ),
            includeEntryMethodAccesses: true
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

    public function test_it_follows_a_model_into_a_promoted_service_property(): void
    {
        $this->assertServiceChainWasFound(PromotedServiceController::class);
    }

    public function test_it_follows_a_model_into_a_typed_service_property(): void
    {
        $this->assertServiceChainWasFound(TypedPropertyServiceController::class);
    }

    public function test_it_resolves_a_service_assigned_in_the_constructor(): void
    {
        $this->assertServiceChainWasFound(AssignedServiceController::class);
    }

    public function test_it_follows_a_model_into_a_method_injected_service(): void
    {
        $this->assertServiceChainWasFound(MethodInjectedServiceController::class);
    }

    private function assertServiceChainWasFound(string $controller): void
    {
        $result = $this->analyzer()->analyze($controller, 'process');
        $chains = array_map(
            static fn (array $access): array => array_column(
                $access['accesses'],
                'name'
            ),
            $result
        );

        $this->assertContains(
            ['author', 'profile', 'name'],
            $chains
        );
    }

    private function analyzer(): ModelPropagationAnalyzer
    {
        return new ModelPropagationAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            ),
            includeEntryMethodAccesses: true
        );
    }
}
