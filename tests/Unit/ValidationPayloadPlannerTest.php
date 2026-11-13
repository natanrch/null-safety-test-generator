<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ValidationPayloadPlanner;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use PHPUnit\Framework\TestCase;

class ValidationPayloadPlannerTest extends TestCase
{
    public function test_it_plans_a_distinct_exists_collection_with_model_constraints(): void
    {
        class_exists(Post::class);

        $result = (new ValidationPayloadPlanner())->plan([
            'post_ids' => [
                'rules' => ['required', 'array', 'min:2'],
                'value' => [],
            ],
            'post_ids.*' => [
                'rules' => [
                    'required',
                    'integer',
                    'distinct',
                    [
                        'name' => 'exists',
                        'table' => 'posts',
                        'column' => 'id',
                        'supported' => true,
                        'constraints' => [[
                            'column' => 'author_id',
                            'source' => [
                                'type' => 'model_property',
                                'variable' => 'author',
                                'property' => 'id',
                            ],
                        ]],
                    ],
                ],
                'value' => 1,
            ],
        ], [
            'post_ids' => [],
            'post_ids.*' => 1,
        ], ['author']);

        $this->assertTrue($result['valid']);
        $this->assertSame(['post_ids' => []], $result['payload']);
        $this->assertSame(Post::class, $result['fixtures'][0]['model']);
        $this->assertSame(2, $result['fixtures'][0]['count']);
        $this->assertSame(
            '$postIdsRecords->modelKeys()',
            $result['expressions']['post_ids']
        );
    }

    public function test_it_builds_distinct_scalar_wildcard_values(): void
    {
        $result = (new ValidationPayloadPlanner())->plan([
            'numbers' => [
                'rules' => ['required', 'array', 'min:3'],
                'value' => [],
            ],
            'numbers.*' => [
                'rules' => ['required', 'integer', 'distinct'],
                'value' => 1,
            ],
        ], [
            'numbers' => [],
            'numbers.*' => 1,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame(['numbers' => [1, 2, 3]], $result['payload']);
        $this->assertSame([], $result['fixtures']);
    }

    public function test_it_plans_a_string_exists_rule_for_wildcard_items(): void
    {
        class_exists(Post::class);

        $result = (new ValidationPayloadPlanner())->plan([
            'post_ids' => [
                'rules' => ['required', 'array'],
                'value' => [],
            ],
            'post_ids.*' => [
                'rules' => ['required', 'integer', 'exists:posts,id'],
                'value' => 1,
            ],
        ], ['post_ids' => [], 'post_ids.*' => 1]);

        $this->assertTrue($result['valid']);
        $this->assertSame(Post::class, $result['fixtures'][0]['model']);
        $this->assertSame(1, $result['fixtures'][0]['count']);
    }

    public function test_it_rejects_constraints_using_unknown_model_variables(): void
    {
        class_exists(Post::class);

        $result = (new ValidationPayloadPlanner())->plan([
            'post_ids' => [
                'rules' => ['required', 'array'],
                'value' => [],
            ],
            'post_ids.*' => [
                'rules' => [[
                    'name' => 'exists',
                    'table' => 'posts',
                    'column' => 'id',
                    'supported' => true,
                    'constraints' => [[
                        'column' => 'author_id',
                        'source' => [
                            'type' => 'model_property',
                            'variable' => 'unknownAuthor',
                            'property' => 'id',
                        ],
                    ]],
                ]],
                'value' => 1,
            ],
        ], ['post_ids' => [], 'post_ids.*' => 1], ['author']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString(
            'unknown model variable',
            $result['message']
        );
    }

    public function test_it_rejects_unresolved_exists_constraints(): void
    {
        $result = (new ValidationPayloadPlanner())->plan([
            'post_ids' => [
                'rules' => ['required', 'array'],
                'value' => [],
            ],
            'post_ids.*' => [
                'rules' => [[
                    'name' => 'exists',
                    'table' => 'posts',
                    'column' => 'id',
                    'supported' => false,
                    'constraints' => [],
                ]],
                'value' => 1,
            ],
        ], ['post_ids' => [], 'post_ids.*' => 1]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString(
            'could not be resolved',
            $result['message']
        );
    }

    public function test_it_rejects_nested_wildcards_conservatively(): void
    {
        $result = (new ValidationPayloadPlanner())->plan([
            'groups.*.post_ids.*' => [
                'rules' => ['required', 'integer'],
                'value' => 1,
            ],
        ], ['groups.*.post_ids.*' => 1]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString(
            'is not supported',
            $result['message']
        );
    }
}
