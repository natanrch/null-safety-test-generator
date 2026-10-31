<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\JsonPostController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class JsonSerializableObjectGenerationTest extends TestCase
{
    #[DataProvider('methods')]
    public function test_it_generates_tests_for_serializable_objects(
        string $method
    ): void {
        $result = $this->app
            ->make(NullSafetyTestGenerationService::class)
            ->generateMethods(
                JsonPostController::class,
                $method,
                [
                    'name' => 'json.' . $method,
                    'method' => 'GET',
                    'parameters' => ['post' => 'post'],
                ]
            );

        $this->assertTrue($result['generated']);
        $code = implode("\n", $result['testMethods']);
        $this->assertStringContainsString('$this->getJson(', $code);
        $this->assertStringContainsString(
            'when_post_author_is_null',
            $code
        );
        $this->assertStringContainsString(
            'when_post_author_profile_is_null',
            $code
        );
    }

    public static function methods(): iterable
    {
        yield 'plain DTO' => ['dto'];
        yield 'JsonSerializable' => ['jsonSerializable'];
        yield 'Arrayable' => ['arrayable'];
        yield 'direct Arrayable response' => ['directArrayable'];
    }
}
