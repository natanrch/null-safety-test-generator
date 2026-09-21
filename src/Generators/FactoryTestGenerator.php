<?php

namespace Natan\NullSafetyTestGenerator\Generators;

use Throwable;

class FactoryTestGenerator
{
    public function generate(array $scenario): array
    {
        $modelClass = $scenario['rootClass'] ?? null;

        if (! is_string($modelClass) || ! $this->factoryExists($modelClass)) {
            return $this->factoryNotFoundResult($modelClass);
        }

        $root = $scenario['root'] ?? null;
        $property = $scenario['target']['property'] ?? null;

        if (! is_string($root) || ! is_string($property)) {
            return [
                'generated' => false,
                'message' => 'The null scenario is invalid; the test could not be generated.',
            ];
        }

        return [
            'generated' => true,
            'code' => implode("\n", [
                '$' . $root . ' = \\' . $modelClass
                    . '::factory()->create([',
                "    '" . $property . "' => null,",
                ']);',
            ]),
        ];
    }

    private function factoryExists(string $modelClass): bool
    {
        if (
            ! class_exists($modelClass)
            || ! method_exists($modelClass, 'factory')
        ) {
            return false;
        }

        try {
            return is_object($modelClass::factory());
        } catch (Throwable) {
            return false;
        }
    }

    private function factoryNotFoundResult(mixed $modelClass): array
    {
        $modelName = is_string($modelClass)
            ? $modelClass
            : 'unknown';

        return [
            'generated' => false,
            'message' => sprintf(
                'Factory for model %s does not exist; the test could not be generated.',
                $modelName
            ),
        ];
    }
}
