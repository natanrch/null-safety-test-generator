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

    public function generate(): array
    {
        $testMethods = [];
        $warnings = [];
        $analyzedRoutes = 0;

        foreach ($this->routeScanner->allGetControllerRoutes() as $route) {
            $controller = $route['controller'] ?? null;
            $controllerMethod = $route['controllerMethod'] ?? null;

            if (! is_string($controller) || ! is_string($controllerMethod)) {
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

                continue;
            }

            if (($result['reason'] ?? null) === 'no_view') {
                continue;
            }

            $routeLabel = $this->routeLabel($route);

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
}
