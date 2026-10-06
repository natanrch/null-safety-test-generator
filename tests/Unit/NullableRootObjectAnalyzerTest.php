<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\NullableRootObjectAnalyzer;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use PHPUnit\Framework\TestCase;

class NullableRootObjectAnalyzerTest extends TestCase
{
    public function test_it_identifies_a_dereferenced_object_loaded_with_first(): void
    {
        $result = (new NullableRootObjectAnalyzer())->analyze(
            JsonPostController::class,
            'collectionUsingNullableRoot'
        );

        $this->assertSame([[
            'root' => 'author',
            'class' => Author::class,
            'type' => 'object',
            'accesses' => [],
            'resolvedAccesses' => [],
            'nullableRoot' => true,
            'retrievalMethod' => 'first',
        ]], $result);
    }

    public function test_it_ignores_an_object_loaded_with_first_or_fail(): void
    {
        $result = (new NullableRootObjectAnalyzer())->analyze(
            JsonPostController::class,
            'collectionUsingRequiredRoot'
        );

        $this->assertSame([], $result);
    }

    public function test_it_identifies_a_dereferenced_object_loaded_with_find(): void
    {
        $result = (new NullableRootObjectAnalyzer())->analyze(
            JsonPostController::class,
            'collectionUsingFindRoot'
        );

        $this->assertSame('author', $result[0]['root']);
        $this->assertSame(Author::class, $result[0]['class']);
        $this->assertSame('find', $result[0]['retrievalMethod']);
        $this->assertTrue($result[0]['nullableRoot']);
    }

    public function test_it_identifies_a_dereferenced_object_loaded_with_first_where(): void
    {
        $result = (new NullableRootObjectAnalyzer())->analyze(
            JsonPostController::class,
            'collectionUsingFirstWhereRoot'
        );

        $this->assertSame('author', $result[0]['root']);
        $this->assertSame('firstWhere', $result[0]['retrievalMethod']);
    }

    public function test_it_ignores_a_nullable_object_that_is_not_dereferenced(): void
    {
        $result = (new NullableRootObjectAnalyzer())->analyze(
            JsonPostController::class,
            'collectionWithUnusedNullableRoot'
        );

        $this->assertSame([], $result);
    }
}
