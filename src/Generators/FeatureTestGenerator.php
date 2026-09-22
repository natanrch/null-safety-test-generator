<?php

namespace Natan\NullSafetyTestGenerator\Generators;

class FeatureTestGenerator
{
    public function __construct(
        private FactoryTestGenerator $factoryTestGenerator
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

        $methodName = $this->generateMethodName($scenario, $route);
        $factoryCode = $this->indent($factoryResult['code'], 4);
        $routeCall = $this->generateRouteCall($route);

        $code = implode("\n", [
            'public function ' . $methodName . '(): void',
            '{',
            $factoryCode,
            '',
            '    $response = $this->get(',
            '        ' . $routeCall,
            '    );',
            '',
            '    $this->assertLessThan(500, $response->status());',
            '}',
        ]);

        return [
            'generated' => true,
            'code' => $code,
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

    private function generateMethodName(
        array $scenario,
        array $route
    ): string 
    {
        $routeName = $this->normalizeName($route['name']);
        $root = $this->normalizeName($scenario['root']);
        $path = array_map(
            fn (mixed $segment): string => $this->normalizeName(
                is_string($segment) ? $segment : 'unknown'
            ),
            $scenario['path']
        );

        return 'test_' . $routeName
            . '_does_not_fail_when_'
            . implode('_', [$root, ...$path])
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

    private function generateRouteCall(array $route): string
    {
        $routeName = var_export($route['name'], true);
        $parameters = $route['parameters'] ?? [];

        if ($parameters === []) {
            return 'route(' . $routeName . ')';
        }

        $generatedParameters = [];

        foreach ($parameters as $parameter => $variable) {
            if (! is_string($parameter) || ! is_string($variable)) {
                continue;
            }

            $generatedParameters[] = var_export($parameter, true)
                . ' => $' . $variable;
        }

        return 'route(' . $routeName . ', ['
            . implode(', ', $generatedParameters)
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
