<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Resolvers\RouteParameterResolver;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use PHPUnit\Framework\TestCase;

class RouteParameterResolverTest extends TestCase
{
    public function test_it_resolves_a_typed_route_parameter_to_an_eloquent_model(): void
    {
        $result = (new RouteParameterResolver())->resolve(
            PostController::class,
            'show',
            ['post']
        );

        $this->assertSame([
            'post' => [
                'variable' => 'post',
                'class' => Post::class,
                'type' => 'model',
            ],
        ], $result);
    }

    public function test_it_ignores_controller_parameters_not_present_in_the_route(): void
    {
        $result = (new RouteParameterResolver())->resolve(
            PostController::class,
            'show',
            ['unrelated']
        );

        $this->assertSame([], $result);
    }

    public function test_it_maps_a_snake_case_route_parameter_to_a_camel_case_variable(): void
    {
        $result = (new RouteParameterResolver())->resolve(
            PostController::class,
            'showRedacaoFinal',
            ['redacao_final']
        );

        $this->assertSame([
            'redacao_final' => [
                'variable' => 'redacaoFinal',
                'class' => Post::class,
                'type' => 'model',
            ],
        ], $result);
    }
}
