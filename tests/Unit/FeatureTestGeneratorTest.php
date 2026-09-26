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
    $this->assertNotSame(404, $response->status());
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

    public function test_it_uses_a_camel_case_variable_for_a_snake_case_route_key(): void
    {
        $target = [
            'model' => FakePost::class,
            'property' => 'title',
            'kind' => 'attribute',
        ];

        $result = (new FeatureTestGenerator(
            new FactoryTestGenerator()
        ))->generate(
            [
                'root' => 'redacaoFinal',
                'rootClass' => FakePost::class,
                'rootType' => 'object',
                'path' => ['title'],
                'resolvedPath' => [$target],
                'target' => $target,
                'strategy' => 'null_attribute',
            ],
            [
                'name' => 'redacao_final.show',
                'method' => 'GET',
                'parameters' => [
                    'redacao_final' => 'redacaoFinal',
                ],
                'parameterModels' => [
                    'redacao_final' => [
                        'variable' => 'redacaoFinal',
                        'class' => RoutePost::class,
                        'type' => 'model',
                    ],
                ],
            ]
        );

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            '$redacaoFinal = \\' . FakePost::class
                . '::factory()->create([',
            $result['code']
        );
        $this->assertStringContainsString(
            "route('redacao_final.show', ['redacao_final' => \$redacaoFinal])",
            $result['code']
        );
        $this->assertStringNotContainsString(
            "\$redacao_final",
            $result['code']
        );
    }

    public function test_it_passes_a_model_key_loaded_from_the_request_as_a_query_parameter(): void
    {
        $target = [
            'model' => FakePost::class,
            'property' => 'title',
            'kind' => 'attribute',
        ];

        $result = (new FeatureTestGenerator(
            new FactoryTestGenerator()
        ))->generate([
            'root' => 'post',
            'rootClass' => FakePost::class,
            'rootType' => 'object',
            'path' => ['title'],
            'resolvedPath' => [$target],
            'target' => $target,
            'strategy' => 'null_attribute',
            'input' => [
                'source' => 'request',
                'parameter' => 'post_id',
                'valueFrom' => 'model_key',
            ],
        ], [
            'name' => 'posts.create',
            'method' => 'GET',
            'parameters' => [],
        ]);

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            "route('posts.create', ['post_id' => \$post->getKey()])",
            $result['code']
        );
    }

    public function test_it_generates_a_test_for_a_missing_request_parameter(): void
    {
        $result = (new FeatureTestGenerator(
            new FactoryTestGenerator()
        ))->generate([
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
        ], [
            'name' => 'posts.create',
            'method' => 'GET',
            'parameters' => [],
        ]);

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            'test_posts_create_does_not_fail_when_post_id_is_missing',
            $result['code']
        );
        $this->assertStringContainsString(
            '// The post_id request parameter is intentionally omitted.',
            $result['code']
        );
        $this->assertStringContainsString(
            "route('posts.create')",
            $result['code']
        );
        $this->assertStringNotContainsString('::factory()', $result['code']);
        $this->assertStringNotContainsString("'post_id' =>", $result['code']);
        $this->assertStringContainsString(
            '$this->assertSame(404, $response->status());',
            $result['code']
        );
        $this->assertStringNotContainsString(
            '$this->assertLessThan(500, $response->status());',
            $result['code']
        );
    }

    public function test_it_uses_the_configured_status_for_a_missing_request_parameter(): void
    {
        $result = (new FeatureTestGenerator(
            new FactoryTestGenerator(),
            422
        ))->generate([
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
        ], [
            'name' => 'posts.create',
            'method' => 'GET',
            'parameters' => [],
        ]);

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            '$this->assertSame(422, $response->status());',
            $result['code']
        );
    }

    public function test_it_generates_a_feature_test_for_an_empty_paginated_collection(): void
    {
        $result = (new FeatureTestGenerator(
            new FactoryTestGenerator()
        ))->generate([
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
        ], [
            'name' => 'posts.index',
            'method' => 'GET',
            'parameters' => [],
        ]);

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            'test_posts_index_does_not_fail_when_posts_is_empty',
            $result['code']
        );
        $this->assertStringNotContainsString(
            '::factory()',
            $result['code']
        );
        $this->assertStringContainsString(
            "route('posts.index')",
            $result['code']
        );
        $this->assertStringContainsString(
            '$this->assertLessThan(500, $response->status());',
            $result['code']
        );
        $this->assertStringContainsString(
            '$this->assertNotSame(404, $response->status());',
            $result['code']
        );
    }
}
