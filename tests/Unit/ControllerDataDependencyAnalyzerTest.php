<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ControllerDataDependencyAnalyzer;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use PHPUnit\Framework\TestCase;

class ControllerDataDependencyAnalyzerTest extends TestCase
{
    public function test_it_finds_a_model_required_by_a_downstream_collection(): void
    {
        $result = (new ControllerDataDependencyAnalyzer())->analyze(
            JsonPostController::class,
            'collectionUsingNullableRoot'
        );

        $this->assertSame([[
            'root' => 'author',
            'class' => Author::class,
            'retrievalMethod' => 'first',
            'constraints' => ['name' => 'active'],
        ]], $result['posts'] ?? null);
    }

    public function test_it_keeps_a_required_model_as_a_precondition(): void
    {
        $result = (new ControllerDataDependencyAnalyzer())->analyze(
            JsonPostController::class,
            'collectionUsingRequiredRoot'
        );

        $this->assertSame(
            'firstOrFail',
            $result['posts'][0]['retrievalMethod'] ?? null
        );
        $this->assertSame(
            ['name' => 'active'],
            $result['posts'][0]['constraints'] ?? null
        );
    }

    public function test_it_uses_a_literal_find_key_as_a_factory_constraint(): void
    {
        $result = (new ControllerDataDependencyAnalyzer())->analyze(
            JsonPostController::class,
            'collectionUsingFindRoot'
        );

        $this->assertSame(
            ['id' => 1],
            $result['posts'][0]['constraints'] ?? null
        );
    }

    public function test_it_uses_first_where_arguments_as_factory_constraints(): void
    {
        $result = (new ControllerDataDependencyAnalyzer())->analyze(
            JsonPostController::class,
            'collectionUsingFirstWhereRoot'
        );

        $this->assertSame(
            ['name' => 'active'],
            $result['posts'][0]['constraints'] ?? null
        );
    }

    public function test_it_does_not_attach_an_unrelated_model_to_a_collection(): void
    {
        $result = (new ControllerDataDependencyAnalyzer())->analyze(
            JsonPostController::class,
            'collectionWithUnusedNullableRoot'
        );

        $this->assertArrayNotHasKey('posts', $result);
    }
}
