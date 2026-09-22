<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\FactoryTestGenerator;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakeAuthor;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
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
}
