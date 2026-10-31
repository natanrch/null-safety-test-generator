<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class ConditionalJsonResourceGenerationTest extends TestCase
{
    public function test_it_generates_only_unprotected_resource_scenarios(): void
    {
        $result = $this->app
            ->make(NullSafetyTestGenerationService::class)
            ->generateMethods(
                JsonPostController::class,
                'conditionalResource',
                [
                    'name' => 'json.conditional-resource',
                    'method' => 'GET',
                    'parameters' => ['post' => 'post'],
                ]
            );

        $this->assertTrue($result['generated']);
        $code = implode("\n", $result['testMethods']);
        $this->assertStringNotContainsString(
            'when_post_author_is_null',
            $code
        );
        $this->assertStringContainsString(
            'when_post_author_profile_is_null',
            $code
        );
    }
}
