<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Resolvers\ViewPathResolver;
use Natan\NullSafetyTestGenerator\Console\GenerateNullSafetyTestsCommand;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
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
}
