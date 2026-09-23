<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Illuminate\Routing\Router;
use Natan\NullSafetyTestGenerator\Services\BatchNullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\ApiPostController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class BatchNullSafetyTestGenerationServiceTest extends TestCase
{
    public function test_it_generates_one_file_only_for_get_routes_with_views(): void
    {
        $this->app->make(Router::class)
            ->get('/api/posts/{post}', [ApiPostController::class, 'show'])
            ->name('api.posts.show');

        $result = $this->app
            ->make(BatchNullSafetyTestGenerationService::class)
            ->generate();

        $this->assertTrue($result['generated']);
        $this->assertSame(
            'ApplicationNullSafetyTest.php',
            $result['fileName']
        );
        $this->assertSame(1, $result['analyzedRoutes']);
        $this->assertSame(4, $result['generatedTests']);
        $this->assertSame(
            4,
            substr_count($result['code'], 'public function test_')
        );
        $this->assertStringContainsString(
            'test_posts_show_does_not_fail_when_post_title_is_null',
            $result['code']
        );
        $this->assertStringNotContainsString(
            'api_posts_show',
            $result['code']
        );
    }
}
