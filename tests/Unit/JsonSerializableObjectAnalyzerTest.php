<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\JsonResponseAnalyzer;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JsonSerializableObjectAnalyzerTest extends TestCase
{
    #[DataProvider('methods')]
    public function test_it_analyzes_dtos_and_serializable_objects(
        string $method
    ): void {
        $analysis = (new JsonResponseAnalyzer(
            new ControllerMethodAnalyzer(),
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        ))->analyze(JsonPostController::class, $method);
        $paths = array_map(
            static fn (array $access): string => implode(
                '.',
                array_column($access['accesses'], 'name')
            ),
            $analysis['accesses'] ?? []
        );

        $this->assertSame('json', $analysis['responseType'] ?? null);
        $this->assertContains('author.profile.name', $paths);
    }

    public static function methods(): iterable
    {
        yield 'plain DTO' => ['dto'];
        yield 'JsonSerializable' => ['jsonSerializable'];
        yield 'Arrayable' => ['arrayable'];
        yield 'direct Arrayable response' => ['directArrayable'];
    }
}
