<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ModelPropagationAnalyzer;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
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

    public function test_it_ignores_a_protected_chain_inside_a_get_helper(): void
    {
        $accesses = (new ModelPropagationAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        ))->analyze(GetServiceController::class, 'safeViewResponse');

        $this->assertSame([], (new NullScenarioGenerator())->generate([
            'accesses' => $accesses,
        ]));
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

    public function test_a_direct_primary_key_access_does_not_generate_a_null_scenario(): void
    {
        $scenarios = $this->scenariosFor('processId');

        $this->assertSame([], $scenarios);
    }

    public function test_a_relationship_before_a_terminal_id_still_generates_its_scenario(): void
    {
        $scenarios = $this->scenariosFor('processAuthorId');

        $this->assertCount(1, $scenarios);
        $this->assertSame(['author'], $scenarios[0]['path']);
        $this->assertSame(
            'missing_relationship',
            $scenarios[0]['strategy']
        );
    }

    public function test_a_property_used_by_a_function_remains_null_sensitive(): void
    {
        $scenarios = $this->scenariosFor('processSensitiveTitle');

        $this->assertCount(1, $scenarios);
        $this->assertSame(['title'], $scenarios[0]['path']);
        $this->assertSame('null_attribute', $scenarios[0]['strategy']);
    }

    public function test_optional_access_does_not_generate_null_scenarios(): void
    {
        $this->assertSame([], $this->scenariosFor('processOptionalAuthor'));
    }

    public function test_coalesced_access_does_not_generate_null_scenarios(): void
    {
        $this->assertSame([], $this->scenariosFor('processCoalescedProfile'));
    }

    public function test_an_if_guard_does_not_generate_null_scenarios(): void
    {
        $this->assertSame([], $this->scenariosFor('processGuardedAuthor'));
    }

    public function test_an_explicit_null_guard_does_not_generate_null_scenarios(): void
    {
        $this->assertSame([], $this->scenariosFor('processComparedGuard'));
    }

    public function test_a_ternary_guard_does_not_generate_null_scenarios(): void
    {
        $this->assertSame([], $this->scenariosFor('processTernaryGuard'));
    }

    public function test_isset_does_not_generate_null_scenarios(): void
    {
        $this->assertSame([], $this->scenariosFor('processIssetGuard'));
    }

    public function test_empty_does_not_generate_null_scenarios(): void
    {
        $this->assertSame([], $this->scenariosFor('processEmptyGuard'));
    }

    public function test_nullsafe_access_does_not_generate_null_scenarios(): void
    {
        $this->assertSame([], $this->scenariosFor('processNullsafeAuthor'));
    }

    public function test_a_non_null_guard_comparison_remains_null_sensitive(): void
    {
        $scenarios = $this->scenariosFor('processComparedTitle');

        $this->assertCount(1, $scenarios);
        $this->assertSame(['title'], $scenarios[0]['path']);
    }

    private function scenariosFor(string $method): array
    {
        $accesses = (new ModelPropagationAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            ),
            includeEntryMethodAccesses: true
        ))->analyze(PostController::class, $method);

        return (new NullScenarioGenerator())->generate([
            'accesses' => $accesses,
        ]);
    }
}
