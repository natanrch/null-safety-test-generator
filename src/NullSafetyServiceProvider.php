<?php

namespace Natan\NullSafetyTestGenerator;

use Illuminate\Support\ServiceProvider;
use Natan\NullSafetyTestGenerator\Console\GenerateNullSafetyTestsCommand;
use Natan\NullSafetyTestGenerator\Resolvers\ViewPathResolver;

class NullSafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ViewPathResolver::class,
            fn ($app): ViewPathResolver => new ViewPathResolver(
                $app->make('view.finder')
            )
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateNullSafetyTestsCommand::class,
            ]);
        }
    }
}
