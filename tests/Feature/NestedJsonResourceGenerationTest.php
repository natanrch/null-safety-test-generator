<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class NestedJsonResourceGenerationTest extends TestCase
{
    public function test_it_generates_tests_for_a_resource_created_with_make(): void
    {
        $code = $this->generatedCode('resourceMake');

        $this->assertStringContainsString(
            'when_post_author_is_null',
            $code
        );
    }

    public function test_it_generates_tests_for_a_nested_resource_collection(): void
    {
        $code = $this->generatedCode('nestedResourceCollection');

        $this->assertStringContainsString(
            'when_post_author_posts_is_null',
            $code
        );
        $this->assertStringContainsString(
            'when_post_author_posts_author_is_null',
            $code
        );
    }

    private function generatedCode(string $method): string
    {
        $result = $this->app
            ->make(NullSafetyTestGenerationService::class)
            ->generateMethods(
                JsonPostController::class,
                $method,
                [
                    'name' => 'json.' . $method,
                    'method' => 'GET',
                    'parameters' => ['post' => 'post'],
                ]
            );

        $this->assertTrue($result['generated']);

        return implode("\n", $result['testMethods']);
    }
}
