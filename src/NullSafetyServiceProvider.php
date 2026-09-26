<?php

namespace Natan\NullSafetyTestGenerator;

use Illuminate\Support\ServiceProvider;
use Natan\NullSafetyTestGenerator\Console\GenerateNullSafetyTestsCommand;
use Natan\NullSafetyTestGenerator\Generators\FactoryTestGenerator;
use Natan\NullSafetyTestGenerator\Generators\FeatureTestGenerator;
use Natan\NullSafetyTestGenerator\Resolvers\ViewPathResolver;

class NullSafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/null-safety.php',
            'null-safety'
        );

        $this->app->singleton(
            ViewPathResolver::class,
            fn ($app): ViewPathResolver => new ViewPathResolver(
                $app->make('view.finder')
            )
        );

        $this->app->singleton(
            FeatureTestGenerator::class,
            function ($app): FeatureTestGenerator {
                $configuredStatus = $app['config']->get(
                    'null-safety.missing_parameter_status',
                    404
                );
                $status = filter_var(
                    $configuredStatus,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 100, 'max_range' => 599]]
                );

                return new FeatureTestGenerator(
                    $app->make(FactoryTestGenerator::class),
                    $status === false ? 404 : $status
                );
            }
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/null-safety.php'
                    => config_path('null-safety.php'),
            ], 'null-safety-config');

            $this->commands([
                GenerateNullSafetyTestsCommand::class,
            ]);
        }
    }
}
