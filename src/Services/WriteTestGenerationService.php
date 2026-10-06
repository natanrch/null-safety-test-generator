<?php

namespace Natan\NullSafetyTestGenerator\Services;

use Illuminate\Support\Str;
use Natan\NullSafetyTestGenerator\Analyzers\WriteControllerAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Generators\WriteFeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Inspectors\ControllerMethodExecutionInspector;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;

class WriteTestGenerationService
{
    public function __construct(
        private WriteControllerAnalyzer $controllerAnalyzer,
        private RouteScanner $routeScanner,
        private WriteFeatureTestGenerator $testGenerator,
        private FeatureTestFileGenerator $fileGenerator,
        private ?ControllerMethodExecutionInspector $methodExecutionInspector = null
    ) {
        $this->methodExecutionInspector ??=
            new ControllerMethodExecutionInspector();
    }

    public function generate(
        string $controllerClass,
        string $controllerMethod,
        ?array $route = null
    ): array {
        $methodsResult = $this->generateMethods(
            $controllerClass,
            $controllerMethod,
            $route
        );

        if (($methodsResult['generated'] ?? false) !== true) {
            return $methodsResult;
        }

        $file = $this->fileGenerator->generate(
            $this->className($methodsResult['route']['name']),
            $methodsResult['testMethods']
        );

        if (($methodsResult['warnings'] ?? []) !== []) {
            $file['warnings'] = $methodsResult['warnings'];
        }

        return $file;
    }

    public function generateMethod(
        string $controllerClass,
        string $controllerMethod,
        ?array $route = null
    ): array {
        $result = $this->generateMethods(
            $controllerClass,
            $controllerMethod,
            $route
        );

        if (($result['generated'] ?? false) !== true) {
            return $result;
        }

        return [
            'generated' => true,
            'code' => $result['testMethods'][0],
            'route' => $result['route'],
            'warnings' => $result['warnings'],
        ];
    }

    public function generateMethods(
        string $controllerClass,
        string $controllerMethod,
        ?array $route = null
    ): array {
        if ($this->methodExecutionInspector->hasExecutableStatements(
            $controllerClass,
            $controllerMethod
        ) === false) {
            return [
                'generated' => false,
                'message' => ControllerMethodExecutionInspector::EMPTY_METHOD_MESSAGE,
                'reason' => 'empty_method',
            ];
        }

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

        $testMethods = [];
        $warnings = [];

        foreach ($this->testGenerator->generateAll($analysis, $route) as $method) {
            if (($method['generated'] ?? false) !== true) {
                $warnings[] = $method['message']
                    ?? 'A write test scenario could not be generated.';
                continue;
            }

            $testMethods[] = $method['code'];
        }

        if ($testMethods === []) {
            return [
                'generated' => false,
                'message' => $warnings[0]
                    ?? 'No valid write test methods were generated.',
                'warnings' => $warnings,
            ];
        }

        return [
            'generated' => true,
            'testMethods' => array_values(array_unique($testMethods)),
            'route' => $route,
            'warnings' => $warnings,
        ];
    }

    private function className(string $routeName): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $routeName);

        return Str::studly($normalized ?? $routeName) . 'WriteSafetyTest';
    }
}
