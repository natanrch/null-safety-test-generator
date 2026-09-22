<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\ViewAnalysisService;
use PHPUnit\Framework\TestCase;

class NullSafetyTestGenerationServiceTest extends TestCase
{
    public function test_it_generates_valid_tests_and_reports_skipped_scenarios(): void
    {
        $viewAnalysisService = $this->createMock(
            ViewAnalysisService::class
        );
        $scenarioGenerator = $this->createMock(
            NullScenarioGenerator::class
        );
        $routeScanner = $this->createMock(RouteScanner::class);
        $featureTestGenerator = $this->createMock(
            FeatureTestGenerator::class
        );
        $fileGenerator = $this->createMock(
            FeatureTestFileGenerator::class
        );

        $viewAnalysisService->method('analyze')->willReturn([
            'view' => 'posts.show',
            'accesses' => [],
        ]);

        $scenarios = [
            ['id' => 'valid'],
            ['id' => 'missing-factory'],
            ['id' => 'invalid-class'],
        ];

        $scenarioGenerator->method('generate')->willReturn($scenarios);
        $routeScanner->method('find')->willReturn([
            'name' => 'posts.show',
            'method' => 'GET',
            'parameters' => ['post' => 'post'],
        ]);

        $featureTestGenerator
            ->expects($this->exactly(3))
            ->method('generate')
            ->willReturnOnConsecutiveCalls(
                [
                    'generated' => true,
                    'code' => 'public function test_valid(): void {}',
                ],
                [
                    'generated' => false,
                    'message' => 'Factory for model MissingFactory does not exist; the test could not be generated.',
                ],
                [
                    'generated' => false,
                    'message' => 'The model class InvalidModel is invalid; the test could not be generated.',
                ]
            );

        $fileGenerator
            ->expects($this->once())
            ->method('generate')
            ->with(
                'PostsShowNullSafetyTest',
                ['public function test_valid(): void {}']
            )
            ->willReturn([
                'generated' => true,
                'fileName' => 'PostsShowNullSafetyTest.php',
                'code' => '<?php // generated',
            ]);

        $service = new NullSafetyTestGenerationService(
            $viewAnalysisService,
            $scenarioGenerator,
            $routeScanner,
            $featureTestGenerator,
            $fileGenerator
        );

        $result = $service->generate('PostController', 'show');

        $this->assertSame([
            'generated' => true,
            'fileName' => 'PostsShowNullSafetyTest.php',
            'code' => '<?php // generated',
            'warnings' => [
                'Factory for model MissingFactory does not exist; the test could not be generated.',
                'The model class InvalidModel is invalid; the test could not be generated.',
            ],
        ], $result);
    }
}
