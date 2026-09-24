<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Illuminate\Routing\Router;
use Natan\NullSafetyTestGenerator\Scanners\RouteScanner;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
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
            'parameterModels' => [
                'post' => [
                    'variable' => 'post',
                    'class' => Post::class,
                    'type' => 'model',
                ],
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
                'parameterModels' => [
                    'post' => [
                        'variable' => 'post',
                        'class' => Post::class,
                        'type' => 'model',
                    ],
                ],
            ],
        ], $scanner->allGetControllerRoutes());
    }

    public function test_it_uses_the_controller_variable_for_a_snake_case_route_parameter(): void
    {
        $router = $this->app->make(Router::class);
        $router->get(
            '/redacoes/{redacao_final}',
            [PostController::class, 'showRedacaoFinal']
        )->name('redacao_final.show');

        $result = (new RouteScanner($router))->find(
            PostController::class,
            'showRedacaoFinal'
        );

        $this->assertSame([
            'redacao_final' => 'redacaoFinal',
        ], $result['parameters']);
        $this->assertSame([
            'redacao_final' => [
                'variable' => 'redacaoFinal',
                'class' => Post::class,
                'type' => 'model',
            ],
        ], $result['parameterModels']);
    }
}
