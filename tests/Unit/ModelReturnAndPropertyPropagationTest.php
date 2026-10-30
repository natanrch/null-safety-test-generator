<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ModelPropagationAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\ReturnAndPropertyPropagationController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModelReturnAndPropertyPropagationTest extends TestCase
{
    public static function methods(): iterable
    {
        foreach ([
            'returned',
            'returnedInternally',
            'storedInternally',
            'storedAcrossCalls',
            'returnedFromProperty',
            'returnedModel',
            'directlyChainedReturn',
        ] as $method) {
            foreach ([false, true] as $write) {
                yield $method . ($write ? ' write' : ' get') => [
                    $method,
                    $write,
                ];
            }
        }
    }

    #[DataProvider('methods')]
    public function test_it_follows_method_returns_and_stored_models(
        string $method,
        bool $write
    ): void {
        $accesses = (new ModelPropagationAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            ),
            $write
        ))->analyze(
            ReturnAndPropertyPropagationController::class,
            $method
        );

        $scenarios = (new NullScenarioGenerator())->generate([
            'accesses' => $accesses,
        ]);
        $paths = array_map(
            static fn (array $scenario): string => implode(
                '.',
                $scenario['path']
            ),
            $scenarios
        );

        $this->assertContains('author', $paths);
        $this->assertContains('author.profile', $paths);
        $this->assertNotContains('profile', $paths);
    }

    #[DataProvider('guardedMethods')]
    public function test_guards_are_preserved_through_returns_and_properties(
        string $method,
        bool $write
    ): void {
        $accesses = (new ModelPropagationAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            ),
            $write
        ))->analyze(
            ReturnAndPropertyPropagationController::class,
            $method
        );
        $scenarios = (new NullScenarioGenerator())->generate([
            'accesses' => $accesses,
        ]);

        $this->assertSame(
            ['author.profile'],
            array_map(
                static fn (array $scenario): string => implode(
                    '.',
                    $scenario['path']
                ),
                $scenarios
            )
        );
    }

    public static function guardedMethods(): iterable
    {
        foreach (['guardedReturn', 'guardedStored'] as $method) {
            foreach ([false, true] as $write) {
                yield $method . ($write ? ' write' : ' get') => [
                    $method,
                    $write,
                ];
            }
        }
    }
}
