<?php

namespace Natan\NullSafetyTestGenerator\Services;

use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;
use Throwable;

class BatchNullSafetyTestGenerationService
{
    public function __construct(
        private RouteScanner $routeScanner,
        private NullSafetyTestGenerationService $testGenerationService,
        private FeatureTestFileGenerator $fileGenerator
    ) {
    }

    public function generate(?callable $progress = null): array
    {
        $testMethods = [];
        $warnings = [];
        $analyzedRoutes = 0;
        $routes = $this->routeScanner->allGetControllerRoutes();
        $totalRoutes = count($routes);

        foreach ($routes as $index => $route) {
            $currentRoute = $index + 1;
            $routeLabel = $this->routeLabel($route);

            $this->reportProgress($progress, [
                'status' => 'analyzing',
                'current' => $currentRoute,
                'total' => $totalRoutes,
                'route' => $routeLabel,
            ]);

            $controller = $route['controller'] ?? null;
            $controllerMethod = $route['controllerMethod'] ?? null;

            if (! is_string($controller) || ! is_string($controllerMethod)) {
                $this->reportProgress($progress, [
                    'status' => 'skipped',
                    'current' => $currentRoute,
                    'total' => $totalRoutes,
                    'route' => $routeLabel,
                    'message' => 'invalid controller action',
                ]);

                continue;
            }

            try {
                $result = $this->testGenerationService->generateMethods(
                    $controller,
                    $controllerMethod,
                    $route
                );
            } catch (Throwable $exception) {
                $warnings[] = sprintf(
                    '%s: The route could not be analyzed: %s',
                    $this->routeLabel($route),
                    $exception->getMessage()
                );

                $this->reportProgress($progress, [
                    'status' => 'failed',
                    'current' => $currentRoute,
                    'total' => $totalRoutes,
                    'route' => $routeLabel,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            if (($result['reason'] ?? null) === 'no_view') {
                $this->reportProgress($progress, [
                    'status' => 'skipped',
                    'current' => $currentRoute,
                    'total' => $totalRoutes,
                    'route' => $routeLabel,
                    'message' => 'no analyzable view',
                ]);

                continue;
            }

            if (($result['generated'] ?? false) !== true) {
                $warnings[] = sprintf(
                    '%s: %s',
                    $routeLabel,
                    $result['message'] ?? 'The route could not be analyzed.'
                );

                foreach ($result['warnings'] ?? [] as $warning) {
                    if (is_string($warning)) {
                        $warnings[] = $routeLabel . ': ' . $warning;
                    }
                }

                $this->reportProgress($progress, [
                    'status' => 'failed',
                    'current' => $currentRoute,
                    'total' => $totalRoutes,
                    'route' => $routeLabel,
                    'message' => $result['message']
                        ?? 'no valid tests generated',
                ]);

                continue;
            }

            $analyzedRoutes++;
            $testMethods = [
                ...$testMethods,
                ...$result['testMethods'],
            ];

            foreach ($result['warnings'] ?? [] as $warning) {
                if (is_string($warning)) {
                    $warnings[] = $routeLabel . ': ' . $warning;
                }
            }

            $this->reportProgress($progress, [
                'status' => 'generated',
                'current' => $currentRoute,
                'total' => $totalRoutes,
                'route' => $routeLabel,
                'tests' => count($result['testMethods']),
            ]);
        }

        if ($testMethods === []) {
            return [
                'generated' => false,
                'message' => 'No analyzable controller views produced valid tests.',
                'warnings' => $warnings,
            ];
        }

        $file = $this->fileGenerator->generate(
            'ApplicationNullSafetyTest',
            $testMethods
        );

        $file['warnings'] = $warnings;
        $file['analyzedRoutes'] = $analyzedRoutes;
        $file['generatedTests'] = count(array_unique($testMethods));

        return $file;
    }

    private function routeLabel(array $route): string
    {
        if (is_string($route['name'] ?? null)) {
            return $route['name'];
        }

        return ($route['controller'] ?? 'UnknownController')
            . '@'
            . ($route['controllerMethod'] ?? 'unknownMethod');
    }

    private function reportProgress(
        ?callable $progress,
        array $event
    ): void {
        if ($progress !== null) {
            $progress($event);
        }
    }
}
