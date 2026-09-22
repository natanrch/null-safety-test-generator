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
        string $controllerMethod,
        string $viewPath
    ): array {
        $viewAnalysis = $this->viewAnalysisService->analyze(
            $controllerClass,
            $controllerMethod,
            $viewPath
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

        foreach ($scenarios as $scenario) {
            $methodResult = $this->featureTestGenerator->generate(
                $scenario,
                $route
            );

            if (($methodResult['generated'] ?? false) !== true) {
                return $methodResult;
            }

            if (! is_string($methodResult['code'] ?? null)) {
                return $this->failure(
                    'A generated test method is invalid; the test file was not generated.'
                );
            }

            $testMethods[] = $methodResult['code'];
        }

        return $this->featureTestFileGenerator->generate(
            $this->generateClassName($route['name']),
            $testMethods
        );
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
