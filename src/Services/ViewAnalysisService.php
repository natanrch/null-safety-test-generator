<?php

namespace Natan\NullSafetyTestGenerator\Services;

use Natan\NullSafetyTestGenerator\Analyzers\BladeAnalyzer;
use Natan\NullSafetyTestGenerator\Analyzers\ControllerViewAnalyzer;

class ViewAnalysisService
{
    public function __construct(
        private ControllerViewAnalyzer $controllerViewAnalyzer,
        private BladeAnalyzer $bladeAnalyzer
    ) {
    }

    public function analyze(
        string $controllerClass,
        string $method,
        string $viewPath
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

        $bladeAccesses = $this->bladeAnalyzer->analyze($viewPath);
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
            $combinedAccesses[] = $combinedAccess;
        }

        return [
            'view' => $controllerAnalysis['view'],
            'accesses' => $combinedAccesses,
        ];
    }
}
