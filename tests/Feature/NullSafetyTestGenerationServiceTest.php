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
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\ViewAnalysisService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class NullSafetyTestGenerationServiceTest extends TestCase
{
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
            'show',
            __DIR__ . '/../Fixtures/Laravel/views/posts/show.blade.php'
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

        $this->assertStringContainsString(
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

        $this->assertStringContainsString(
            'test_posts_show_does_not_fail_when_post_author_profile_name_is_null',
            $result['code']
        );

        $this->assertSame(
            4,
            substr_count($result['code'], 'public function test_')
        );
    }
}
