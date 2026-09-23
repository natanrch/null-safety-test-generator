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
}
