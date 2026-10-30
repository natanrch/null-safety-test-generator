<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ModelPropagationAnalyzer;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\NewStaticCollectionPropagationController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NewStaticCollectionPropagationTest extends TestCase
{
    public static function methods(): iterable
    {
        foreach ([
            'localNew' => ['author', 'author.profile'],
            'inlineNew' => ['author', 'author.profile'],
            'constructorNew' => ['author', 'author.profile'],
            'inlineConstructorNew' => ['author', 'author.profile'],
            'staticCall' => ['author', 'author.profile'],
            'collectionCallback' => [
                'author',
                'author.posts',
                'author.posts.author',
                'author.posts.author.profile',
            ],
            'arrowCollectionCallback' => [
                'author',
                'author.posts',
                'author.posts.author.profile',
            ],
        ] as $method => $expected) {
            foreach ([false, true] as $write) {
                yield $method . ($write ? ' write' : ' get') => [
                    $method,
                    $write,
                    $expected,
                ];
            }
        }
    }

    #[DataProvider('methods')]
    public function test_it_propagates_models_through_new_static_calls_and_collection_callbacks(
        string $method,
        bool $write,
        array $expected
    ): void {
        $accesses = (new ModelPropagationAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            ),
            $write
        ))->analyze(
            NewStaticCollectionPropagationController::class,
            $method
        );

        $scenarios = (new NullScenarioGenerator())->generate([
            'accesses' => $accesses,
        ]);
        $paths = array_values(array_unique(array_map(
            static fn (array $scenario): string => implode(
                '.',
                $scenario['path']
            ),
            $scenarios
        )));

        foreach ($expected as $path) {
            $this->assertContains($path, $paths);
        }
    }
}
