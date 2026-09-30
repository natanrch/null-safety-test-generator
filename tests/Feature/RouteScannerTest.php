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

    public function test_it_resolves_scalar_route_parameters(): void
    {
        $router = $this->app->make(Router::class);
        $router->get(
            '/archive/{year}/{slug}',
            [PostController::class, 'archive']
        )->name('posts.archive');

        $result = (new RouteScanner($router))->find(
            PostController::class,
            'archive'
        );

        $this->assertSame([
            'year' => ['variable' => 'year', 'value' => 1],
            'slug' => ['variable' => 'slug', 'value' => 'test'],
        ], $result['parameterValues']);
    }

    public function test_it_lists_post_put_and_patch_controller_routes(): void
    {
        $router = $this->app->make(Router::class);
        $router->post('/posts', [PostController::class, 'store'])
            ->name('posts.store');
        $router->put('/posts/{post}', [PostController::class, 'update'])
            ->name('posts.update');
        $router->patch('/posts/{post}', [PostController::class, 'patch'])
            ->name('posts.patch');

        $routes = (new RouteScanner($router))->allWriteControllerRoutes();

        $this->assertSame(
            ['POST', 'PUT', 'PATCH'],
            array_column($routes, 'method')
        );
        $this->assertSame(
            ['posts.store', 'posts.update', 'posts.patch'],
            array_column($routes, 'name')
        );
        $this->assertSame(Post::class, $routes[1]['parameterModels']['post']['class']);
    }
}
