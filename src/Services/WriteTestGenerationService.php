<?php

namespace Natan\NullSafetyTestGenerator\Services;

use Illuminate\Support\Str;
use Natan\NullSafetyTestGenerator\Analyzers\WriteControllerAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Generators\WriteFeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;

class WriteTestGenerationService
{
    public function __construct(
        private WriteControllerAnalyzer $controllerAnalyzer,
        private RouteScanner $routeScanner,
        private WriteFeatureTestGenerator $testGenerator,
        private FeatureTestFileGenerator $fileGenerator
    ) {
    }

    public function generate(
        string $controllerClass,
        string $controllerMethod,
        ?array $route = null
    ): array {
        $methodResult = $this->generateMethod(
            $controllerClass,
            $controllerMethod,
            $route
        );

        if (($methodResult['generated'] ?? false) !== true) {
            return $methodResult;
        }

        return $this->fileGenerator->generate(
            $this->className($methodResult['route']['name']),
            [$methodResult['code']]
        );
    }

    public function generateMethod(
        string $controllerClass,
        string $controllerMethod,
        ?array $route = null
    ): array {
        $analysis = $this->controllerAnalyzer->analyze(
            $controllerClass,
            $controllerMethod
        );

        if ($analysis === []) {
            return [
                'generated' => false,
                'message' => 'The write controller method could not be analyzed.',
            ];
        }

        $route ??= $this->routeScanner->find(
            $controllerClass,
            $controllerMethod
        );

        if ($route === null) {
            return [
                'generated' => false,
                'message' => 'No named write route was found.',
            ];
        }

        $method = $this->testGenerator->generate($analysis, $route);

        if (($method['generated'] ?? false) !== true) {
            return $method;
        }

        return [
            'generated' => true,
            'code' => $method['code'],
            'route' => $route,
        ];
    }

    private function className(string $routeName): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $routeName);

        return Str::studly($normalized ?? $routeName) . 'WriteSafetyTest';
    }
}
