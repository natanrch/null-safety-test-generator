<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\FactoryTestGenerator;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeAuthor;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeModelWithoutFactory;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeProfile;
use PHPUnit\Framework\TestCase;

class FactoryTestGeneratorTest extends TestCase
{
    public function test_it_generates_factory_code_when_the_factory_exists(): void
    {
        $generator = new FactoryTestGenerator();

        $result = $generator->generate(
            $this->attributeScenario(FakePost::class)
        );

        $this->assertSame([
            'generated' => true,
            'code' => implode("\n", [
                '$post = \\' . FakePost::class . '::factory()->create([',
                "    'title' => null,",
                ']);',
            ]),
        ], $result);
    }

    public function test_it_returns_a_message_when_the_factory_does_not_exist(): void
    {
        $generator = new FactoryTestGenerator();

        $result = $generator->generate(
            $this->attributeScenario(FakeModelWithoutFactory::class)
        );

        $this->assertSame([
            'generated' => false,
            'message' => sprintf(
                'Factory for model %s does not exist; the test could not be generated.',
                FakeModelWithoutFactory::class
            ),
        ], $result);
    }

    public function test_it_returns_a_message_when_the_model_class_is_invalid(): void
    {
        $generator = new FactoryTestGenerator();

        $result = $generator->generate(
            $this->attributeScenario('InvalidModelClass')
        );

        $this->assertSame([
            'generated' => false,
            'message' => 'The model class InvalidModelClass is invalid; the test could not be generated.',
        ], $result);
    }

    public function test_it_accepts_a_resolved_path_when_all_model_factories_exist(): void
    {
        $generator = new FactoryTestGenerator();

        $result = $generator->generate(
            $this->nestedAttributeScenario(FakeProfile::class)
        );

        $this->assertSame([
            'generated' => true,
            'code' => '$post = \\' . FakePost::class
                . '::factory()->for(\\' . FakeAuthor::class
                . '::factory()->has(\\' . FakeProfile::class
                . "::factory()->state(['name' => null]), 'profile'), 'author')->create();",
        ], $result);
    }

    public function test_it_returns_a_message_when_a_model_in_the_resolved_path_has_no_factory(): void
    {
        $generator = new FactoryTestGenerator();

        $result = $generator->generate(
            $this->nestedAttributeScenario(
                FakeModelWithoutFactory::class
            )
        );

        $this->assertSame([
            'generated' => false,
            'message' => sprintf(
                'Factory for model %s does not exist; the test could not be generated.',
                FakeModelWithoutFactory::class
            ),
        ], $result);
    }

    public function test_it_generates_code_for_a_missing_belongs_to_relationship(): void
    {
        $generator = new FactoryTestGenerator();

        $result = $generator->generate([
            'root' => 'post',
            'rootClass' => FakePost::class,
            'rootType' => 'object',
            'path' => ['author'],
            'resolvedPath' => [
                [
                    'model' => FakePost::class,
                    'property' => 'author',
                    'kind' => 'relationship',
                    'relation' => 'belongsTo',
                    'relatedClass' => FakeAuthor::class,
                ],
            ],
            'target' => [
                'model' => FakePost::class,
                'property' => 'author',
                'kind' => 'relationship',
                'relation' => 'belongsTo',
                'relatedClass' => FakeAuthor::class,
            ],
            'strategy' => 'missing_relationship',
        ]);

        $this->assertSame([
            'generated' => true,
            'code' => '$post = \\' . FakePost::class
                . "::factory()->state(['author_id' => null])->create();",
        ], $result);
    }

