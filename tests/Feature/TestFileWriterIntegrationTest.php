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
use Natan\NullSafetyTestGenerator\Tests\TestCase;
use Natan\NullSafetyTestGenerator\Writers\GeneratedTestFileWriter;

class TestFileWriterIntegrationTest extends TestCase
{
    public function test_it_writes_the_generated_test_file(): void
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

        $generatedFile = $service->generate(
            PostController::class,
            'show'
        );

        $result = (new GeneratedTestFileWriter())->write(
            $generatedFile,
            __DIR__ . '/Generated',
            overwrite: true
        );

        $expectedPath = __DIR__
            . '/Generated/PostsShowNullSafetyTest.php';

        $this->assertSame([
            'written' => true,
            'path' => $expectedPath,
        ], $result);

        $this->assertFileExists($expectedPath);
        $this->assertSame(
            $generatedFile['code'] . "\n",
            file_get_contents($expectedPath)
        );
    }
}
