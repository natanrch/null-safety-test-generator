<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Generators\FactoryTestGenerator;
use Natan\NullSafetyTestGenerator\Inspectors\DatabaseColumnInspector;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Profile;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

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

    public function test_it_discards_a_null_scenario_for_a_not_null_column(): void
    {
        $result = (new FactoryTestGenerator(
            new DatabaseColumnInspector()
        ))->generate($this->scenarioFor('id'));

        $this->assertSame([
            'generated' => false,
            'message' => 'Column posts.id does not accept null; the scenario was skipped.',
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

    private function scenarioFor(string $property): array
    {
        $target = [
            'model' => Post::class,
            'property' => $property,
            'kind' => 'attribute',
        ];

        return [
            'root' => 'post',
            'rootClass' => Post::class,
            'rootType' => 'object',
            'path' => [$property],
            'resolvedPath' => [$target],
            'target' => $target,
            'strategy' => 'null_attribute',
        ];
    }
}
