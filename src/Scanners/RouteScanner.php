<?php

namespace Natan\NullSafetyTestGenerator\Scanners;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

class RouteScanner
{
    public function __construct(
        private Router $router
    ) {
    }

    public function find(
        string $controllerClass,
        string $controllerMethod
    ): ?array {
        $expectedAction = ltrim($controllerClass, '\\')
            . '@' . $controllerMethod;

        foreach ($this->router->getRoutes() as $route) {
            if ($this->getActionName($route) !== $expectedAction) {
                continue;
            }

            $httpMethod = $this->getPrimaryHttpMethod($route);

            if ($httpMethod === null) {
                continue;
            }

            return [
                'name' => $route->getName(),
                'method' => $httpMethod,
                'parameters' => $this->getParameters($route),
            ];
        }

        return null;
    }

    public function allGetControllerRoutes(): array
    {
        $routes = [];

        foreach ($this->router->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $controllerAction = $this->getControllerAction($route);

            if ($controllerAction === null) {
                continue;
            }

            $routes[] = [
                'name' => $route->getName(),
                'method' => 'GET',
                'parameters' => $this->getParameters($route),
                'controller' => $controllerAction['controller'],
                'controllerMethod' => $controllerAction['method'],
            ];
        }

        return $routes;
    }

    private function getActionName(Route $route): string
    {
        return ltrim($route->getActionName(), '\\');
    }

    private function getPrimaryHttpMethod(Route $route): ?string
    {
        foreach ($route->methods() as $method) {
            $method = strtoupper($method);

            if ($method !== 'HEAD') {
                return $method;
            }
        }

        return null;
    }

    private function getControllerAction(Route $route): ?array
    {
        $action = $route->getAction('controller');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }

        [$controller, $method] = explode('@', $action, 2);
        $controller = ltrim($controller, '\\');

        if ($controller === '' || $method === '') {
            return null;
        }

        return [
            'controller' => $controller,
            'method' => $method,
        ];
    }

    private function getParameters(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameterName) {
            $parameters[$parameterName] = $parameterName;
        }

        return $parameters;
    }
}
