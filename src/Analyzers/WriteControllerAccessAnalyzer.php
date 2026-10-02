<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Illuminate\Database\Eloquent\Model;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/** @deprecated Use ModelPropagationAnalyzer. */
class WriteControllerAccessAnalyzer
{
    private const MAX_CALL_DEPTH = 8;

    public function __construct(
        private EloquentAccessChainResolver $accessChainResolver,
        private bool $includeEntryMethodAccesses = true
    ) {
    }

    public function analyze(string $controllerClass, string $methodName): array
    {
        try {
            $method = new ReflectionMethod($controllerClass, $methodName);
        } catch (Throwable) {
            return [];
        }

        $results = [];
        $visited = [];

        $this->analyzeMethod(
            $controllerClass,
            $methodName,
            $this->modelParameters($method),
            $results,
            $visited,
            0
        );

        return array_values($results);
    }

    private function analyzeMethod(
        string $className,
        string $methodName,
        array $aliases,
        array &$results,
        array &$visited,
        int $depth
    ): void {
        if ($depth > self::MAX_CALL_DEPTH || $aliases === []) {
            return;
        }

        try {
            $method = new ReflectionMethod($className, $methodName);
        } catch (Throwable) {
            return;
        }

        if (! $this->isApplicationMethod($method)) {
            return;
        }

        $visitKey = $className . '::' . $methodName . ':'
            . serialize($aliases);

        if (isset($visited[$visitKey])) {
            return;
        }

        $visited[$visitKey] = true;
        $classMethod = $this->parseMethod($method);

        if ($classMethod === null) {
            return;
        }

        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf(
            $classMethod->stmts ?? [],
            Node\Expr\Assign::class
        ) as $assignment) {
            if (
                ! $assignment->var instanceof Node\Expr\Variable
                || ! is_string($assignment->var->name)
            ) {
                continue;
            }

            $access = $this->trackedExpression(
                $assignment->expr,
                $aliases
            );

            if ($access !== null) {
                $aliases[$assignment->var->name] = $access;
            }
        }

        if ($depth > 0 || $this->includeEntryMethodAccesses) {
            foreach ($finder->findInstanceOf(
                $classMethod->stmts ?? [],
                Node\Expr\PropertyFetch::class
            ) as $propertyFetch) {
                $access = $this->propertyAccess($propertyFetch, $aliases);

                if ($access === null || $access['accesses'] === []) {
                    continue;
                }

                $access['resolvedAccesses'] = $this->accessChainResolver->resolve(
                    $access['class'],
                    $access['accesses']
                );
                $access['usage'] = 'function_argument';
                $results[serialize([
                    $access['root'],
                    $access['accesses'],
                ])] = $access;
            }
        }

        $objectVariables = $this->objectParameters($method);
        $objectProperties = $this->objectProperties($className);

