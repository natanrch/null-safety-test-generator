<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;
use Natan\NullSafetyTestGenerator\Services\BatchNullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BatchNullSafetyTestGenerationServiceTest extends TestCase
{
    public function test_it_continues_when_one_route_cannot_be_analyzed(): void
    {
        $routeScanner = $this->createMock(RouteScanner::class);
        $generator = $this->createMock(
            NullSafetyTestGenerationService::class
        );
        $fileGenerator = $this->createMock(
            FeatureTestFileGenerator::class
        );

        $invalidRoute = [
            'name' => 'broken.show',
            'method' => 'GET',
            'parameters' => [],
            'controller' => 'BrokenController',
            'controllerMethod' => 'show',
        ];
        $validRoute = [
            'name' => 'posts.show',
            'method' => 'GET',
            'parameters' => [],
            'controller' => 'PostController',
            'controllerMethod' => 'show',
        ];

        $routeScanner->method('allGetControllerRoutes')
            ->willReturn([$invalidRoute, $validRoute]);

        $generator->expects($this->exactly(2))
            ->method('generateMethods')
            ->willReturnCallback(
                static function (string $controller) use ($validRoute): array {
                    if ($controller === 'BrokenController') {
                        throw new RuntimeException('Invalid controller source.');
                    }

                    return [
                        'generated' => true,
                        'testMethods' => [
                            'public function test_valid(): void {}',
                        ],
                        'route' => $validRoute,
                        'warnings' => [],
                    ];
                }
            );

        $fileGenerator->expects($this->once())
            ->method('generate')
            ->with(
                'ApplicationNullSafetyTest',
                ['public function test_valid(): void {}']
            )
            ->willReturn([
                'generated' => true,
                'fileName' => 'ApplicationNullSafetyTest.php',
                'code' => '<?php // generated',
            ]);

        $result = (new BatchNullSafetyTestGenerationService(
            $routeScanner,
            $generator,
            $fileGenerator
        ))->generate();

        $this->assertTrue($result['generated']);
        $this->assertSame(1, $result['analyzedRoutes']);
        $this->assertSame([
            'broken.show: The route could not be analyzed: Invalid controller source.',
        ], $result['warnings']);
    }
}
