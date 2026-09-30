<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\RequestValidationAnalyzer;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use PHPUnit\Framework\TestCase;

class RequestValidationAnalyzerTest extends TestCase
{
    public function test_it_analyzes_inline_request_validation(): void
    {
        $result = (new RequestValidationAnalyzer())->analyze(
            PostController::class,
            'storeInline'
        );

        $this->assertSame(
            ['required', 'string', 'max:255'],
            $result['fields']['title']['rules']
        );
        $this->assertSame('inline', $result['fields']['title']['source']);
        $this->assertSame([
            'title' => 'test',
            'active' => true,
            'status' => 'draft',
            'category_id' => 1,
            'tags' => [],
        ], $result['payload']);
    }

    public function test_it_analyzes_a_form_request(): void
    {
        $result = (new RequestValidationAnalyzer())->analyze(
            PostController::class,
            'storeFormRequest'
        );

        $this->assertSame('form_request', $result['fields']['title']['source']);
        $this->assertSame([
            'title' => 'test',
            'email' => 'test@example.com',
            'published_at' => '2026-01-01',
        ], $result['payload']);
    }

    public function test_it_analyzes_the_validator_facade(): void
    {
        $result = (new RequestValidationAnalyzer())->analyze(
            PostController::class,
            'storeWithValidator'
        );

        $this->assertSame('validator', $result['fields']['quantity']['source']);
        $this->assertSame([
            'quantity' => 1,
            'accepted' => 'yes',
            'identifier' => '00000000-0000-4000-8000-000000000001',
        ], $result['payload']);
    }

    public function test_it_returns_an_empty_payload_without_validation(): void
    {
        $result = (new RequestValidationAnalyzer())->analyze(
            PostController::class,
            'store'
        );

        $this->assertSame(['fields' => [], 'payload' => []], $result);
    }

    public function test_it_identifies_the_model_loaded_by_an_exists_rule(): void
    {
        $result = (new RequestValidationAnalyzer())->analyze(
            PostController::class,
            'storeWithExistingCategory'
        );

        $this->assertSame(
            \Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Category::class,
            $result['dependencies']['category_id']['model']
        );
        $this->assertSame('id', $result['dependencies']['category_id']['column']);
        $this->assertSame(
            ['description'],
            $result['dependencies']['category_id']['accessedProperties']
        );
    }
}
