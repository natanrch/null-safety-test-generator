<?php

namespace Natan\NullSafetyTestGenerator\Inspectors;

use Illuminate\Database\Eloquent\Model;
use Throwable;

class DatabaseColumnInspector
{
    public function inspect(string $modelClass, string $columnName): array
    {
        if (
            ! class_exists($modelClass)
            || ! is_subclass_of($modelClass, Model::class)
        ) {
            return $this->unavailable(
                'The model class is not a valid Eloquent model.'
            );
        }

        try {
            /** @var Model $model */
            $model = new $modelClass();
            $columns = $model->getConnection()
                ->getSchemaBuilder()
                ->getColumns($model->getTable());

            foreach ($columns as $column) {
                if (($column['name'] ?? null) !== $columnName) {
                    continue;
                }

                return [
                    'inspected' => true,
                    'exists' => true,
                    'nullable' => (bool) ($column['nullable'] ?? false),
                    'primary' => $model->getKeyName() === $columnName,
                    'table' => $model->getTable(),
                    'column' => $columnName,
                ];
            }

            return [
                'inspected' => true,
                'exists' => false,
                'nullable' => null,
                'primary' => false,
                'table' => $model->getTable(),
                'column' => $columnName,
            ];
        } catch (Throwable $exception) {
            return $this->unavailable($exception->getMessage());
        }
    }

    private function unavailable(string $message): array
    {
        return [
            'inspected' => false,
            'exists' => false,
            'nullable' => null,
            'primary' => false,
            'message' => $message,
        ];
    }
}
