<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Illuminate\Routing\Router;
use Natan\NullSafetyTestGenerator\Services\WriteTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PromotedServiceController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class WriteTestGenerationServiceTest extends TestCase
{
    public function test_it_generates_a_complete_put_feature_test_file(): void
    {
        $this->app->make(Router::class)
            ->put('/posts/{post}', [PostController::class, 'update'])
            ->name('posts.update');

        $result = $this->app
            ->make(WriteTestGenerationService::class)
            ->generate(PostController::class, 'update');

        $this->assertTrue($result['generated']);
        $this->assertSame(
            'PostsUpdateWriteSafetyTest.php',
            $result['fileName']
        );
        $this->assertStringContainsString(
            'class PostsUpdateWriteSafetyTest extends TestCase',
            $result['code']
        );
        $this->assertStringContainsString(
            '$response = $this->put(',
            $result['code']
        );
        $this->assertStringContainsString(
            '$this->assertLessThan(500, $response->status());',
            $result['code']
        );
    }

    public function test_it_generates_a_post_file_with_validated_payload(): void
    {
        $this->app->make(Router::class)
            ->post('/posts', [PostController::class, 'storeInline'])
            ->name('posts.store');

        $result = $this->app
            ->make(WriteTestGenerationService::class)
            ->generate(PostController::class, 'storeInline');

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            "['title' => 'test', 'active' => true, "
                . "'status' => 'draft', 'category_id' => \$category->id, 'tags' => []]",
            $result['code']
        );
    }

    public function test_it_generates_a_delete_file_with_model_binding_and_validated_payload(): void
    {
        $this->app->make(Router::class)
            ->delete('/posts/{post}', [PostController::class, 'destroy'])
            ->name('posts.destroy');

        $result = $this->app
            ->make(WriteTestGenerationService::class)
            ->generate(PostController::class, 'destroy');

        $this->assertTrue($result['generated']);
        $this->assertSame(
            'PostsDestroyWriteSafetyTest.php',
            $result['fileName']
        );
        $this->assertStringContainsString(
            '$post = \\' . Post::class . '::factory()->create();',
            $result['code']
        );
        $this->assertStringContainsString(
            '$response = $this->delete(',
            $result['code']
        );
        $this->assertStringContainsString(
            "route('posts.destroy', ['post' => \$post])",
            $result['code']
        );
        $this->assertStringContainsString(
            "['reason' => 'test']",
            $result['code']
        );
    }

    public function test_it_generates_null_scenarios_for_a_delete_relationship_chain(): void
    {
        $this->app->make(Router::class)
            ->delete('/posts/{post}/with-author', [
                PostController::class,
                'destroyWithAuthor',
            ])
            ->name('posts.destroy-with-author');

        $result = $this->app
            ->make(WriteTestGenerationService::class)
            ->generate(PostController::class, 'destroyWithAuthor');

        $this->assertTrue($result['generated']);
        $this->assertSame(3, substr_count(
            $result['code'],
            'public function test_'
        ));
        $this->assertStringContainsString(
            'test_posts_destroy_with_author_does_not_fail_when_post_author_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            'test_posts_destroy_with_author_does_not_fail_when_post_author_name_is_null',
            $result['code']
        );
        $this->assertSame(3, substr_count(
            $result['code'],
            '$response = $this->delete('
        ));
    }

    public function test_it_creates_the_exists_model_and_nulls_only_accessed_nullable_columns(): void
    {
        $this->app->make(Router::class)
            ->post('/posts/category', [PostController::class, 'storeWithExistingCategory'])
            ->name('posts.category.store');

        $result = $this->app
            ->make(WriteTestGenerationService::class)
            ->generate(PostController::class, 'storeWithExistingCategory');

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            "::factory()->create(['description' => null]);",
            $result['code']
        );
        $this->assertStringContainsString(
            "['category_id' => \$category->id]",
            $result['code']
        );
        $this->assertStringNotContainsString("'name' => null", $result['code']);
    }

    public function test_it_reports_that_the_exists_model_factory_does_not_exist(): void
    {
        $this->app->make(Router::class)
            ->post('/posts/category-without-factory', [
                PostController::class,
                'storeWithExistingCategoryWithoutFactory',
            ])
            ->name('posts.category-without-factory.store');

        $result = $this->app
            ->make(WriteTestGenerationService::class)
            ->generate(
                PostController::class,
                'storeWithExistingCategoryWithoutFactory'
            );

        $this->assertFalse($result['generated']);
        $this->assertStringContainsString(
            'does not exist; the test could not be generated.',
            $result['message']
        );
    }

    public function test_it_generates_each_null_scenario_in_a_write_relationship_chain(): void
    {
        $this->app->make(Router::class)
            ->post('/posts/{post}/process-profile', [
                PostController::class,
                'processAuthorProfile',
            ])
            ->name('posts.process-profile');

        $result = $this->app
            ->make(WriteTestGenerationService::class)
            ->generate(PostController::class, 'processAuthorProfile');

        $this->assertTrue($result['generated']);
        $this->assertSame(4, substr_count(
            $result['code'],
            'public function test_'
        ));
        $this->assertStringContainsString(
            'test_posts_process_profile_does_not_fail_when_post_author_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            'test_posts_process_profile_does_not_fail_when_post_author_profile_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            'test_posts_process_profile_does_not_fail_when_post_author_profile_name_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            "::factory()->state(['author_id' => null])->create();",
            $result['code']
        );
        $this->assertStringContainsString(
            "::factory()->state(['name' => null])",
            $result['code']
        );
    }

    public function test_it_generates_null_scenarios_found_inside_a_service(): void
    {
        $this->app->make(Router::class)
            ->post('/posts/{post}/service-process', [
                PromotedServiceController::class,
                'process',
            ])
            ->name('posts.service-process');

        $result = $this->app
            ->make(WriteTestGenerationService::class)
            ->generate(PromotedServiceController::class, 'process');

        $this->assertTrue($result['generated']);
        $this->assertSame(4, substr_count(
            $result['code'],
            'public function test_'
        ));
        $this->assertStringContainsString(
            'test_posts_service_process_does_not_fail_when_post_author_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            'test_posts_service_process_does_not_fail_when_post_author_profile_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            'test_posts_service_process_does_not_fail_when_post_author_profile_name_is_null',
            $result['code']
        );
    }

    public function test_it_does_not_generate_a_null_primary_key_scenario(): void
    {
        $this->app->make(Router::class)
            ->post('/posts/{post}/process-id', [
                PostController::class,
                'processId',
            ])
            ->name('posts.process-id');

        $result = $this->app
            ->make(WriteTestGenerationService::class)
            ->generate(PostController::class, 'processId');

        $this->assertTrue($result['generated']);
        $this->assertSame(1, substr_count(
            $result['code'],
            'public function test_'
        ));
        $this->assertStringNotContainsString(
            "'id' => null",
            $result['code']
        );
    }

    public function test_it_generates_the_missing_relationship_but_not_its_terminal_id(): void
    {
        $this->app->make(Router::class)
            ->post('/posts/{post}/process-author-id', [
                PostController::class,
                'processAuthorId',
            ])
            ->name('posts.process-author-id');

        $result = $this->app
            ->make(WriteTestGenerationService::class)
            ->generate(PostController::class, 'processAuthorId');

        $this->assertTrue($result['generated']);
        $this->assertSame(2, substr_count(
            $result['code'],
            'public function test_'
        ));
        $this->assertStringContainsString(
            "['author_id' => null]",
            $result['code']
        );
        $this->assertStringNotContainsString(
            "['id' => null]",
            $result['code']
        );
        $this->assertStringNotContainsString(
            '// This route does not require route parameters.',
            $this->scenarioMethod(
                $result['code'],
                'test_posts_process_author_id_does_not_fail_when_post_author_is_null'
            )
        );
    }

    private function scenarioMethod(string $code, string $method): string
    {
        $start = strpos($code, 'public function ' . $method);

        if ($start === false) {
            return '';
        }

        $next = strpos($code, 'public function ', $start + 1);

        return $next === false
            ? substr($code, $start)
            : substr($code, $start, $next - $start);
    }
}
