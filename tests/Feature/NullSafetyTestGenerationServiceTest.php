<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Illuminate\Routing\Router;
use Natan\NullSafetyTestGenerator\Analyzers\BladeAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerViewAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\FactoryTestGenerator;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Resolvers\ViewPathResolver;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\ViewAnalysisService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\GetServiceController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class NullSafetyTestGenerationServiceTest extends TestCase
{
    public function test_it_skips_an_empty_get_controller_method(): void
    {
        $result = $this->app
            ->make(NullSafetyTestGenerationService::class)
            ->generateMethods(
                PostController::class,
                'emptyAction',
                [
                    'name' => 'empty.get',
                    'method' => 'GET',
                    'parameters' => [],
                    'parameterModels' => [],
                ]
            );

        $this->assertFalse($result['generated']);
        $this->assertSame('empty_method', $result['reason']);
        $this->assertSame(
            'The controller method has no executable statements; no test was generated.',
            $result['message']
        );
    }

    public function test_it_orchestrates_the_complete_test_generation_flow(): void
    {
        $factoryTestGenerator = new FactoryTestGenerator();

        $service = new NullSafetyTestGenerationService(
            new ViewAnalysisService(
                new ControllerViewAnalyzer(
                    new ControllerMethodAnalyzer()
                ),
                new BladeAnalyzer(),
                new EloquentAccessChainResolver(
                    new EloquentRelationshipResolver()
                ),
                new ViewPathResolver(
                    $this->app->make('view.finder')
                )
            ),
            new NullScenarioGenerator(),
            new RouteScanner(
                $this->app->make(Router::class)
            ),
            new FeatureTestGenerator($factoryTestGenerator),
            new FeatureTestFileGenerator()
        );

        $result = $service->generate(
            PostController::class,
            'show'
        );

        $this->assertTrue($result['generated']);
        $this->assertSame(
            'PostsShowNullSafetyTest.php',
            $result['fileName']
        );

        $this->assertStringContainsString(
            'class PostsShowNullSafetyTest extends TestCase',
            $result['code']
        );

        $this->assertStringNotContainsString(
            'test_posts_show_does_not_fail_when_post_title_is_null',
            $result['code']
        );

        $this->assertStringContainsString(
            'test_posts_show_does_not_fail_when_post_author_is_null',
            $result['code']
        );

        $this->assertStringContainsString(
            'test_posts_show_does_not_fail_when_post_author_profile_is_null',
            $result['code']
        );

        $this->assertStringNotContainsString(
            'test_posts_show_does_not_fail_when_post_author_profile_name_is_null',
            $result['code']
        );

        $this->assertSame(
            2,
            substr_count($result['code'], 'public function test_')
        );
    }

    public function test_it_generates_get_json_tests_for_a_json_controller(): void
    {
        $this->app->make(Router::class)
            ->get('/api/posts/{post}/profile', [
                JsonPostController::class,
                'jsonResponse',
            ])
            ->name('api.posts.profile');

        $result = $this->app
            ->make(NullSafetyTestGenerationService::class)
            ->generate(JsonPostController::class, 'jsonResponse');

        $this->assertTrue($result['generated']);
        $this->assertSame(2, substr_count(
            $result['code'],
            'public function test_'
        ));
        $this->assertStringContainsString(
            '$response = $this->getJson(',
            $result['code']
        );
        $this->assertStringContainsString(
            'when_post_author_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            'when_post_author_profile_is_null',
            $result['code']
        );
    }

    public function test_it_generates_view_and_json_scenarios_for_a_mixed_route(): void
    {
        $this->app->make(Router::class)
            ->get('/posts/{post}/mixed', [
                JsonPostController::class,
                'mixed',
            ])
            ->name('posts.mixed');

        $result = $this->app
            ->make(NullSafetyTestGenerationService::class)
            ->generate(JsonPostController::class, 'mixed');

        $this->assertTrue($result['generated']);
        $this->assertSame(4, substr_count(
            $result['code'],
            'public function test_'
        ));
        $this->assertStringContainsString(
            'test_posts_mixed_does_not_fail_when_post_author_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            'test_posts_mixed_as_json_does_not_fail_when_post_author_is_null',
            $result['code']
        );
        $this->assertStringContainsString('$this->get(', $result['code']);
        $this->assertStringContainsString('$this->getJson(', $result['code']);
    }

    public function test_it_generates_get_tests_for_accesses_found_inside_a_helper(): void
    {
        $this->app->make(Router::class)
            ->get('/posts/{post}/helper-view', [
                GetServiceController::class,
                'viewResponse',
            ])
            ->name('posts.helper-view');

        $result = $this->app
            ->make(NullSafetyTestGenerationService::class)
            ->generate(GetServiceController::class, 'viewResponse');

        $this->assertTrue($result['generated']);
        $this->assertSame(3, substr_count(
            $result['code'],
            'public function test_'
        ));
        $this->assertStringContainsString(
            'when_post_author_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            'when_post_author_profile_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            'when_post_author_profile_name_is_null',
            $result['code']
        );
        $this->assertStringContainsString('$this->get(', $result['code']);
    }

    public function test_it_generates_get_json_tests_for_accesses_found_inside_a_helper(): void
    {
        $this->app->make(Router::class)
            ->get('/api/posts/{post}/helper-json', [
                GetServiceController::class,
                'jsonResponse',
            ])
            ->name('api.posts.helper-json');

        $result = $this->app
            ->make(NullSafetyTestGenerationService::class)
            ->generate(GetServiceController::class, 'jsonResponse');

        $this->assertTrue($result['generated']);
        $this->assertSame(3, substr_count(
            $result['code'],
            'public function test_'
        ));
        $this->assertStringContainsString(
            'test_api_posts_helper_json_as_json_does_not_fail_when_post_author_is_null',
            $result['code']
        );
        $this->assertStringContainsString(
            '$response = $this->getJson(',
            $result['code']
        );
    }
}
