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

            if (isset($bladeAccess['alias'])) {
                $combinedAccess['alias'] = $bladeAccess['alias'];
            }

            $combinedAccess['accesses'] = $bladeAccess['accesses'] ?? [];
            $combinedAccess['resolvedAccesses'] =
                $this->accessChainResolver->resolve(
                    $variable['class'],
                    $combinedAccess['accesses']
                );

            $combinedAccesses[] = $combinedAccess;
        }

        return [
            'view' => $controllerAnalysis['view'],
            'accesses' => $combinedAccesses,
        ];
    }
}
