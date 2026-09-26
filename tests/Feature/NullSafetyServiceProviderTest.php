<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Resolvers\ViewPathResolver;
use Natan\NullSafetyTestGenerator\Console\GenerateNullSafetyTestsCommand;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class NullSafetyServiceProviderTest extends TestCase
{
    public function test_it_registers_the_package_services_and_command(): void
    {
        $this->assertInstanceOf(
            ViewPathResolver::class,
            $this->app->make(ViewPathResolver::class)
        );

        $this->assertInstanceOf(
            NullSafetyTestGenerationService::class,
            $this->app->make(NullSafetyTestGenerationService::class)
        );

        $this->assertInstanceOf(
            GenerateNullSafetyTestsCommand::class,
            $this->app->make(GenerateNullSafetyTestsCommand::class)
        );
    }

    public function test_it_configures_the_missing_parameter_status_as_404_by_default(): void
    {
        $this->assertSame(
            404,
            config('null-safety.missing_parameter_status')
        );
        $this->assertInstanceOf(
            FeatureTestGenerator::class,
            $this->app->make(FeatureTestGenerator::class)
        );
    }

    public function test_it_injects_the_configured_missing_parameter_status(): void
    {
        config()->set('null-safety.missing_parameter_status', 422);
        $this->app->forgetInstance(FeatureTestGenerator::class);

        $result = $this->app->make(FeatureTestGenerator::class)->generate([
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
}
