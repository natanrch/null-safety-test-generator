<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Illuminate\Http\Request;
use Natan\NullSafetyTestGenerator\Generators\NullScenarioGenerator;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentRelationshipResolver;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class WriteControllerAnalyzer
{
    public function __construct(
        private ?RequestValidationAnalyzer $validationAnalyzer = null,
        private ?WriteControllerAccessAnalyzer $accessAnalyzer = null,
        private ?NullScenarioGenerator $scenarioGenerator = null
    ) {
        $this->validationAnalyzer ??= new RequestValidationAnalyzer();
        $this->accessAnalyzer ??= new WriteControllerAccessAnalyzer(
            new EloquentAccessChainResolver(
                new EloquentRelationshipResolver()
            )
        );
        $this->scenarioGenerator ??= new NullScenarioGenerator();
    }

    public function analyze(
        string $controllerClass,
        string $controllerMethod
    ): array {
        try {
            $method = new ReflectionMethod(
                $controllerClass,
                $controllerMethod
            );
        } catch (Throwable) {
            return [];
        }

        $request = null;

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (
                ! $type instanceof ReflectionNamedType
                || $type->isBuiltin()
            ) {
                continue;
            }

            $className = $type->getName();

            if (
                $className !== Request::class
                && ! is_subclass_of($className, Request::class)
            ) {
                continue;
            }

            $request = [
                'variable' => $parameter->getName(),
                'class' => $className,
            ];
            break;
        }

        $validation = $this->validationAnalyzer->analyze(
            $controllerClass,
            $controllerMethod
        );
        $accesses = $this->accessAnalyzer->analyze(
            $controllerClass,
            $controllerMethod
        );

        return [
            'controller' => ltrim($controllerClass, '\\'),
            'method' => $controllerMethod,
            'request' => $request,
            'validation' => $validation['fields'],
            'payload' => $validation['payload'],
            'dependencies' => $validation['dependencies'] ?? [],
            'accesses' => $accesses,
            'scenarios' => $this->scenarioGenerator->generate([
                'accesses' => $accesses,
            ]),
        ];
    }
}
