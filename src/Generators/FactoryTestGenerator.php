<?php

namespace Natan\NullSafetyTestGenerator\Generators;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Natan\NullSafetyTestGenerator\Inspectors\DatabaseColumnInspector;
use Throwable;

class FactoryTestGenerator
{
    private DatabaseColumnInspector $columnInspector;

    public function __construct(?DatabaseColumnInspector $columnInspector = null)
    {
        $this->columnInspector = $columnInspector
            ?? new DatabaseColumnInspector();
    }

    public function generateRouteParameter(
        string $variable,
        string $modelClass
    ): array {
        if (
            ! class_exists($modelClass)
            || ! is_subclass_of($modelClass, Model::class)
        ) {
            return [
                'generated' => false,
                'message' => sprintf(
                    'The route parameter model class %s is invalid; the test could not be generated.',
                    $modelClass
                ),
            ];
        }

        if (! $this->factoryExists($modelClass)) {
            return $this->factoryNotFoundResult($modelClass);
        }

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $variable) !== 1) {
            return [
                'generated' => false,
                'message' => 'The route parameter variable is invalid; the test could not be generated.',
            ];
        }

        return [
            'generated' => true,
            'code' => '$' . $variable . ' = \\' . $modelClass
                . '::factory()->create();',
        ];
    }

    public function generate(array $scenario): array
    {
        $modelClass = $scenario['rootClass'] ?? null;
        $invalidModelClass = $this->findInvalidModelClass($scenario);

        if ($invalidModelClass !== null) {
            return [
                'generated' => false,
                'message' => sprintf(
                    'The model class %s is invalid; the test could not be generated.',
                    $invalidModelClass
                ),
            ];
        }

        $root = $scenario['root'] ?? null;
        $property = $scenario['target']['property'] ?? null;

        if (! is_string($root) || ! is_string($property)) {
            return [
                'generated' => false,
                'message' => 'The null scenario is invalid; the test could not be generated.',
            ];
        }

        $resolvedPath = $scenario['resolvedPath'] ?? [];
        $strategy = $scenario['strategy'] ?? null;

        if (
            $strategy === 'null_attribute'
            || (
                $strategy === 'missing_relationship'
                && ($scenario['target']['relation'] ?? null) === 'belongsTo'
            )
        ) {
            $columnResult = $this->inspectNullableTarget(
                $scenario,
                $strategy
            );

            if ($columnResult !== null) {
                return $columnResult;
            }
        }

        $missingFactoryModel = $this->findModelWithoutFactory($scenario);

        if ($missingFactoryModel !== null) {
            return $this->factoryNotFoundResult($missingFactoryModel);
        }

        if (
            in_array(
                $strategy,
                ['missing_relationship', 'empty_collection'],
                true
            )
        ) {
            $code = $this->generateAbsentRelationshipCode(
                $root,
                $resolvedPath,
                $strategy
            );

            if ($code === null) {
                return $this->unsupportedRelationshipPathResult();
            }

            return [
                'generated' => true,
                'code' => $code,
            ];
        }

        if ($strategy !== 'null_attribute') {
            return [
                'generated' => false,
                'message' => 'The null scenario strategy is not supported; the test could not be generated.',
            ];
        }

        if (count($resolvedPath) > 1) {
            $code = $this->generateNestedAttributeCode(
                $root,
                $resolvedPath
            );

            if ($code === null) {
                return $this->unsupportedRelationshipPathResult();
            }

            return [
                'generated' => true,
                'code' => $code,
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

    private function generateAbsentRelationshipCode(
        string $root,
        array $resolvedPath,
        string $strategy
    ): ?string {
        $target = array_pop($resolvedPath);

        if (
            ! is_array($target)
            || ($target['kind'] ?? null) !== 'relationship'
            || ! isset(
                $target['model'],
                $target['property'],
                $target['relation']
            )
            || ! is_string($target['model'])
            || ! is_string($target['property'])
            || ! is_string($target['relation'])
        ) {
            return null;
        }

        $toManyRelationships = [
            'belongsToMany',
            'hasMany',
            'hasManyThrough',
            'morphMany',
            'morphToMany',
            'morphedByMany',
        ];

        if (
            $strategy === 'empty_collection'
            && ! in_array(
                $target['relation'],
                $toManyRelationships,
                true
            )
        ) {
            return null;
        }

        $factoryExpression = '\\' . $target['model']
            . '::factory()';

        if (
            $strategy === 'missing_relationship'
            && $target['relation'] === 'belongsTo'
        ) {
            $foreignKey = Str::snake($target['property']) . '_id';
            $factoryExpression .= '->state(['
                . var_export($foreignKey, true)
                . ' => null])';
        }

        foreach (array_reverse($resolvedPath) as $relationship) {
            $factoryExpression = $this->wrapFactoryForRelationship(
                $factoryExpression,
                $relationship
            );

            if ($factoryExpression === null) {
                return null;
            }
        }

        return '$' . $root . ' = ' . $factoryExpression . '->create();';
    }

    private function generateNestedAttributeCode(
        string $root,
        array $resolvedPath
    ): ?string {
        $target = array_pop($resolvedPath);

        if (
            ! is_array($target)
            || ($target['kind'] ?? null) !== 'attribute'
            || ! isset($target['model'], $target['property'])
            || ! is_string($target['model'])
            || ! is_string($target['property'])
        ) {
            return null;
        }

        $factoryExpression = '\\' . $target['model']
            . '::factory()->state(['
            . var_export($target['property'], true)
            . ' => null])';

        foreach (array_reverse($resolvedPath) as $relationship) {
            $factoryExpression = $this->wrapFactoryForRelationship(
                $factoryExpression,
                $relationship
            );

            if ($factoryExpression === null) {
                return null;
            }
        }

        return '$' . $root . ' = ' . $factoryExpression . '->create();';
    }

    private function wrapFactoryForRelationship(
        string $relatedFactory,
        array $relationship
    ): ?string {
        if (
            ! isset(
                $relationship['model'],
                $relationship['property'],
                $relationship['relation']
            )
            || ! is_string($relationship['model'])
            || ! is_string($relationship['property'])
            || ! is_string($relationship['relation'])
        ) {
            return null;
        }

        $parentFactory = '\\' . $relationship['model']
            . '::factory()';
        $relationshipName = var_export(
            $relationship['property'],
            true
        );

        if ($relationship['relation'] === 'belongsTo') {
            return $parentFactory
                . '->for(' . $relatedFactory
                . ', ' . $relationshipName . ')';
        }

        if (
            in_array(
                $relationship['relation'],
                ['hasOne', 'hasMany', 'morphOne', 'morphMany'],
                true
            )
        ) {
            return $parentFactory
                . '->has(' . $relatedFactory
                . ', ' . $relationshipName . ')';
        }

        return null;
    }

    private function unsupportedRelationshipPathResult(): array
    {
        return [
            'generated' => false,
            'message' => 'The relationship path is not supported; the test could not be generated.',
        ];
    }

    private function inspectNullableTarget(
        array $scenario,
        string $strategy
    ): ?array {
        $modelClass = $scenario['target']['model'] ?? null;
        $property = $scenario['target']['property'] ?? null;

        if (! is_string($modelClass) || ! is_string($property)) {
            return null;
        }

        $columnName = $strategy === 'missing_relationship'
            ? Str::snake($property) . '_id'
            : $property;

        $inspection = $this->columnInspector->inspect(
            $modelClass,
            $columnName
        );

        if (
            ($inspection['inspected'] ?? false) === true
            && ($inspection['exists'] ?? true) === false
        ) {
            return [
                'generated' => false,
                'message' => sprintf(
                    'Property %s.%s is not a database column and could not be resolved as a relationship; the scenario was skipped.',
                    $inspection['table'],
                    $inspection['column']
                ),
            ];
        }

        if (
            ($inspection['inspected'] ?? false) !== true
            || ($inspection['exists'] ?? false) !== true
            || ($inspection['nullable'] ?? null) !== false
        ) {
            return null;
        }

        return [
            'generated' => false,
            'message' => sprintf(
                'Column %s.%s does not accept null; the scenario was skipped.',
                $inspection['table'],
                $inspection['column']
            ),
        ];
    }

    private function findModelWithoutFactory(array $scenario): ?string
    {
        foreach ($this->getModelClasses($scenario) as $modelClass) {
            if (! $this->factoryExists($modelClass)) {
                return $modelClass;
            }
        }

        return null;
    }

    private function findInvalidModelClass(array $scenario): ?string
    {
        foreach ($this->getModelClasses($scenario) as $modelClass) {
            if (! class_exists($modelClass)) {
                return $modelClass;
            }
        }

        return null;
    }

    private function getModelClasses(array $scenario): array
    {
        $rootClass = $scenario['rootClass'] ?? null;

        if (! is_string($rootClass)) {
            return ['unknown'];
        }

        $modelClasses = [$rootClass => true];

        foreach ($scenario['resolvedPath'] ?? [] as $resolvedAccess) {
            foreach (['model', 'relatedClass'] as $classKey) {
                $className = $resolvedAccess[$classKey] ?? null;

                if (is_string($className)) {
                    $modelClasses[$className] = true;
                }
            }
        }

        return array_keys($modelClasses);
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
