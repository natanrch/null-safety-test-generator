<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Illuminate\Http\Request;
use Natan\NullSafetyTestGenerator\Analyzers\WriteControllerAnalyzer;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use PHPUnit\Framework\TestCase;

class WriteControllerAnalyzerTest extends TestCase
{
    public function test_it_analyzes_a_simple_write_controller_request(): void
    {
        $result = (new WriteControllerAnalyzer())->analyze(
            PostController::class,
            'store'
        );

        $this->assertSame(PostController::class, $result['controller']);
        $this->assertSame('store', $result['method']);
        $this->assertSame([
            'variable' => 'request',
            'class' => Request::class,
        ], $result['request']);
        $this->assertSame([], $result['payload']);
    }

    public function test_it_returns_empty_for_an_invalid_controller_method(): void
    {
        $this->assertSame([], (new WriteControllerAnalyzer())->analyze(
            PostController::class,
            'missing'
        ));
    }

    public function test_it_includes_the_validated_payload(): void
    {
        $result = (new WriteControllerAnalyzer())->analyze(
            PostController::class,
            'storeInline'
        );

        $this->assertSame('test', $result['payload']['title']);
        $this->assertTrue($result['payload']['active']);
        $this->assertSame('draft', $result['payload']['status']);
        $this->assertSame(
            ['required', 'string', 'max:255'],
            $result['validation']['title']['rules']
        );
    }
}
