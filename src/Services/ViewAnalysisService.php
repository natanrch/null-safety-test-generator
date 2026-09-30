<?php

namespace Natan\NullSafetyTestGenerator\Services;

use Natan\NullSafetyTestGenerator\Analyzers\BladeAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerViewAnalyzer;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\ViewPathResolver;

class ViewAnalysisService
{
    public function __construct(
        private ControllerViewAnalyzer $controllerViewAnalyzer,
        private BladeAnalyzer $bladeAnalyzer,
        private EloquentAccessChainResolver $accessChainResolver,
        private ?ViewPathResolver $viewPathResolver = null
    ) {
    }

    public function analyze(
        string $controllerClass,
        string $method,
        ?string $viewPath = null
    ): array 
    {
        $controllerAnalysis = $this->controllerViewAnalyzer->analyze(
            $controllerClass,
            $method
        );

        if (
            ! isset($controllerAnalysis['view'])
            || ! isset($controllerAnalysis['variables'])
            || ! is_array($controllerAnalysis['variables'])
        ) {
            return [];
        }

        if ($viewPath === null) {
            $viewPath = $this->viewPathResolver?->resolve(
                $controllerAnalysis['view']
            );
        }

        if ($viewPath === null) {
            return [];
        }

        $bladeAccesses = $this->bladeAnalyzer->analyze(
            $viewPath,
            $this->viewPathResolver
        );
        $combinedAccesses = [];
        $requestPreconditions = [];

        foreach ($controllerAnalysis['variables'] as $root => $variable) {
            if (
                ! is_string($root)
                || ! is_array($variable)
                || ! is_array($variable['input'] ?? null)
                || ! is_string($variable['class'] ?? null)
            ) {
                continue;
            }

            $requestPreconditions[] = [
                'root' => $root,
                'class' => $variable['class'],
                'input' => $variable['input'],
            ];
        }

        foreach ($bladeAccesses as $bladeAccess) {
            $root = $bladeAccess['root'] ?? null;

            if (! is_string($root)) {
                continue;
            }

            $variable = $controllerAnalysis['variables'][$root] ?? null;

            if (
                ! is_array($variable)
                || ! isset($variable['class'], $variable['type'])
            ) {
                continue;
            }

            $combinedAccess = [
                'root' => $root,
                'class' => $variable['class'],
                'type' => $variable['type'],
            ];

            if (isset($variable['input']) && is_array($variable['input'])) {
                $combinedAccess['input'] = $variable['input'];
            }

            if (isset($bladeAccess['alias'])) {
                $combinedAccess['alias'] = $bladeAccess['alias'];
            }

            if (isset($bladeAccess['usage'])) {
                $combinedAccess['usage'] = $bladeAccess['usage'];
            }

            $combinedAccess['accesses'] = $bladeAccess['accesses'] ?? [];
            $combinedAccess['resolvedAccesses'] =
                $this->accessChainResolver->resolve(
                    $variable['class'],
                    $combinedAccess['accesses']
                );

            $combinedAccesses[] = $combinedAccess;
        }

        $result = [
            'view' => $controllerAnalysis['view'],
            'accesses' => $combinedAccesses,
        ];

        if ($requestPreconditions !== []) {
            $result['requestPreconditions'] = $requestPreconditions;
        }

        if (is_array($controllerAnalysis['requestParameters'] ?? null)) {
            $result['requestParameters'] =
                $controllerAnalysis['requestParameters'];
        }

        return $result;
    }
}
