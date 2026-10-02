<?php

namespace Natan\NullSafetyTestGenerator\Generators;

class FeatureTestGenerator
{
    public function __construct(
        private FactoryTestGenerator $factoryTestGenerator,
        private int $missingParameterStatus = 404
    ) 
    {

    }

    public function generate(
        array $scenario,
        array $route
    ): array 
    {
        $validationError = $this->validateInput($scenario, $route);

        if ($validationError !== null) {
            return [
                'generated' => false,
                'message' => $validationError,
            ];
        }

        $factoryResult = $this->factoryTestGenerator->generate($scenario);

        if (($factoryResult['generated'] ?? false) !== true) {
            return $factoryResult;
        }

        $routeFactoryResult = $this->generateRouteParameterFactories(
            $route,
            $scenario['root']
        );

        if (($routeFactoryResult['generated'] ?? false) !== true) {
            return $routeFactoryResult;
        }

        $requestPreconditionResult = $this->generateRequestPreconditions(
            $scenario
        );

        if (($requestPreconditionResult['generated'] ?? false) !== true) {
            return $requestPreconditionResult;
        }

        $methodName = $this->generateMethodName($scenario, $route);
        $factoryBlocks = $routeFactoryResult['code'];
        $factoryBlocks = [
            ...$factoryBlocks,
            ...$this->generateScalarRouteParameters($route),
            ...$requestPreconditionResult['code'],
        ];
        $factoryBlocks[] = $factoryResult['code'];
        $factoryCode = $this->indent(
            implode("\n\n", $factoryBlocks),
            4
        );
        $routeCall = $this->generateRouteCall($route, $scenario);
        $statusAssertions = $this->generateStatusAssertions($scenario);

        $requestMethod = ($scenario['responseType'] ?? null) === 'json'
            ? 'getJson'
            : 'get';
        $code = implode("\n", [
            'public function ' . $methodName . '(): void',
            '{',
            $factoryCode,
            '',
            '    $response = $this->' . $requestMethod . '(',
            '        ' . $routeCall,
            '    );',
            '',
            ...$statusAssertions,
            '}',
        ]);

        return [
            'generated' => true,
            'code' => $code,
        ];
    }

    private function generateStatusAssertions(array $scenario): array
    {
        if (($scenario['strategy'] ?? null) === 'missing_request_parameter') {
            return [
                sprintf(
                    '    $this->assertSame(%d, $response->status());',
                    $this->missingParameterStatus
                ),
            ];
        }

        return [
            '    $this->assertLessThan(500, $response->status());',
            '    $this->assertNotSame(404, $response->status());',
        ];
    }

    private function validateInput(
        array $scenario,
        array $route
    ): ?string 
    {
        if (
            ! isset($scenario['root'], $scenario['path'])
            || ! is_string($scenario['root'])
            || ! is_array($scenario['path'])
        ) {
            return 'The null scenario is invalid; the feature test could not be generated.';
        }

        if (
            ! isset($route['name'], $route['method'])
            || ! is_string($route['name'])
            || ! is_string($route['method'])
            || strtoupper($route['method']) !== 'GET'
        ) {
            return 'The route is invalid or unsupported; the feature test could not be generated.';
        }

        if (
            isset($route['parameters'])
            && ! is_array($route['parameters'])
        ) {
            return 'The route parameters are invalid; the feature test could not be generated.';
        }

        return null;
    }

    private function generateRouteParameterFactories(
        array $route,
        string $scenarioRoot
    ): array {
        $generatedCode = [];

        foreach ($route['parameterModels'] ?? [] as $parameter) {
            $variable = $parameter['variable'] ?? null;
            $modelClass = $parameter['class'] ?? null;

            if (! is_string($variable) || ! is_string($modelClass)) {
                return [
                    'generated' => false,
                    'message' => 'A route parameter model is invalid; the feature test could not be generated.',
                ];
            }

            if ($variable === $scenarioRoot) {
                continue;
            }

            $factory = $this->factoryTestGenerator
                ->generateRouteParameter($variable, $modelClass);

            if (($factory['generated'] ?? false) !== true) {
                return $factory;
            }

            $generatedCode[] = $factory['code'];
        }

        return [
            'generated' => true,
            'code' => $generatedCode,
        ];
    }

    private function generateScalarRouteParameters(array $route): array
    {
        $code = [];

        foreach ($route['parameterValues'] ?? [] as $parameter) {
            $variable = $parameter['variable'] ?? null;

            if (! is_string($variable)) {
                continue;
            }

            $code[] = '$' . $variable . ' = '
                . var_export($parameter['value'] ?? 'test', true) . ';';
        }

        return $code;
    }

