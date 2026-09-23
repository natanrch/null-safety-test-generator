<?php

namespace Natan\NullSafetyTestGenerator\Resolvers;

use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class RouteParameterResolver
{
    public function resolve(
        string $controllerClass,
        string $controllerMethod,
        array $routeParameters
    ): array {
        try {
            $method = new ReflectionMethod(
                $controllerClass,
                $controllerMethod
            );
        } catch (Throwable) {
            return [];
        }

        $routeParameterNames = array_fill_keys(
            array_values($routeParameters),
            true
        );
        $resolved = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (
                ! isset($routeParameterNames[$name])
                || ! $type instanceof ReflectionNamedType
                || $type->isBuiltin()
            ) {
                continue;
            }

            $className = $type->getName();

            if (! is_subclass_of($className, Model::class)) {
                continue;
            }

            $resolved[$name] = [
                'variable' => $name,
                'class' => $className,
                'type' => 'model',
            ];
        }

        return $resolved;
    }
}
