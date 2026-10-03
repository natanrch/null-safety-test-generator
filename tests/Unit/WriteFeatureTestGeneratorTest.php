<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\FactoryTestGenerator;
use Natan\NullSafetyTestGenerator\Generators\WriteFeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Category;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\CategoryWithoutFactory;
use PHPUnit\Framework\TestCase;

class WriteFeatureTestGeneratorTest extends TestCase
{
    private WriteFeatureTestGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new WriteFeatureTestGenerator(
            new FactoryTestGenerator()
        );
    }

    public function test_it_generates_a_post_request_test(): void
    {
        $result = $this->generator->generate(
            ['payload' => []],
            [
                'name' => 'posts.store',
                'method' => 'POST',
                'parameters' => [],
            ]
        );

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            '$response = $this->post(',
            $result['code']
        );
        $this->assertStringContainsString("route('posts.store')", $result['code']);
        $this->assertStringContainsString("        []\n", $result['code']);
    }

    public function test_it_generates_a_put_request_with_model_binding(): void
    {
        $result = $this->generator->generate(
            ['payload' => []],
            $this->modelRoute('PUT', 'posts.update')
        );

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            '$post = \\' . Post::class . '::factory()->create();',
            $result['code']
        );
        $this->assertStringContainsString('$this->put(', $result['code']);
        $this->assertStringContainsString(
            "route('posts.update', ['post' => \$post])",
            $result['code']
        );
    }

    public function test_it_generates_a_patch_request_with_scalar_route_parameter(): void
    {
        $result = $this->generator->generate(
            ['payload' => []],
            [
                'name' => 'reports.patch',
                'method' => 'PATCH',
                'parameters' => ['year' => 'year'],
                'parameterValues' => [
                    'year' => ['variable' => 'year', 'value' => 2026],
                ],
            ]
        );

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString('$year = 2026;', $result['code']);
        $this->assertStringContainsString('$this->patch(', $result['code']);
    }

    public function test_it_generates_a_delete_request_with_model_binding_and_payload(): void
    {
        $result = $this->generator->generate(
            ['payload' => ['reason' => 'test']],
            $this->modelRoute('DELETE', 'posts.destroy')
        );

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            'test_posts_destroy_does_not_return_a_server_error_for_delete_request',
            $result['code']
        );
        $this->assertStringContainsString(
            '$post = \\' . Post::class . '::factory()->create();',
            $result['code']
        );
        $this->assertStringContainsString('$this->delete(', $result['code']);
        $this->assertStringContainsString(
            "route('posts.destroy', ['post' => \$post])",
            $result['code']
        );
        $this->assertStringContainsString(
            "['reason' => 'test']",
            $result['code']
        );
        $this->assertStringContainsString(
            '$this->assertLessThan(500, $response->status());',
            $result['code']
        );
    }

    public function test_it_rejects_get_routes(): void
    {
        $result = $this->generator->generate(
            ['payload' => []],
            ['name' => 'posts.index', 'method' => 'GET']
        );

        $this->assertFalse($result['generated']);
    }

    public function test_it_sends_the_analyzed_validation_payload(): void
    {
        $result = $this->generator->generate(
            [
                'payload' => [
                    'title' => 'test',
                    'active' => true,
                ],
            ],
            [
                'name' => 'posts.store',
                'method' => 'POST',
                'parameters' => [],
            ]
        );

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            "['title' => 'test', 'active' => true]",
            $result['code']
        );
    }

    public function test_it_creates_an_exists_rule_record_with_its_factory(): void
    {
        $result = $this->generator->generate([
            'payload' => ['category_id' => 1],
            'dependencies' => [
                'category_id' => [
                    'model' => Category::class,
                    'variable' => 'category',
                    'column' => 'id',
                    'nullableProperties' => ['description'],
                ],
            ],
        ], [
            'name' => 'posts.store',
            'method' => 'POST',
            'parameters' => [],
        ]);

        $this->assertTrue($result['generated']);
        $this->assertStringContainsString(
            '$category = \\' . Category::class . "::factory()->create(['description' => null]);",
            $result['code']
        );
        $this->assertStringContainsString(
            "['category_id' => \$category->id]",
            $result['code']
        );
    }

    public function test_it_does_not_generate_when_an_exists_model_has_no_factory(): void
    {
        $result = $this->generator->generate([
            'payload' => ['category_id' => 1],
            'dependencies' => [
                'category_id' => [
                    'model' => CategoryWithoutFactory::class,
                    'variable' => 'category',
                    'column' => 'id',
                    'nullableProperties' => [],
                ],
            ],
        ], [
            'name' => 'posts.store',
            'method' => 'POST',
            'parameters' => [],
        ]);

        $this->assertFalse($result['generated']);
        $this->assertSame(
            'Factory for model ' . CategoryWithoutFactory::class
                . ' does not exist; the test could not be generated.',
            $result['message']
        );
    }

    private function modelRoute(string $method, string $name): array
    {
        return [
            'name' => $name,
            'method' => $method,
            'parameters' => ['post' => 'post'],
            'parameterModels' => [
                'post' => [
                    'variable' => 'post',
                    'class' => Post::class,
                    'type' => 'model',
                ],
            ],
        ];
    }
}
