<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\FactoryTestGenerator;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeAuthor;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post as RoutePost;
use PHPUnit\Framework\TestCase;

class FeatureTestGeneratorTest extends TestCase
{
    public function test_it_generates_a_complete_feature_test_method(): void
    {
        $generator = new FeatureTestGenerator(
            new FactoryTestGenerator()
        );

        $authorRelationship = [
            'model' => FakePost::class,
            'property' => 'author',
            'kind' => 'relationship',
            'relation' => 'belongsTo',
            'relatedClass' => FakeAuthor::class,
        ];

        $result = $generator->generate(
            [
                'root' => 'post',
                'rootClass' => FakePost::class,
                'rootType' => 'object',
                'path' => ['author'],
                'resolvedPath' => [$authorRelationship],
                'target' => $authorRelationship,
                'strategy' => 'missing_relationship',
            ],
            [
                'name' => 'posts.show',
                'method' => 'GET',
                'parameters' => [
                    'post' => 'post',
                ],
            ]
        );

        $expectedCode = sprintf(
            <<<'PHP'
public function test_posts_show_does_not_fail_when_post_author_is_null(): void
{
    $post = \%s::factory()->state(['author_id' => null])->create();

    $response = $this->get(
        route('posts.show', ['post' => $post])
    );

    $this->assertLessThan(500, $response->status());
}
PHP,
            FakePost::class
        );

        $this->assertSame([
            'generated' => true,
            'code' => $expectedCode,
        ], $result);
    }

    public function test_it_creates_a_factory_for_a_route_model_parameter(): void
    {
        $generator = new FeatureTestGenerator(
            new FactoryTestGenerator()
        );

        $result = $generator->generate(
            [
                'root' => 'author',
                'rootClass' => FakeAuthor::class,
                'rootType' => 'object',
                'path' => ['name'],
                'resolvedPath' => [[
                    'model' => FakeAuthor::class,
                    'property' => 'name',
                    'kind' => 'attribute',
                ]],
                'target' => [
                    'model' => FakeAuthor::class,
                    'property' => 'name',
                    'kind' => 'attribute',
                ],
                'strategy' => 'null_attribute',
            ],
            [
                'name' => 'posts.edit',
                'method' => 'GET',
                'parameters' => [
                    'post' => 'post',
                ],
                'parameterModels' => [
                    'post' => [
                        'variable' => 'post',
                        'class' => RoutePost::class,
                        'type' => 'model',
                    ],
                ],
            ]
        );

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            '$post = \\' . RoutePost::class
                . '::factory()->create();',
            $result['code']
        );
        $this->assertStringContainsString(
            '$author = \\' . FakeAuthor::class
                . "::factory()->create([\n        'name' => null,",
            $result['code']
        );
        $this->assertStringContainsString(
            "route('posts.edit', ['post' => \$post])",
            $result['code']
        );
    }
}
