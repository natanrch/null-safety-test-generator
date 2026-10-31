<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\JsonResponseAnalyzer;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StreamingJsonAnalyzerTest extends TestCase
{
    #[DataProvider('streamingMethods')]
    public function test_it_analyzes_streaming_json_responses(
        string $method
    ): void {
        $analysis = $this->analysis($method);
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

    public function test_it_identifies_cursor_as_a_streamed_model_collection(): void
    {
        $analysis = $this->analysis('streamedJsonCollection');
        $roots = array_values(array_filter(
            $analysis['accesses'] ?? [],
            static fn (array $access): bool =>
                ($access['root'] ?? null) === 'posts'
                && ($access['type'] ?? null) === 'collection'
        ));

        $this->assertNotEmpty($roots);
        $this->assertContains(
            'author.profile.name',
            array_map(
                static fn (array $access): string => implode(
                    '.',
                    array_column($access['accesses'], 'name')
                ),
                $analysis['accesses']
            )
        );
    }

    public function test_it_does_not_treat_a_generic_stream_as_json(): void
    {
        $this->assertSame([], $this->analysis('genericStream'));
    }

    public static function streamingMethods(): iterable
    {
        yield 'streamJson with generator' => ['streamJson'];
        yield 'stream with JSON content type' => ['streamedResponse'];
        yield 'StreamedJsonResponse' => ['streamedJsonResponse'];
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
