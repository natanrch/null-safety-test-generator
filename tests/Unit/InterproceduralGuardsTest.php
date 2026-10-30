<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ModelPropagationAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\GuardPropagationController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InterproceduralGuardsTest extends TestCase
{
    public static function cases(): iterable
    {
        $cases = [
            'guarded' => [],
            'partial' => ['post.author.profile'],
            'mixed' => ['post.author'],
            'mixedReverse' => ['post.author'],
            'related' => ['post.author.profile'],
            'negativeBranch' => [],
            'disjunction' => ['post.author'],
            'reassigned' => ['other.author'],
            'mutation' => ['post.author'],
            'serviceMutation' => ['post.author'],
            'callerAfterMutation' => ['post.author'],
            'pureCalls' => [],
            'unknownMutation' => ['post.author'],
        ];
        foreach ($cases as $method => $expected) {
            foreach ([false, true] as $write) {
                yield $method . ($write ? ' write' : ' get') => [$method, $write, $expected];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_protection_depends_on_the_call_path(string $method, bool $write, array $expected): void
    {
        $analyzer = new ModelPropagationAnalyzer(
            new EloquentAccessChainResolver(new EloquentRelationshipResolver()),
            $write
        );
        $accesses = $analyzer->analyze(GuardPropagationController::class, $method);
        $scenarios = (new NullScenarioGenerator())->generate(['accesses' => $accesses]);
        $paths = array_map(static fn (array $scenario): string =>
            $scenario['root'] . '.' . implode('.', $scenario['path']), $scenarios);
        sort($paths);
        sort($expected);
        $this->assertSame($expected, $paths);
    }
}
