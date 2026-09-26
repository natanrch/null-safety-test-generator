<?php

namespace Natan\NullSafetyTestGenerator\Generators;

class NullScenarioGenerator
{
    private const TO_MANY_RELATIONSHIPS = [
        'belongsToMany',
        'hasMany',
        'hasManyThrough',
        'morphMany',
        'morphToMany',
        'morphedByMany',
    ];

    public function generate(array $viewAnalysis): array
    {
        $scenarios = [];
        $generatedScenarios = [];

        foreach ($viewAnalysis['accesses'] ?? [] as $analyzedAccess) {
            if (! $this->hasValidRootMetadata($analyzedAccess)) {
                continue;
            }

            if (($analyzedAccess['type'] ?? null) === 'collection') {
                $scenarioKey = implode('|', [
                    $analyzedAccess['root'],
                    $analyzedAccess['class'],
                    'empty_root_collection',
                ]);

                if (! isset($generatedScenarios[$scenarioKey])) {
                    $scenario = [
                        'root' => $analyzedAccess['root'],
                        'rootClass' => $analyzedAccess['class'],
                        'rootType' => 'collection',
                        'path' => [],
                        'resolvedPath' => [],
                        'target' => [
                            'model' => $analyzedAccess['class'],
                            'kind' => 'collection',
                        ],
                        'strategy' => 'empty_root_collection',
                    ];

                    if (
                        isset($analyzedAccess['input'])
                        && is_array($analyzedAccess['input'])
                    ) {
                        $scenario['input'] = $analyzedAccess['input'];
                    }

                    $scenarios[] = $scenario;
                    $generatedScenarios[$scenarioKey] = true;
                }
            }

            $path = [];
            $resolvedPath = [];

            foreach ($analyzedAccess['resolvedAccesses'] ?? [] as $target) {
                $property = $target['property'] ?? null;

                if (! is_string($property)) {
                    continue;
                }

                $path[] = $property;
                $resolvedPath[] = $target;
                $strategy = $this->getStrategy($target);

                if ($strategy === null) {
                    continue;
                }

                $scenarioKey = implode('|', [
                    $analyzedAccess['root'],
                    $analyzedAccess['class'],
                    implode('.', $path),
                    $strategy,
                ]);

                if (isset($generatedScenarios[$scenarioKey])) {
                    continue;
                }

                $scenario = [
                    'root' => $analyzedAccess['root'],
                    'rootClass' => $analyzedAccess['class'],
                    'rootType' => $analyzedAccess['type'],
                    'path' => $path,
                    'resolvedPath' => $resolvedPath,
                    'target' => $target,
                    'strategy' => $strategy,
                ];

                if (
                    isset($analyzedAccess['input'])
                    && is_array($analyzedAccess['input'])
                ) {
                    $scenario['input'] = $analyzedAccess['input'];
                }

                $scenarios[] = $scenario;

                $generatedScenarios[$scenarioKey] = true;
            }
        }

        return $scenarios;
    }

    private function hasValidRootMetadata(array $analyzedAccess): bool
    {
        return isset(
            $analyzedAccess['root'],
            $analyzedAccess['class'],
            $analyzedAccess['type']
        )
            && is_string($analyzedAccess['root'])
            && is_string($analyzedAccess['class'])
            && is_string($analyzedAccess['type']);
    }

    private function getStrategy(array $target): ?string
    {
        if (($target['kind'] ?? null) === 'attribute') {
            return 'null_attribute';
        }

        if (($target['kind'] ?? null) !== 'relationship') {
            return null;
        }

        if (
            in_array(
                $target['relation'] ?? null,
                self::TO_MANY_RELATIONSHIPS,
                true
            )
        ) {
            return 'empty_collection';
        }

        return 'missing_relationship';
    }
}
