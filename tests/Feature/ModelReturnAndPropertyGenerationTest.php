<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\WriteTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\ReturnAndPropertyPropagationController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ModelReturnAndPropertyGenerationTest extends TestCase
{
    public static function routes(): iterable
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach ([
                'returned',
                'storedAcrossCalls',
                'returnedFromProperty',
            ] as $method) {
                yield $verb . ' ' . $method => [$verb, $method];
            }
        }
    }

    #[DataProvider('routes')]
    public function test_generated_tests_follow_returns_and_stored_models(
        string $verb,
        string $method
    ): void {
        $service = $verb === 'GET'
            ? NullSafetyTestGenerationService::class
            : WriteTestGenerationService::class;
        $result = $this->app->make($service)->generateMethods(
            ReturnAndPropertyPropagationController::class,
            $method,
            [
                'name' => 'propagation.' . strtolower($verb),
                'method' => $verb,
                'parameters' => ['post' => 'post'],
            ]
        );

        $this->assertTrue($result['generated']);
        $code = implode("\n", $result['testMethods']);
        $this->assertStringContainsString(
            'when_post_author_is_null',
            $code
        );
        $this->assertStringContainsString(
            'when_post_author_profile_is_null',
            $code
        );
    }
}
