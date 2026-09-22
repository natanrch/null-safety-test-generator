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

    private function getParameters(Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameterName) {
            $parameters[$parameterName] = $parameterName;
        }

        return $parameters;
    }
}
