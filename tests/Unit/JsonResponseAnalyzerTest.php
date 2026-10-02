<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\JsonResponseAnalyzer;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use PHPUnit\Framework\TestCase;

class JsonResponseAnalyzerTest extends TestCase
{
    public function test_it_detects_chained_access_in_response_json(): void
    {
        $this->assertContains(
            ['author', 'profile', 'name'],
            $this->chains('jsonResponse')
        );
    }

    public function test_it_detects_chained_access_in_a_direct_array(): void
    {
        $this->assertContains(
            ['author', 'profile', 'name'],
            $this->chains('directArray')
        );
    }

    public function test_it_detects_a_direct_model_response(): void
    {
        $result = $this->analyzer()->analyze(
            JsonPostController::class,
            'directModel'
        );

        $this->assertSame('json', $result['responseType']);
        $this->assertSame('post', $result['accesses'][0]['root']);
        $this->assertSame('object', $result['accesses'][0]['type']);
        $this->assertSame([], $result['accesses'][0]['accesses']);
    }

    public function test_it_detects_a_direct_collection_response(): void
    {
        $result = $this->analyzer()->analyze(
            JsonPostController::class,
            'directCollection'
        );

        $this->assertSame('posts', $result['accesses'][0]['root']);
        $this->assertSame('collection', $result['accesses'][0]['type']);
    }

    public function test_it_analyzes_a_json_resource(): void
    {
        $this->assertContains(
            ['author', 'name'],
            $this->chains('resource')
        );
    }

    public function test_it_analyzes_a_resource_collection(): void
    {
        $result = $this->analyzer()->analyze(
            JsonPostController::class,
            'resourceCollection'
        );

        $this->assertSame('collection', $result['accesses'][0]['type']);
        $this->assertContains(['author', 'name'], array_map(
            static fn (array $access): array => array_column(
                $access['accesses'],
                'name'
            ),
            $result['accesses']
        ));
    }

    public function test_it_analyzes_nested_json_resources(): void
    {
        $this->assertContains(
            ['author', 'profile', 'name'],
            $this->chains('nestedResource')
        );
    }

    public function test_it_ignores_nullsafe_resource_accesses(): void
    {
        $this->assertSame([], $this->chains('safeResource'));
    }

    private function chains(string $method): array
    {
        $result = $this->analyzer()->analyze(
            JsonPostController::class,
            $method
        );

        return array_values(array_filter(array_map(
            static fn (array $access): array => array_column(
                $access['accesses'],
                'name'
            ),
            $result['accesses'] ?? []
        )));
    }

    private function analyzer(): JsonResponseAnalyzer
    {
        return new JsonResponseAnalyzer(
            new ControllerMethodAnalyzer(),
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        );
    }
}
