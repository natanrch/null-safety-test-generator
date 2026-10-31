<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\JsonResponseAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use PHPUnit\Framework\TestCase;

class ConditionalJsonResourceTest extends TestCase
{
    public function test_when_and_merge_when_protect_the_guarded_relationship(): void
    {
        $paths = $this->scenarioPaths();

        $this->assertNotContains('author', $paths);
        $this->assertContains('author.profile', $paths);
    }

    public function test_when_loaded_propagates_the_protected_relation_to_a_nested_resource(): void
    {
        $analysis = $this->analysis();
        $nested = array_values(array_filter(
            $analysis['accesses'],
            static fn (array $access): bool => array_column(
                $access['accesses'],
                'name'
            ) === ['author', 'profile', 'name']
        ));

        $this->assertNotEmpty($nested);
        $this->assertSame(1, $nested[0]['protectedAccessCount']);
    }

    public function test_when_not_null_does_not_create_a_scenario_for_its_value(): void
    {
        $this->assertNotContains('author', $this->scenarioPaths());
    }

    public function test_metadata_helpers_do_not_create_relationship_scenarios(): void
    {
        $paths = $this->scenarioPaths();

        $this->assertNotContains('title', $paths);
        $this->assertNotContains('posts', $paths);
    }

    private function scenarioPaths(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $scenario): string => implode(
                '.',
                $scenario['path']
            ),
            (new NullScenarioGenerator())->generate($this->analysis())
        )));
    }

    private function analysis(): array
    {
        return (new JsonResponseAnalyzer(
            new ControllerMethodAnalyzer(),
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        ))->analyze(
            JsonPostController::class,
            'conditionalResource'
        );
    }
}
