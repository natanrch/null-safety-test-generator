<?php

namespace Natan\NullSafetyTestGenerator\Services;

use Illuminate\Support\Str;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerMethodAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\JsonResponseAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ModelPropagationAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\NullableRootObjectAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Inspectors\ControllerMethodExecutionInspector;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;

class NullSafetyTestGenerationService
{
    public function __construct(
        private ViewAnalysisService $viewAnalysisService,
        private NullScenarioGenerator $nullScenarioGenerator,
        private RouteScanner $routeScanner,
        private FeatureTestGenerator $featureTestGenerator,
        private FeatureTestFileGenerator $featureTestFileGenerator,
        private ?JsonResponseAnalyzer $jsonResponseAnalyzer = null,
        private ?ModelPropagationAnalyzer $modelPropagationAnalyzer = null,
        private ?ControllerMethodExecutionInspector $methodExecutionInspector = null,
        private ?NullableRootObjectAnalyzer $nullableRootObjectAnalyzer = null
    ) {
        $this->jsonResponseAnalyzer ??= new JsonResponseAnalyzer(
            new ControllerMethodAnalyzer(),
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        );
        $this->modelPropagationAnalyzer ??= new ModelPropagationAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        );
        $this->methodExecutionInspector ??=
            new ControllerMethodExecutionInspector();
        $this->nullableRootObjectAnalyzer ??=
            new NullableRootObjectAnalyzer();
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
        if ($this->methodExecutionInspector->hasExecutableStatements(
            $controllerClass,
            $controllerMethod
        ) === false) {
            return $this->failure(
                ControllerMethodExecutionInspector::EMPTY_METHOD_MESSAGE,
                'empty_method'
            );
        }

        $viewAnalysis = $this->viewAnalysisService->analyze(
            $controllerClass,
            $controllerMethod
        );
        $jsonAnalysis = $this->jsonResponseAnalyzer->analyze(
            $controllerClass,
            $controllerMethod
        );
        $propagatedAccesses = $this->modelPropagationAnalyzer->analyze(
            $controllerClass,
            $controllerMethod
        );
        $nullableRootAccesses = $this->nullableRootObjectAnalyzer->analyze(
            $controllerClass,
            $controllerMethod
        );
        $additionalAccesses = $this->mergeAccesses(
            $propagatedAccesses,
            $nullableRootAccesses
        );

        if ($additionalAccesses !== []) {
            if ($viewAnalysis !== []) {
                $viewAnalysis['accesses'] = $this->mergeAccesses(
                    $viewAnalysis['accesses'] ?? [],
                    $additionalAccesses
                );
            }

            if ($jsonAnalysis !== []) {
                $jsonAnalysis['accesses'] = $this->mergeAccesses(
                    $jsonAnalysis['accesses'] ?? [],
                    $additionalAccesses
                );
            }
        }

        if ($viewAnalysis === [] && $jsonAnalysis === []) {
            return $this->failure(
                'The controller response could not be analyzed; no tests were generated.',
                'no_view'
            );
        }

        $scenarios = [];

        foreach ([$viewAnalysis, $jsonAnalysis] as $analysis) {
            if ($analysis === []) {
                continue;
            }

            $analysisScenarios = $this->nullScenarioGenerator->generate(
                $analysis
            );

            if (($analysis['responseType'] ?? null) === 'json') {
                $analysisScenarios = array_map(
                    static function (array $scenario): array {
                        $scenario['responseType'] = 'json';

                        return $scenario;
                    },
                    $analysisScenarios
                );
            }

            $scenarios = [...$scenarios, ...$analysisScenarios];
        }

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

    private function mergeAccesses(array ...$groups): array
    {
        $merged = [];

        foreach ($groups as $accesses) {
            foreach ($accesses as $access) {
                if (! is_array($access)) {
                    continue;
                }

                $key = serialize([
                    $access['root'] ?? null,
                    $access['class'] ?? null,
                    $access['type'] ?? null,
                    $access['accesses'] ?? [],
                    $access['usage'] ?? null,
                ]);
                $merged[$key] = $access;
            }
        }

        return array_values($merged);
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
