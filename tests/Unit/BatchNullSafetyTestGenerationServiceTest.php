<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;
use Natan\NullSafetyTestGenerator\Services\BatchNullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\WriteTestGenerationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BatchNullSafetyTestGenerationServiceTest extends TestCase
{
    public function test_it_sends_delete_routes_to_the_write_generator(): void
    {
        $routeScanner = $this->createMock(RouteScanner::class);
        $getGenerator = $this->createMock(
            NullSafetyTestGenerationService::class
        );
        $fileGenerator = $this->createMock(
            FeatureTestFileGenerator::class
        );
        $writeGenerator = $this->createMock(
            WriteTestGenerationService::class
        );
        $route = [
            'name' => 'posts.destroy',
            'method' => 'DELETE',
            'parameters' => ['post' => 'post'],
            'controller' => 'PostController',
            'controllerMethod' => 'destroy',
        ];
        $testMethod = 'public function test_delete(): void {}';

        $routeScanner->method('allGetControllerRoutes')->willReturn([]);
        $routeScanner->method('allWriteControllerRoutes')->willReturn([$route]);
        $getGenerator->expects($this->never())->method('generateMethods');
        $writeGenerator->expects($this->once())
            ->method('generateMethods')
            ->with('PostController', 'destroy', $route)
            ->willReturn([
                'generated' => true,
                'testMethods' => [$testMethod],
                'route' => $route,
                'warnings' => [],
            ]);
        $fileGenerator->expects($this->once())
            ->method('generate')
            ->with('ApplicationNullSafetyTest', [$testMethod])
            ->willReturn([
                'generated' => true,
                'fileName' => 'ApplicationNullSafetyTest.php',
                'code' => '<?php // generated',
            ]);

        $result = (new BatchNullSafetyTestGenerationService(
            $routeScanner,
            $getGenerator,
            $fileGenerator,
            $writeGenerator
        ))->generate();

        $this->assertTrue($result['generated']);
        $this->assertSame(1, $result['analyzedRoutes']);
        $this->assertSame(1, $result['generatedTests']);
    }

    public function test_it_continues_when_one_route_cannot_be_analyzed(): void
    {
        $routeScanner = $this->createMock(RouteScanner::class);
        $generator = $this->createMock(
            NullSafetyTestGenerationService::class
        );
        $fileGenerator = $this->createMock(
            FeatureTestFileGenerator::class
        );
        $writeGenerator = $this->createMock(
            WriteTestGenerationService::class
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

        $events = [];
        $result = (new BatchNullSafetyTestGenerationService(
            $routeScanner,
            $generator,
            $fileGenerator,
            $writeGenerator
        ))->generate(
            static function (array $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $this->assertTrue($result['generated']);
        $this->assertSame(1, $result['analyzedRoutes']);
        $this->assertSame([
            'broken.show: The route could not be analyzed: Invalid controller source.',
        ], $result['warnings']);
        $this->assertSame([
            [
                'status' => 'analyzing',
                'current' => 1,
                'total' => 2,
                'route' => 'broken.show',
            ],
            [
                'status' => 'failed',
                'current' => 1,
                'total' => 2,
                'route' => 'broken.show',
                'message' => 'Invalid controller source.',
            ],
            [
                'status' => 'analyzing',
                'current' => 2,
                'total' => 2,
                'route' => 'posts.show',
            ],
            [
                'status' => 'generated',
                'current' => 2,
                'total' => 2,
                'route' => 'posts.show',
                'tests' => 1,
            ],
        ], $events);
    }
}
