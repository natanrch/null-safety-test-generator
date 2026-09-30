<?php

namespace Natan\NullSafetyTestGenerator\Generators;

class WriteFeatureTestGenerator
{
    private const SUPPORTED_METHODS = ['POST', 'PUT', 'PATCH'];

    public function __construct(
        private FactoryTestGenerator $factoryTestGenerator
    ) {
    }

    public function generate(array $analysis, array $route): array
    {
        $error = $this->validate($analysis, $route);

        if ($error !== null) {
            return ['generated' => false, 'message' => $error];
        }

        $setup = $this->generateRouteSetup($route);

        if (($setup['generated'] ?? false) !== true) {
            return $setup;
        }

        $dependencySetup = $this->generateDependencySetup(
            $analysis['dependencies'] ?? []
        );

        if (($dependencySetup['generated'] ?? false) !== true) {
            return $dependencySetup;
        }

        $setup['code'] = [
            ...$setup['code'],
            ...$dependencySetup['code'],
        ];

        $httpMethod = strtolower($route['method']);
        $methodName = 'test_' . $this->normalizeName($route['name'])
            . '_does_not_return_a_server_error_for_'
            . $httpMethod . '_request';
        $setupCode = $this->indent(
            implode("\n\n", $setup['code']),
            4
        );
        $routeCall = $this->generateRouteCall($route);
        $payload = $this->exportPayload(
            $analysis['payload'] ?? [],
            $analysis['dependencies'] ?? []
        );

        return [
            'generated' => true,
            'code' => implode("\n", [
                'public function ' . $methodName . '(): void',
                '{',
                $setupCode,
                '',
                '    $response = $this->' . $httpMethod . '(',
                '        ' . $routeCall . ',',
                '        ' . $payload,
                '    );',
                '',
                '    $this->assertLessThan(500, $response->status());',
                '    $this->assertNotSame(404, $response->status());',
                '}',
            ]),
        ];
    }

    private function validate(array $analysis, array $route): ?string
    {
        if (! is_array($analysis['payload'] ?? null)) {
            return 'The write request analysis is invalid; the test could not be generated.';
        }

        if (
            ! is_string($route['name'] ?? null)
            || ! is_string($route['method'] ?? null)
            || ! in_array(strtoupper($route['method']), self::SUPPORTED_METHODS, true)
        ) {
            return 'The write route is invalid or unsupported; the test could not be generated.';
        }

        return null;
    }

    private function generateRouteSetup(array $route): array
    {
        $code = [];

        foreach ($route['parameterModels'] ?? [] as $parameter) {
            $variable = $parameter['variable'] ?? null;
            $modelClass = $parameter['class'] ?? null;

            if (! is_string($variable) || ! is_string($modelClass)) {
                return [
                    'generated' => false,
                    'message' => 'A write route parameter model is invalid; the test could not be generated.',
                ];
            }

            $factory = $this->factoryTestGenerator->generateRouteParameter(
                $variable,
                $modelClass
            );

            if (($factory['generated'] ?? false) !== true) {
                return $factory;
            }

            $code[] = $factory['code'];
        }

        foreach ($route['parameterValues'] ?? [] as $parameter) {
            if (! is_string($parameter['variable'] ?? null)) {
                continue;
            }

            $code[] = '$' . $parameter['variable'] . ' = '
                . var_export($parameter['value'] ?? 'test', true) . ';';
        }

        if ($code === []) {
            $code[] = '// This route does not require route parameters.';
        }

        return ['generated' => true, 'code' => $code];
    }

    private function generateRouteCall(array $route): string
    {
        $routeName = var_export($route['name'], true);
        $parameters = [];

        foreach ($route['parameters'] ?? [] as $name => $variable) {
            if (is_string($name) && is_string($variable)) {
                $parameters[] = var_export($name, true) . ' => $' . $variable;
            }
        }

        return $parameters === []
            ? 'route(' . $routeName . ')'
            : 'route(' . $routeName . ', ['
                . implode(', ', $parameters) . '])';
    }

    private function generateDependencySetup(array $dependencies): array
    {
        $code = [];

        foreach ($dependencies as $dependency) {
            if (! is_array($dependency)) {
                continue;
            }

            $result = $this->factoryTestGenerator
                ->generateExistsRecord($dependency);

            if (($result['generated'] ?? false) !== true) {
                return $result;
            }

            $code[] = $result['code'];
        }

        return ['generated' => true, 'code' => $code];
    }

    private function normalizeName(string $name): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', '_', $name);

        return strtolower(trim($normalized ?? $name, '_'));
    }

    private function exportPayload(
        array $payload,
        array $dependencies = []
    ): string
    {
        if ($payload === []) {
            return '[]';
        }

        $values = [];

        foreach ($payload as $field => $value) {
            $dependency = $dependencies[$field] ?? null;
            $exportedValue = is_array($dependency)
                && is_string($dependency['variable'] ?? null)
                && is_string($dependency['column'] ?? null)
                    ? '$' . $dependency['variable']
                        . '->' . $dependency['column']
                    : $this->exportValue($value);
            $values[] = var_export($field, true)
                . ' => ' . $exportedValue;
        }

        return '[' . implode(', ', $values) . ']';
    }

    private function exportValue(mixed $value): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        $items = [];

        foreach ($value as $key => $item) {
            $prefix = is_int($key) ? '' : var_export($key, true) . ' => ';
            $items[] = $prefix . $this->exportValue($item);
        }

        return '[' . implode(', ', $items) . ']';
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
