<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\WriteTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\NewStaticCollectionPropagationController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class NewStaticCollectionGenerationTest extends TestCase
{
    public static function routes(): iterable
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach ([
                'localNew',
                'constructorNew',
                'staticCall',
                'collectionCallback',
            ] as $method) {
                yield $verb . ' ' . $method => [$verb, $method];
            }
        }
    }

    #[DataProvider('routes')]
    public function test_generated_tests_include_models_propagated_through_supported_constructs(
        string $verb,
        string $method
    ): void {
        $service = $verb === 'GET'
            ? NullSafetyTestGenerationService::class
            : WriteTestGenerationService::class;
        $result = $this->app->make($service)->generateMethods(
            NewStaticCollectionPropagationController::class,
            $method,
            [
                'name' => 'propagation.' . strtolower($verb),
                'method' => $verb,
                'parameters' => ['post' => 'post'],
            ]
        );

        $this->assertTrue($result['generated']);
        $code = implode("\n", $result['testMethods']);
        $this->assertStringContainsString('when_post_author_is_null', $code);

        if ($method === 'collectionCallback') {
            $this->assertStringContainsString(
                $verb === 'GET'
                    ? 'when_post_author_posts_is_null'
                    : 'when_post_author_posts_is_empty',
                $code
            );
            $this->assertStringContainsString(
                'when_post_author_posts_author_is_null',
                $code
            );
        } else {
            $this->assertStringContainsString(
                'when_post_author_profile_is_null',
                $code
            );
        }
    }
}
