<?php

namespace Natan\NullSafetyTestGenerator\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Natan\NullSafetyTestGenerator\NullSafetyServiceProvider;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            NullSafetyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(
            str_repeat('a', 32)
        ));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('view.paths', [
            __DIR__ . '/Fixtures/Laravel/views',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(
            __DIR__ . '/Fixtures/Laravel/database/migrations'
        );
    }

    protected function defineRoutes($router): void
    {
        $router->get(
            '/posts/{post}',
            [PostController::class, 'show']
        )
            ->middleware(SubstituteBindings::class)
            ->name('posts.show');
    }
}
