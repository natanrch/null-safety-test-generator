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
        $viewAnalysis = $this->viewAnalysisService->analyze(
            $controllerClass,
            $controllerMethod
        );

        if ($viewAnalysis === []) {
            return $this->failure(
                'The controller view could not be analyzed; the test file was not generated.'
            );
        }

        $scenarios = $this->nullScenarioGenerator->generate(
            $viewAnalysis
        );

        if ($scenarios === []) {
            return $this->failure(
                'No null scenarios were found; the test file was not generated.'
            );
        }

        $route = $this->routeScanner->find(
            $controllerClass,
            $controllerMethod
        );

        if ($route === null || ! is_string($route['name'] ?? null)) {
            return $this->failure(
                'No named route was found; the test file was not generated.'
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
                'message' => 'No valid test methods were generated; the test file was not generated.',
                'warnings' => $warnings,
            ];
        }

        $fileResult = $this->featureTestFileGenerator->generate(
            $this->generateClassName($route['name']),
            $testMethods
        );

        if ($warnings !== []) {
            $fileResult['warnings'] = $warnings;
        }

        return $fileResult;
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

    private function failure(string $message): array
    {
        return [
            'generated' => false,
            'message' => $message,
        ];
    }
}
