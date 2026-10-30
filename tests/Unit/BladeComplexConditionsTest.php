<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\BladeAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BladeComplexConditionsTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'and' => ['and', []],
            'or does not prove either operand' => ['or', ['author', 'author.profile']],
            'negative branch' => ['negative', []],
            'elseif remembers previous failures' => ['elseif', []],
            'condition itself can fail' => ['unsafe-condition', ['author']],
            'ternary' => ['ternary', []],
            'unknown business method' => ['unknown-method', ['author', 'author.profile']],
            'assignment invalidates guard' => ['reassigned', ['author', 'editor', 'editor.profile']],
            'property mutation invalidates guard' => ['mutated', ['author', 'author.profile']],
            'or keeps common guarantees' => ['common-or', ['author.profile']],
            'is_null' => ['null-function', ['author.profile']],
            'or short circuit' => ['short-circuit-or', []],
            'is_null arguments can fail' => ['unsafe-is-null', ['author']],
            'nested include' => ['include', []],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_generates_only_unprotected_relationship_scenarios(string $fixture, array $expected): void
    {
        $accesses = (new BladeAnalyzer())->analyze(
            __DIR__ . '/../Fixtures/views/blade/conditions/' . $fixture . '.blade.php'
        );
        foreach ($accesses as &$access) {
            $access['class'] = FakePost::class;
            $access['type'] = 'object';
            $access['resolvedAccesses'] = [];
            foreach ($access['accesses'] as $part) {
                if ($part['type'] !== 'property') {
                    break;
                }
                $access['resolvedAccesses'][] = [
                    'model' => FakePost::class,
                    'property' => $part['name'],
                    'kind' => $part['name'] === 'name' ? 'attribute' : 'relationship',
                    'relation' => 'hasOne',
                    'relatedClass' => FakePost::class,
                ];
            }
        }
        unset($access);
        $scenarios = (new NullScenarioGenerator())->generate(['accesses' => $accesses]);
        $paths = array_map(static fn (array $scenario): string => implode('.', $scenario['path']), $scenarios);
        $this->assertSame($expected, $paths);
    }
}
