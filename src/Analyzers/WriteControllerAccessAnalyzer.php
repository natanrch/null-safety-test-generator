<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Illuminate\Database\Eloquent\Model;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class WriteControllerAccessAnalyzer
{
    public function __construct(
        private EloquentAccessChainResolver $accessChainResolver
    ) {
    }

    public function analyze(string $controllerClass, string $methodName): array
    {
        try {
            $method = new ReflectionMethod($controllerClass, $methodName);
            $classMethod = $this->parseMethod($method, $methodName);
        } catch (Throwable) {
            return [];
        }

        if ($classMethod === null) {
            return [];
        }

        $aliases = $this->modelParameters($method);
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

            $access = $this->propertyAccess($assignment->expr, $aliases);

            if ($access !== null) {
                $aliases[$assignment->var->name] = $access;
            }
        }

        $results = [];

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

        return array_values($results);
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

    private function parseMethod(
        ReflectionMethod $method,
        string $methodName
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
                && $node->name->toString() === $methodName
        );

        return $node instanceof Node\Stmt\ClassMethod ? $node : null;
    }
}
