<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Illuminate\Routing\Router;
use Natan\NullSafetyTestGenerator\Services\BatchNullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\ApiPostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class BatchNullSafetyTestGenerationServiceTest extends TestCase
{
    public function test_it_skips_empty_methods_for_every_supported_http_method(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/empty-get', [PostController::class, 'emptyAction'])
            ->name('empty.get');
        $router->post('/empty-post', [PostController::class, 'emptyAction'])
            ->name('empty.post');
        $router->put('/empty-put', [PostController::class, 'emptyAction'])
            ->name('empty.put');
        $router->patch('/empty-patch', [PostController::class, 'emptyAction'])
            ->name('empty.patch');
        $router->delete('/empty-delete', [PostController::class, 'emptyAction'])
            ->name('empty.delete');

        $result = $this->app
            ->make(BatchNullSafetyTestGenerationService::class)
            ->generate();

        $this->assertTrue($result['generated']);
        $this->assertSame(1, $result['analyzedRoutes']);
        $this->assertSame(2, $result['generatedTests']);

        $warnings = implode("\n", $result['warnings']);

        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $this->assertStringContainsString(
                'empty.' . $method
                    . ': The controller method has no executable statements; no test was generated.',
                $warnings
            );
        }

        $this->assertStringNotContainsString(
            'test_empty_',
            $result['code']
        );
    }

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
        $this->assertSame(2, $result['generatedTests']);
        $this->assertSame(
            2,
            substr_count($result['code'], 'public function test_')
        );
        $this->assertStringNotContainsString(
            'test_posts_show_does_not_fail_when_post_title_is_null',
            $result['code']
        );
        $this->assertStringNotContainsString(
            'api_posts_show',
            $result['code']
        );
    }

    public function test_it_adds_write_route_tests_to_the_same_application_file(): void
    {
        $this->app->make(Router::class)
            ->post('/posts/category', [PostController::class, 'storeWithExistingCategory'])
            ->name('posts.category.store');

        $result = $this->app
            ->make(BatchNullSafetyTestGenerationService::class)
            ->generate();

        $this->assertTrue($result['generated']);
        $this->assertSame(2, $result['analyzedRoutes']);
        $this->assertSame(3, $result['generatedTests']);
        $this->assertStringContainsString(
            'test_posts_category_store_does_not_return_a_server_error_for_post_request',
            $result['code']
        );
        $this->assertStringContainsString(
            "::factory()->create(['description' => null]);",
            $result['code']
        );
    }

    public function test_it_adds_delete_route_tests_to_the_application_file(): void
    {
        $this->app->make(Router::class)
            ->delete('/posts/{post}', [PostController::class, 'destroy'])
            ->name('posts.destroy');

        $result = $this->app
            ->make(BatchNullSafetyTestGenerationService::class)
            ->generate();

        $this->assertTrue($result['generated']);
        $this->assertSame(2, $result['analyzedRoutes']);
        $this->assertSame(3, $result['generatedTests']);
        $this->assertStringContainsString(
            'test_posts_destroy_does_not_return_a_server_error_for_delete_request',
            $result['code']
        );
        $this->assertStringContainsString(
            '$response = $this->delete(',
            $result['code']
        );
        $this->assertStringContainsString(
            "['reason' => 'test']",
            $result['code']
        );
    }

    public function test_it_skips_a_write_route_without_factory_and_keeps_valid_tests(): void
    {
        $this->app->make(Router::class)
            ->post('/categories-without-factory', [
                PostController::class,
                'storeWithExistingCategoryWithoutFactory',
            ])
            ->name('categories-without-factory.store');

        $result = $this->app
            ->make(BatchNullSafetyTestGenerationService::class)
            ->generate();

        $this->assertTrue($result['generated']);
        $this->assertSame(1, $result['analyzedRoutes']);
        $this->assertStringContainsString(
            'Factory for model',
            implode("\n", $result['warnings'])
        );
        $this->assertStringContainsString(
            'does not exist; the test could not be generated.',
            implode("\n", $result['warnings'])
        );
    }

    public function test_it_includes_json_get_routes_in_the_application_file(): void
    {
        $this->app->make(Router::class)
            ->get('/api/posts/{post}/profile', [
                JsonPostController::class,
                'jsonResponse',
            ])
            ->name('api.posts.profile');

        $result = $this->app
            ->make(BatchNullSafetyTestGenerationService::class)
            ->generate();

        $this->assertTrue($result['generated']);
        $this->assertSame(2, $result['analyzedRoutes']);
        $this->assertSame(4, $result['generatedTests']);
        $this->assertStringContainsString(
            'test_api_posts_profile_as_json_does_not_fail_when_post_author_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            '$response = $this->getJson(',
            $result['code']
        );
    }
}