        foreach ($finder->findInstanceOf(
            $classMethod->stmts ?? [],
            Node\Expr\MethodCall::class
        ) as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $targetClass = $this->calledObjectClass(
                $call->var,
                $className,
                $objectVariables,
                $objectProperties
            );

            if ($targetClass === null) {
                continue;
            }

            $targetMethod = $call->name->toString();

            try {
                $reflection = new ReflectionMethod(
                    $targetClass,
                    $targetMethod
                );
            } catch (Throwable) {
                continue;
            }

            $calledAliases = [];

            foreach ($reflection->getParameters() as $index => $parameter) {
                $argument = $call->args[$index]->value ?? null;

                if (! $argument instanceof Node\Expr) {
                    continue;
                }

                $tracked = $this->trackedExpression($argument, $aliases);

                if ($tracked !== null) {
                    $calledAliases[$parameter->getName()] = $tracked;
                }
            }

            if ($calledAliases === []) {
                continue;
            }

            $this->analyzeMethod(
                $targetClass,
                $targetMethod,
                $calledAliases,
                $results,
                $visited,
                $depth + 1
            );
        }
    }

    private function modelParameters(ReflectionMethod $method): array
    {
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (
                ! $type instanceof ReflectionNamedType
                || $type->isBuiltin()
                || ! is_subclass_of($type->getName(), Model::class)
            ) {
                continue;
            }

            $parameters[$parameter->getName()] = [
                'root' => $parameter->getName(),
                'class' => $type->getName(),
                'type' => 'object',
                'accesses' => [],
            ];
        }

        return $parameters;
    }

    private function objectParameters(ReflectionMethod $method): array
    {
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (
                ! $type instanceof ReflectionNamedType
                || $type->isBuiltin()
                || is_subclass_of($type->getName(), Model::class)
            ) {
                continue;
            }

            $parameters[$parameter->getName()] = $type->getName();
        }

        return $parameters;
    }

    private function objectProperties(string $className): array
    {
        try {
            $class = new ReflectionClass($className);
        } catch (Throwable) {
            return [];
        }

        $properties = [];

        foreach ($class->getProperties() as $property) {
            $type = $property->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $properties[$property->getName()] = $type->getName();
            }
        }

        if (! $class->hasMethod('__construct')) {
            return $properties;
        }

        $constructor = $class->getMethod('__construct');
        $constructorNode = $this->parseMethod($constructor);

        if ($constructorNode === null) {
            return $properties;
        }

        $parameterClasses = $this->objectParameters($constructor);

        foreach ((new NodeFinder())->findInstanceOf(
            $constructorNode->stmts ?? [],
            Node\Expr\Assign::class
        ) as $assignment) {
            if (
                ! $assignment->var instanceof Node\Expr\PropertyFetch
                || ! $assignment->var->var instanceof Node\Expr\Variable
                || $assignment->var->var->name !== 'this'
                || ! $assignment->var->name instanceof Node\Identifier
                || ! $assignment->expr instanceof Node\Expr\Variable
                || ! is_string($assignment->expr->name)
            ) {
                continue;
            }

            $parameterClass = $parameterClasses[$assignment->expr->name]
                ?? null;

            if ($parameterClass !== null) {
                $properties[$assignment->var->name->toString()]
                    = $parameterClass;
            }
        }

        return $properties;
    }

    private function calledObjectClass(
        Node\Expr $receiver,
        string $currentClass,
        array $objectVariables,
        array $objectProperties
    ): ?string {
        if ($receiver instanceof Node\Expr\Variable) {
            if ($receiver->name === 'this') {
                return $currentClass;
            }

            return is_string($receiver->name)
                ? ($objectVariables[$receiver->name] ?? null)
                : null;
        }

        if (
            $receiver instanceof Node\Expr\PropertyFetch
            && $receiver->var instanceof Node\Expr\Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Node\Identifier
        ) {
            return $objectProperties[$receiver->name->toString()] ?? null;
        }

        return null;
    }

    private function trackedExpression(
        Node\Expr $expression,
        array $aliases
    ): ?array {
        if (
            $expression instanceof Node\Expr\Variable
            && is_string($expression->name)
        ) {
            return $aliases[$expression->name] ?? null;
        }

        return $this->propertyAccess($expression, $aliases);
    }

    private function propertyAccess(
        Node\Expr $expression,
        array $aliases
    ): ?array {
        $properties = [];
        $current = $expression;

        while ($current instanceof Node\Expr\PropertyFetch) {
            if (! $current->name instanceof Node\Identifier) {
                return null;
            }

            array_unshift($properties, [
                'type' => 'property',
                'name' => $current->name->toString(),
            ]);
            $current = $current->var;
        }

        if (
            ! $current instanceof Node\Expr\Variable
            || ! is_string($current->name)
            || ! isset($aliases[$current->name])
        ) {
            return null;
        }

        $alias = $aliases[$current->name];

        return [
            'root' => $alias['root'],
            'class' => $alias['class'],
            'type' => 'object',
            'accesses' => [...$alias['accesses'], ...$properties],
        ];
    }

    private function isApplicationMethod(ReflectionMethod $method): bool
    {
        $file = $method->getFileName();

        return $file !== false
            && ! str_contains(
                str_replace('\\', '/', $file),
                '/vendor/'
            );
    }

    private function parseMethod(
        ReflectionMethod $method
    ): ?Node\Stmt\ClassMethod {
        $file = $method->getFileName();
        $code = $file === false ? false : file_get_contents($file);

        if ($code === false) {
            return null;
        }

        $ast = (new ParserFactory())
            ->createForNewestSupportedVersion()
            ->parse($code);

        if ($ast === null) {
            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $node = (new NodeFinder())->findFirst(
            $ast,
            fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $method->getName()
                && $node->getStartLine() === $method->getStartLine()
        );

        return $node instanceof Node\Stmt\ClassMethod ? $node : null;
    }
}
