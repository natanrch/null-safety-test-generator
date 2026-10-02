<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Generators\FactoryTestGenerator;
use Natan\NullSafetyTestGenerator\Inspectors\DatabaseColumnInspector;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\PostWithoutFactory;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Profile;
use Natan\NullSafetyTestGenerator\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class DatabaseColumnInspectorTest extends TestCase
{
    public function test_it_identifies_nullable_and_not_null_columns(): void
    {
        $inspector = new DatabaseColumnInspector();

        $this->assertSame([
            'inspected' => true,
            'exists' => true,
            'nullable' => true,
            'primary' => false,
            'table' => 'posts',
            'column' => 'title',
        ], $inspector->inspect(Post::class, 'title'));

        $this->assertSame([
            'inspected' => true,
            'exists' => true,
            'nullable' => false,
            'primary' => true,
            'table' => 'posts',
            'column' => 'id',
        ], $inspector->inspect(Post::class, 'id'));
    }

    public function test_it_reports_a_column_that_does_not_exist(): void
    {
        $result = (new DatabaseColumnInspector())->inspect(
            Post::class,
            'computed_name'
        );

        $this->assertTrue($result['inspected']);
        $this->assertFalse($result['exists']);
        $this->assertNull($result['nullable']);
    }

    public function test_it_discards_an_unresolved_property_that_is_not_a_column(): void
    {
        $result = (new FactoryTestGenerator(
            new DatabaseColumnInspector()
        ))->generate($this->scenarioFor('computed_name'));

        $this->assertSame([
            'generated' => false,
            'message' => 'Property posts.computed_name is not a database column and could not be resolved as a relationship; the scenario was skipped.',
        ], $result);
    }

    public function test_it_discards_a_null_scenario_for_a_not_null_column(): void
    {
        $result = (new FactoryTestGenerator(
            new DatabaseColumnInspector()
        ))->generate($this->scenarioFor('id'));

        $this->assertSame([
            'generated' => false,
            'message' => 'Property posts.id is the model primary key; the null scenario was skipped.',
        ], $result);
    }

    public function test_it_discards_a_custom_model_primary_key_without_inspecting_the_schema(): void
    {
        $model = new class extends Model
        {
            protected $table = 'posts';

            protected $primaryKey = 'external_key';
        };
        $inspector = new class extends DatabaseColumnInspector
        {
            public function inspect(string $modelClass, string $columnName): array
            {
                throw new \RuntimeException('The schema must not be inspected.');
            }
        };

        $result = (new FactoryTestGenerator($inspector))->generate(
            $this->scenarioFor('external_key', $model::class)
        );

        $this->assertFalse($result['generated']);
        $this->assertStringContainsString(
            'is the model primary key',
            $result['message']
        );
    }

    public function test_it_skips_a_real_model_scenario_when_nullability_cannot_be_inspected(): void
    {
        $inspector = new class extends DatabaseColumnInspector
        {
            public function inspect(string $modelClass, string $columnName): array
            {
                return [
                    'inspected' => false,
                    'exists' => false,
                    'nullable' => null,
                    'primary' => false,
                    'message' => 'Database unavailable.',
                ];
            }
        };

        $result = (new FactoryTestGenerator($inspector))->generate(
            $this->scenarioFor('title')
        );

        $this->assertSame([
            'generated' => false,
            'message' => 'Could not inspect whether posts.title accepts null; the scenario was skipped.',
        ], $result);
    }

    public function test_it_keeps_a_null_scenario_for_a_nullable_column(): void
    {
        $result = (new FactoryTestGenerator(
            new DatabaseColumnInspector()
        ))->generate($this->scenarioFor('title'));

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            "'title' => null",
            $result['code']
        );
    }

    public function test_it_discards_a_missing_belongs_to_with_a_not_null_foreign_key(): void
    {
        $target = [
            'model' => Profile::class,
            'property' => 'author',
            'kind' => 'relationship',
            'relation' => 'belongsTo',
            'relatedClass' => Author::class,
        ];

        $result = (new FactoryTestGenerator(
            new DatabaseColumnInspector()
        ))->generate([
            'root' => 'profile',
            'rootClass' => Profile::class,
            'rootType' => 'object',
            'path' => ['author'],
            'resolvedPath' => [$target],
            'target' => $target,
            'strategy' => 'missing_relationship',
        ]);

        $this->assertSame([
            'generated' => false,
            'message' => 'Column profiles.author_id does not accept null; the scenario was skipped.',
        ], $result);
    }

    public function test_it_does_not_use_an_existing_record_when_the_factory_is_missing(): void
    {
        Post::factory()->create();

        $result = (new FactoryTestGenerator(
            new DatabaseColumnInspector()
        ))->generate($this->scenarioFor(
            'title',
            PostWithoutFactory::class
        ));

        $this->assertSame([
            'generated' => false,
            'message' => sprintf(
                'Factory for model %s does not exist; the test could not be generated.',
                PostWithoutFactory::class
            ),
        ], $result);
    }

    private function scenarioFor(
        string $property,
        string $modelClass = Post::class
    ): array {
        $target = [
            'model' => $modelClass,
            'property' => $property,
            'kind' => 'attribute',
        ];

        return [
            'root' => 'post',
            'rootClass' => $modelClass,
            'rootType' => 'object',
            'path' => [$property],
            'resolvedPath' => [$target],
            'target' => $target,
            'strategy' => 'null_attribute',
        ];
    }
}
