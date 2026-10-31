<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\JsonResponseAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use PHPUnit\Framework\TestCase;

class AutomaticModelSerializationTest extends TestCase
{
    public function test_it_analyzes_visible_appended_accessors(): void
    {
        $paths = $this->accessPaths('directModel');

        $this->assertContains('author.profile.name', $paths);
    }

    public function test_it_ignores_hidden_appended_accessors(): void
    {
        $this->assertNotContains(
            'author.posts',
            $this->accessPaths('directModel')
        );
    }

    public function test_it_respects_the_model_visible_list(): void
    {
        $paths = $this->accessPaths('visibleModel');

        $this->assertContains('category.name', $paths);
        $this->assertNotContains('author.profile.name', $paths);
    }

    public function test_it_recognizes_serialized_casts_without_generating_a_null_scenario_for_them(): void
    {
        $analysis = $this->analysis('directModel');
        $casts = array_values(array_filter(
            $analysis['accesses'],
            static fn (array $access): bool => array_column(
                $access['accesses'],
                'name'
            ) === ['published_at']
        ));

        $this->assertNotEmpty($casts);
        $this->assertNotContains(
            'published_at',
            $this->scenarioPaths($analysis)
        );
    }

    public function test_it_recursively_analyzes_loaded_relationship_models(): void
    {
        $analysis = $this->analysis('loadedModel');
        $paths = $this->scenarioPaths($analysis);

        $this->assertNotContains('author.posts', $paths);
        $this->assertContains('author.profile', $paths);
        $this->assertContains('author.posts.author', $paths);
        $this->assertContains(
            'author.posts.author.profile',
            $paths
        );
    }

    public function test_it_recursively_analyzes_relationships_loaded_with_the_query(): void
    {
        $paths = $this->scenarioPaths(
            $this->analysis('eagerLoadedModel')
        );

        $this->assertContains('author.profile', $paths);
        $this->assertContains('author.posts.author', $paths);
    }

    private function accessPaths(string $method): array
    {
        return array_map(
            static fn (array $access): string => implode(
                '.',
                array_column($access['accesses'], 'name')
            ),
            $this->analysis($method)['accesses']
        );
    }

    private function scenarioPaths(array $analysis): array
    {
        return array_values(array_unique(array_map(
            static fn (array $scenario): string => implode(
                '.',
                $scenario['path']
            ),
            (new NullScenarioGenerator())->generate($analysis)
        )));
    }

    private function analysis(string $method): array
    {
        return (new JsonResponseAnalyzer(
            new ControllerMethodAnalyzer(),
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        ))->analyze(JsonPostController::class, $method);
    }
}
