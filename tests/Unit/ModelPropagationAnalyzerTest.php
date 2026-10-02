<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ModelPropagationAnalyzer;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\GetServiceController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use PHPUnit\Framework\TestCase;

class ModelPropagationAnalyzerTest extends TestCase
{
    public function test_it_follows_a_controller_model_through_a_get_helper(): void
    {
        $result = (new ModelPropagationAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        ))->analyze(GetServiceController::class, 'viewResponse');

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

    public function test_entry_method_accesses_can_be_enabled_for_write_routes(): void
    {
        $resolver = new EloquentAccessChainResolver(
            new EloquentRelationshipResolver()
        );
        $getResult = (new ModelPropagationAnalyzer($resolver))->analyze(
            PostController::class,
            'processAuthorProfile'
        );
        $writeResult = (new ModelPropagationAnalyzer(
            $resolver,
            includeEntryMethodAccesses: true
        ))->analyze(
            PostController::class,
            'processAuthorProfile'
        );

        $this->assertSame([], $getResult);
        $this->assertNotSame([], $writeResult);
    }
}