    private function generateRequestPreconditions(array $scenario): array
    {
        $code = [];

        foreach ($scenario['requestPreconditions'] ?? [] as $precondition) {
            $root = $precondition['root'] ?? null;
            $modelClass = $precondition['class'] ?? null;

            if (! is_string($root) || ! is_string($modelClass)) {
                continue;
            }

            if (
                $root === ($scenario['root'] ?? null)
                || $this->isOmittedRequestPrecondition($scenario, $precondition)
            ) {
                continue;
            }

            $factory = $this->factoryTestGenerator->generateRouteParameter(
                $root,
                $modelClass
            );

            if (($factory['generated'] ?? false) !== true) {
                return $factory;
            }

            $code[] = $factory['code'];
        }

        return ['generated' => true, 'code' => $code];
    }

    private function isOmittedRequestPrecondition(
        array $scenario,
        array $precondition
    ): bool {
        return ($scenario['strategy'] ?? null) === 'missing_request_parameter'
            && ($scenario['input']['parameter'] ?? null)
                === ($precondition['input']['parameter'] ?? null);
    }

    private function generateMethodName(
        array $scenario,
        array $route
    ): string 
    {
        $routeName = $this->normalizeName($route['name']);
        $routeName .= ($scenario['responseType'] ?? null) === 'json'
            ? '_as_json'
            : '';
        $root = $this->normalizeName($scenario['root']);
        $path = array_map(
            fn (mixed $segment): string => $this->normalizeName(
                is_string($segment) ? $segment : 'unknown'
            ),
            $scenario['path']
        );

        $scenarioDescription = implode('_', [$root, ...$path]);

        if (($scenario['strategy'] ?? null) === 'missing_request_parameter') {
            $parameter = $scenario['input']['parameter'] ?? 'request_parameter';

            return 'test_' . $routeName
                . '_does_not_fail_when_'
                . $this->normalizeName(
                    is_string($parameter) ? $parameter : 'request_parameter'
                )
                . '_is_missing';
        }

        if (($scenario['strategy'] ?? null) === 'empty_root_collection') {
            return 'test_' . $routeName
                . '_does_not_fail_when_'
                . $scenarioDescription
                . '_is_empty';
        }

        return 'test_' . $routeName
            . '_does_not_fail_when_'
            . $scenarioDescription
            . '_is_null';
    }

    private function normalizeName(string $name): string
    {
        $normalized = preg_replace(
            '/[^a-zA-Z0-9]+/',
            '_',
            $name
        );

        return strtolower(trim($normalized ?? $name, '_'));
    }

    private function generateRouteCall(
        array $route,
        array $scenario
    ): string
    {
        $routeName = var_export($route['name'], true);
        $parameters = $route['parameters'] ?? [];

        $generatedParameters = [];

        foreach ($parameters as $parameter => $variable) {
            if (! is_string($parameter) || ! is_string($variable)) {
                continue;
            }

            $generatedParameters[] = var_export($parameter, true)
                . ' => $' . $variable;
        }

        foreach ($scenario['requestPreconditions'] ?? [] as $precondition) {
            $input = $precondition['input'] ?? null;
            $root = $precondition['root'] ?? null;

            if (
                ! is_array($input)
                || ! is_string($root)
                || ($input['source'] ?? null) !== 'request'
                || ($input['valueFrom'] ?? null) !== 'model_key'
                || ! is_string($input['parameter'] ?? null)
                || array_key_exists($input['parameter'], $parameters)
                || $this->isOmittedRequestPrecondition(
                    $scenario,
                    $precondition
                )
            ) {
                continue;
            }

            $generatedParameters[$input['parameter']] = var_export(
                $input['parameter'],
                true
            ) . ' => $' . $root . '->getKey()';
        }

        foreach ($scenario['requestParameters'] ?? [] as $requestParameter) {
            $parameter = $requestParameter['parameter'] ?? null;

            if (
                ! is_string($parameter)
                || array_key_exists($parameter, $parameters)
                || (
                    ($scenario['strategy'] ?? null)
                        === 'missing_request_parameter'
                    && ($scenario['input']['parameter'] ?? null) === $parameter
                )
            ) {
                continue;
            }

            $generatedParameters[$parameter] = var_export($parameter, true)
                . ' => '
                . var_export($requestParameter['value'] ?? 'test', true);
        }

        $input = $scenario['input'] ?? null;

        if (
            is_array($input)
            && ($scenario['strategy'] ?? null) !== 'missing_request_parameter'
            && ($input['source'] ?? null) === 'request'
            && ($input['valueFrom'] ?? null) === 'model_key'
            && is_string($input['parameter'] ?? null)
            && ! array_key_exists($input['parameter'], $parameters)
        ) {
            $generatedParameters[$input['parameter']] = var_export(
                $input['parameter'],
                true
            ) . ' => $' . $scenario['root'] . '->getKey()';
        }

        if ($generatedParameters === []) {
            return 'route(' . $routeName . ')';
        }

        return 'route(' . $routeName . ', ['
            . implode(', ', array_values($generatedParameters))
            . '])';
    }

    private function indent(string $code, int $spaces): string
    {
        $indentation = str_repeat(' ', $spaces);

        return $indentation . str_replace(
            "\n",
            "\n" . $indentation,
            $code
        );
    }
}
