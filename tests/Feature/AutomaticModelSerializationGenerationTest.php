<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class AutomaticModelSerializationGenerationTest extends TestCase
{
    public function test_it_generates_tests_for_null_sensitive_appended_accessors(): void
    {
        $result = $this->app
            ->make(NullSafetyTestGenerationService::class)
            ->generateMethods(
                JsonPostController::class,
                'directModel',
                [
                    'name' => 'json.direct-model',
                    'method' => 'GET',
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
        $this->assertStringNotContainsString(
            'when_post_author_posts_is_null',
            $code
        );
    }
}
