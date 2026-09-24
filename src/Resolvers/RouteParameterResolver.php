<?php

namespace Natan\NullSafetyTestGenerator\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
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

        $resolved = [];

        foreach ($routeParameters as $routeParameter) {
            if (! is_string($routeParameter)) {
                continue;
            }

            $parameter = $this->findControllerParameter(
                $method,
                $routeParameter
            );

            if ($parameter === null) {
                continue;
            }

            $variable = $parameter->getName();
            $type = $parameter->getType();

            if (
                ! $type instanceof ReflectionNamedType
                || $type->isBuiltin()
            ) {
                continue;
            }

            $className = $type->getName();

            if (! is_subclass_of($className, Model::class)) {
                continue;
            }

            $resolved[$routeParameter] = [
                'variable' => $variable,
                'class' => $className,
                'type' => 'model',
            ];
        }

        return $resolved;
    }

    private function findControllerParameter(
        ReflectionMethod $method,
        string $routeParameter
    ): ?\ReflectionParameter {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() === $routeParameter) {
                return $parameter;
            }
        }

        foreach ($method->getParameters() as $parameter) {
            if (
                Str::snake($parameter->getName())
                === Str::snake($routeParameter)
            ) {
                return $parameter;
            }
        }

        return null;
    }
}
