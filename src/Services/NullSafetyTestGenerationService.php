<?php

namespace Natan\NullSafetyTestGenerator\Services;

use Illuminate\Support\Str;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;

class NullSafetyTestGenerationService
{
    public function __construct(
        private ViewAnalysisService $viewAnalysisService,
        private NullScenarioGenerator $nullScenarioGenerator,
        private RouteScanner $routeScanner,
        private FeatureTestGenerator $featureTestGenerator,
        private FeatureTestFileGenerator $featureTestFileGenerator
    ) {
    }

    public function generate(
        string $controllerClass,
        string $controllerMethod
    ): array {
        $methodsResult = $this->generateMethods(
            $controllerClass,
            $controllerMethod
        );

        if (($methodsResult['generated'] ?? false) !== true) {
            return $methodsResult;
        }

        $fileResult = $this->featureTestFileGenerator->generate(
            $this->generateClassName($methodsResult['route']['name']),
            $methodsResult['testMethods']
        );

        if (($methodsResult['warnings'] ?? []) !== []) {
            $fileResult['warnings'] = $methodsResult['warnings'];
        }

        return $fileResult;
    }

    public function generateMethods(
        string $controllerClass,
        string $controllerMethod,
        ?array $route = null
    ): array {
        $viewAnalysis = $this->viewAnalysisService->analyze(
            $controllerClass,
            $controllerMethod
        );

        if ($viewAnalysis === []) {
            return $this->failure(
                'The controller view could not be analyzed; no tests were generated.',
                'no_view'
            );
        }

        $scenarios = $this->nullScenarioGenerator->generate(
            $viewAnalysis
        );

        if ($scenarios === []) {
            return $this->failure(
                'No null scenarios were found; no tests were generated.',
                'no_scenarios'
            );
        }

        $route ??= $this->routeScanner->find(
            $controllerClass,
            $controllerMethod
        );

        if ($route === null || ! is_string($route['name'] ?? null)) {
            return $this->failure(
                'No named route was found; no tests were generated.',
                'no_named_route'
            );
        }

        $testMethods = [];
        $warnings = [];

        foreach ($scenarios as $scenario) {
            $methodResult = $this->featureTestGenerator->generate(
                $scenario,
                $route
            );

            if (($methodResult['generated'] ?? false) !== true) {
                $warnings[] = $methodResult['message']
                    ?? 'A test scenario could not be generated.';

                continue;
            }

            if (! is_string($methodResult['code'] ?? null)) {
                $warnings[] = 'A generated test method is invalid and was skipped.';

                continue;
            }

            $testMethods[] = $methodResult['code'];
        }

        if ($testMethods === []) {
            return [
                'generated' => false,
                'message' => 'No valid test methods were generated.',
                'warnings' => $warnings,
                'reason' => 'no_valid_methods',
            ];
        }

        return [
            'generated' => true,
            'testMethods' => $testMethods,
            'route' => $route,
            'warnings' => $warnings,
        ];
    }

    private function generateClassName(string $routeName): string
    {
        $normalizedRouteName = preg_replace(
            '/[^a-zA-Z0-9]+/',
            ' ',
            $routeName
        );

        return Str::studly($normalizedRouteName ?? $routeName)
            . 'NullSafetyTest';
    }

    private function failure(string $message, ?string $reason = null): array
    {
        $result = [
            'generated' => false,
            'message' => $message,
        ];

        if ($reason !== null) {
            $result['reason'] = $reason;
        }

        return $result;
    }
}
