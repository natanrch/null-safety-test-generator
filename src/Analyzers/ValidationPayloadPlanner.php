<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentModelClassResolver;
use Throwable;

class ValidationPayloadPlanner
{
    private EloquentModelClassResolver $modelResolver;

    public function __construct(
        ?EloquentModelClassResolver $modelResolver = null
    ) {
        $this->modelResolver = $modelResolver
            ?? new EloquentModelClassResolver();
    }

    public function plan(
        array $fields,
        array $payload,
        array $modelVariables = []
    ): array
    {
        $fixtures = [];
        $expressions = [];

        foreach ($fields as $field => $metadata) {
            foreach ($metadata['rules'] ?? [] as $rule) {
                if (
                    is_array($rule)
                    && ($rule['name'] ?? null) === 'exists'
                    && ($rule['supported'] ?? false) !== true
                ) {
                    return $this->failure(sprintf(
                        'The constraints for the %s exists rule could not be resolved; the test could not be generated.',
                        is_string($field) ? $field : 'unknown'
                    ));
                }
            }
        }

        foreach ($fields as $field => $metadata) {
            if (! is_string($field) || ! str_contains($field, '*')) {
                continue;
            }

            $parent = $this->terminalWildcardParent($field);

            if ($parent === null || ! isset($fields[$parent])) {
                return $this->failure(sprintf(
                    'Wildcard validation field %s is not supported; the test could not be generated.',
                    $field
                ));
            }

            unset($payload[$field]);
            $parentRules = $fields[$parent]['rules'] ?? [];

            if (! $this->shouldInclude($parentRules)) {
                unset($payload[$parent]);
                continue;
            }

            $count = $this->minimumItems($parentRules);

            if ($count > 20) {
                return $this->failure(sprintf(
                    'Wildcard validation field %s requires %d records, exceeding the safe generation limit of 20.',
                    $field,
                    $count
                ));
            }
            $itemRules = is_array($metadata['rules'] ?? null)
                ? $metadata['rules']
                : [];
            $exists = $this->structuredExistsRule($itemRules);

            if ($exists === null) {
                $payload[$parent] = $this->scalarItems(
                    $metadata['value'] ?? 'test',
                    $count,
                    $this->hasRule($itemRules, 'distinct')
                );
                continue;
            }

            if (($exists['supported'] ?? false) !== true) {
                return $this->failure(sprintf(
                    'The constraints for the %s exists rule could not be resolved; the test could not be generated.',
                    $field
                ));
            }

            if (! $this->constraintsUseKnownModels(
                $exists['constraints'] ?? [],
                $modelVariables
            )) {
                return $this->failure(sprintf(
                    'The constraints for the %s exists rule reference an unknown model variable; the test could not be generated.',
                    $field
                ));
            }

            $modelClass = $this->modelForTable($exists['table'] ?? null);

            if ($modelClass === null) {
                return $this->failure(sprintf(
                    'No Eloquent model could be resolved for table %s; the test could not be generated.',
                    is_string($exists['table'] ?? null)
                        ? $exists['table']
                        : 'unknown'
                ));
            }

            $variable = Str::camel($parent) . 'Records';
            $column = is_string($exists['column'] ?? null)
                ? $exists['column']
                : 'id';
            $fixtures[] = [
                'field' => $parent,
                'model' => $modelClass,
                'variable' => $variable,
                'count' => $count,
                'column' => $column,
                'constraints' => $exists['constraints'] ?? [],
            ];
            $expressions[$parent] = $this->collectionValueExpression(
                $variable,
                $modelClass,
                $column
            );
            $payload[$parent] = [];
        }

        return [
            'valid' => true,
            'payload' => $payload,
            'fixtures' => $fixtures,
            'expressions' => $expressions,
        ];
    }

    private function terminalWildcardParent(string $field): ?string
    {
        if (
            ! str_ends_with($field, '.*')
            || substr_count($field, '*') !== 1
        ) {
            return null;
        }

        $parent = substr($field, 0, -2);

        return $parent !== '' ? $parent : null;
    }

    private function minimumItems(array $rules): int
    {
        $minimum = $this->integerRuleArgument($rules, 'min');

        return max(1, $minimum ?? 1);
    }

    private function integerRuleArgument(array $rules, string $name): ?int
    {
        foreach ($rules as $rule) {
            if (! is_string($rule) || ! str_starts_with($rule, $name . ':')) {
                continue;
            }

            $value = substr($rule, strlen($name) + 1);

            return ctype_digit($value) ? (int) $value : null;
        }

        return null;
    }

    private function shouldInclude(array $rules): bool
    {
        $optional = $this->hasRule($rules, 'nullable')
            || $this->hasRule($rules, 'sometimes');
        $required = $this->hasRule($rules, 'required')
            || $this->hasRule($rules, 'present');

        return ! $optional || $required;
    }

    private function hasRule(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            if (
                is_string($rule)
                && explode(':', $rule, 2)[0] === $name
            ) {
                return true;
            }
        }

        return false;
    }

    private function structuredExistsRule(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (
                is_array($rule)
                && ($rule['name'] ?? null) === 'exists'
            ) {
                return $rule;
            }

            if (is_string($rule) && str_starts_with($rule, 'exists:')) {
                $arguments = explode(',', substr($rule, 7));
                $table = trim($arguments[0] ?? '');
                $column = trim($arguments[1] ?? 'id');

                if ($table !== '' && $column !== '') {
                    return [
                        'name' => 'exists',
                        'table' => $table,
                        'column' => $column,
                        'constraints' => [],
                        'supported' => true,
                    ];
                }
            }
        }

        return null;
    }

    private function constraintsUseKnownModels(
        array $constraints,
        array $modelVariables
    ): bool {
        foreach ($constraints as $constraint) {
            $source = $constraint['source'] ?? null;

            if (! is_array($source)) {
                return false;
            }

            if (($source['type'] ?? null) === 'literal') {
                continue;
            }

            if (
                ! in_array(
                    $source['variable'] ?? null,
                    $modelVariables,
                    true
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function scalarItems(
        mixed $value,
        int $count,
        bool $distinct
    ): array {
        $items = [];

        for ($index = 0; $index < $count; $index++) {
            $items[] = $distinct
                ? $this->distinctValue($value, $index)
                : $value;
        }

        return $items;
    }

    private function distinctValue(mixed $value, int $index): mixed
    {
        if (is_int($value)) {
            return $value + $index;
        }

        if (is_float($value)) {
            return $value + $index;
        }

        if (is_string($value)) {
            return $index === 0 ? $value : $value . $index;
        }

        return $value;
    }

    private function modelForTable(mixed $table): ?string
    {
        return is_string($table)
            ? $this->modelResolver->resolveTable($table)
            : null;
    }

    private function collectionValueExpression(
        string $variable,
        string $modelClass,
        string $column
    ): string {
        try {
            /** @var Model $model */
            $model = new $modelClass();

            if ($model->getKeyName() === $column) {
                return '$' . $variable . '->modelKeys()';
            }
        } catch (Throwable) {
            // The validated column can still be read from the collection.
        }

        return '$' . $variable . '->pluck('
            . var_export($column, true) . ')->all()';
    }

    private function failure(string $message): array
    {
        return [
            'valid' => false,
            'message' => $message,
            'payload' => [],
            'fixtures' => [],
            'expressions' => [],
        ];
    }
}