    public function test_it_generates_code_for_a_missing_nested_has_one_relationship(): void
    {
        $generator = new FactoryTestGenerator();

        $authorRelationship = [
            'model' => FakePost::class,
            'property' => 'author',
            'kind' => 'relationship',
            'relation' => 'belongsTo',
            'relatedClass' => FakeAuthor::class,
        ];

        $profileRelationship = [
            'model' => FakeAuthor::class,
            'property' => 'profile',
            'kind' => 'relationship',
            'relation' => 'hasOne',
            'relatedClass' => FakeProfile::class,
        ];

        $result = $generator->generate([
            'root' => 'post',
            'rootClass' => FakePost::class,
            'rootType' => 'object',
            'path' => ['author', 'profile'],
            'resolvedPath' => [
                $authorRelationship,
                $profileRelationship,
            ],
            'target' => $profileRelationship,
            'strategy' => 'missing_relationship',
        ]);

        $this->assertSame([
            'generated' => true,
            'code' => '$post = \\' . FakePost::class
                . '::factory()->for(\\' . FakeAuthor::class
                . "::factory(), 'author')->create();",
        ], $result);
    }

    public function test_it_generates_code_for_an_empty_has_many_collection(): void
    {
        $generator = new FactoryTestGenerator();

        $postsRelationship = [
            'model' => FakeAuthor::class,
            'property' => 'posts',
            'kind' => 'relationship',
            'relation' => 'hasMany',
            'relatedClass' => FakePost::class,
        ];

        $result = $generator->generate([
            'root' => 'author',
            'rootClass' => FakeAuthor::class,
            'rootType' => 'object',
            'path' => ['posts'],
            'resolvedPath' => [$postsRelationship],
            'target' => $postsRelationship,
            'strategy' => 'empty_collection',
        ]);

        $this->assertSame([
            'generated' => true,
            'code' => '$author = \\' . FakeAuthor::class
                . '::factory()->create();',
        ], $result);
    }

    public function test_it_generates_no_records_for_an_empty_root_collection(): void
    {
        $result = (new FactoryTestGenerator())->generate([
            'root' => 'posts',
            'rootClass' => FakePost::class,
            'rootType' => 'collection',
            'path' => [],
            'resolvedPath' => [],
            'target' => [
                'model' => FakePost::class,
                'kind' => 'collection',
            ],
            'strategy' => 'empty_root_collection',
        ]);

        $this->assertSame([
            'generated' => true,
            'code' => '// No ' . FakePost::class
                . ' records are created for this empty collection scenario.',
        ], $result);
    }

    public function test_it_omits_the_model_for_a_missing_request_parameter(): void
    {
        $result = (new FactoryTestGenerator())->generate([
            'root' => 'post',
            'rootClass' => FakePost::class,
            'rootType' => 'object',
            'path' => [],
            'resolvedPath' => [],
            'target' => [
                'kind' => 'request_parameter',
                'parameter' => 'post_id',
            ],
            'strategy' => 'missing_request_parameter',
            'input' => [
                'source' => 'request',
                'parameter' => 'post_id',
                'valueFrom' => 'model_key',
            ],
        ]);

        $this->assertSame([
            'generated' => true,
            'code' => '// The post_id request parameter is intentionally omitted.',
        ], $result);
    }

    private function attributeScenario(string $modelClass): array
    {
        return [
            'root' => 'post',
            'rootClass' => $modelClass,
            'rootType' => 'object',
            'path' => ['title'],
            'target' => [
                'model' => $modelClass,
                'property' => 'title',
                'kind' => 'attribute',
            ],
            'strategy' => 'null_attribute',
        ];
    }

    private function nestedAttributeScenario(
        string $finalModelClass
    ): array {
        return [
            'root' => 'post',
            'rootClass' => FakePost::class,
            'rootType' => 'object',
            'path' => ['author', 'profile', 'name'],
            'resolvedPath' => [
                [
                    'model' => FakePost::class,
                    'property' => 'author',
                    'kind' => 'relationship',
                    'relation' => 'belongsTo',
                    'relatedClass' => FakeAuthor::class,
                ],
                [
                    'model' => FakeAuthor::class,
                    'property' => 'profile',
                    'kind' => 'relationship',
                    'relation' => 'hasOne',
                    'relatedClass' => $finalModelClass,
                ],
                [
                    'model' => $finalModelClass,
                    'property' => 'name',
                    'kind' => 'attribute',
                ],
            ],
            'target' => [
                'model' => $finalModelClass,
                'property' => 'name',
                'kind' => 'attribute',
            ],
            'strategy' => 'null_attribute',
        ];
    }
}
