<?php

namespace Natan\NullSafetyTestGenerator\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class EloquentModelClassResolver
{
    private bool $applicationModelsDiscovered = false;

    private array $cache = [];

    public function resolveTable(string $table): ?string
    {
        if ($table === '') {
            return null;
        }

        if (array_key_exists($table, $this->cache)) {
            return $this->cache[$table];
        }

        $baseName = Str::studly(Str::singular($table));
        $conventionalClass = 'App\\Models\\' . $baseName;

        if ($this->usesTable($conventionalClass, $table)) {
            return $this->cache[$table] = $conventionalClass;
        }

        $this->discoverApplicationModels();
        $fallback = null;

        foreach (get_declared_classes() as $className) {
            if (! $this->usesTable($className, $table)) {
                continue;
            }

            if (class_basename($className) === $baseName) {
                return $this->cache[$table] = $className;
            }

            $fallback ??= $className;
        }

        return $this->cache[$table] = $fallback;
    }

    private function discoverApplicationModels(): void
    {
        if ($this->applicationModelsDiscovered) {
            return;
        }

        $this->applicationModelsDiscovered = true;

        if (! function_exists('app_path')) {
            return;
        }

        try {
            $path = app_path('Models');
        } catch (Throwable) {
            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($path) + 1);
            $className = 'App\\Models\\' . str_replace(
                ['/', '\\', '.php'],
                ['\\', '\\', ''],
                $relative
            );
            class_exists($className);
        }
    }

    private function usesTable(string $className, string $table): bool
    {
        if (
            ! class_exists($className)
            || ! is_subclass_of($className, Model::class)
        ) {
            return false;
        }

        try {
            return (new $className())->getTable() === $table;
        } catch (Throwable) {
            return false;
        }
    }
}
