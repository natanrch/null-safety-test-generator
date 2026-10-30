<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\WriteTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\GuardPropagationController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class InterproceduralGuardGenerationTest extends TestCase
{
    public static function routes(): iterable
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach (['partial', 'mixed', 'mixedReverse'] as $method) {
                yield $verb . ' ' . $method => [$verb, $method];
            }
        }
    }

    #[DataProvider('routes')]
    public function test_generated_methods_respect_each_call_context(string $verb, string $method): void
    {
        $service = $verb === 'GET' ? NullSafetyTestGenerationService::class : WriteTestGenerationService::class;
        $result = $this->app->make($service)->generateMethods(
            GuardPropagationController::class, $method,
            ['name' => 'guard.context', 'method' => $verb, 'parameters' => ['post' => 'post']]
        );
        $this->assertTrue($result['generated']);
        $code = implode("\n", $result['testMethods']);
        if ($method === 'partial') {
            $this->assertStringContainsString('when_post_author_profile_is_null', $code);
            $this->assertStringNotContainsString('when_post_author_is_null', $code);
        } else {
            $this->assertStringContainsString('when_post_author_is_null', $code);
        }
    }
}
