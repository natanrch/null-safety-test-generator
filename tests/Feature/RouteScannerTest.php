<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Illuminate\Routing\Router;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class RouteScannerTest extends TestCase
{
    public function test_it_finds_a_get_route_for_a_controller_method(): void
    {
        $scanner = new RouteScanner(
            $this->app->make(Router::class)
        );

        $result = $scanner->find(
            PostController::class,
            'show'
        );

        $this->assertSame([
            'name' => 'posts.show',
            'method' => 'GET',
            'parameters' => [
                'post' => 'post',
            ],
        ], $result);
    }

    public function test_it_lists_only_get_routes_backed_by_controller_methods(): void
    {
        $router = $this->app->make(Router::class);

        $router->post(
            '/posts',
            [PostController::class, 'show']
        )->name('posts.store');

        $router->get('/health', fn () => ['status' => 'ok'])
            ->name('health');

        $scanner = new RouteScanner($router);

        $this->assertSame([
            [
                'name' => 'posts.show',
                'method' => 'GET',
                'parameters' => [
                    'post' => 'post',
                ],
                'controller' => PostController::class,
                'controllerMethod' => 'show',
            ],
        ], $scanner->allGetControllerRoutes());
    }
}
