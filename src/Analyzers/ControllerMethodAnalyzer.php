<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use ReflectionNamedType;

class ControllerMethodAnalyzer
{
    public function getObjectClasses(
        string $controllerClass,
        string $method
    ): array {
        return array_merge(
            $this->getParameterClasses($controllerClass, $method),
            $this->getLocalVariableClasses($controllerClass, $method)
        );
    }

    private function getParameterClasses(
        string $controllerClass,
        string $method
    ): array {
        $reflectionMethod = new ReflectionMethod(
            $controllerClass,
            $method
        );

        $classes = [];

        foreach ($reflectionMethod->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            if ($type->isBuiltin()) {
                continue;
            }

            $classes[$parameter->getName()] = $type->getName();
        }

        return $classes;
    }

    private function getLocalVariableClasses(
        string $controllerClass,
        string $method
    ): array {
        $reflectionMethod = new ReflectionMethod(
            $controllerClass,
            $method
        );

        $fileName = $reflectionMethod->getFileName();

        if ($fileName === false) {
            return [];
        }

        $code = file_get_contents($fileName);

        if ($code === false) {
            return [];
        }

        $parser = (new ParserFactory())
            ->createForNewestSupportedVersion();

        $ast = $parser->parse($code);

        if ($ast === null) {
            return [];
        }

        $traverser = new NodeTraverser();

        $traverser->addVisitor(
            new NameResolver()
        );

        $ast = $traverser->traverse($ast);

        $nodeFinder = new NodeFinder();

        $classMethod = $nodeFinder->findFirst(
            $ast,
            function (Node $node) use ($method) {
                return $node instanceof Node\Stmt\ClassMethod
                    && $node->name->toString() === $method;
            }
        );

        if (! $classMethod instanceof Node\Stmt\ClassMethod) {
            return [];
        }

        $assignments = $nodeFinder->findInstanceOf(
            $classMethod->stmts ?? [],
            Node\Expr\Assign::class
        );

        $classes = [];

        foreach ($assignments as $assignment) {
            if (! $assignment->var instanceof Node\Expr\Variable) {
                continue;
            }

            if (! is_string($assignment->var->name)) {
                continue;
            }

            $className = $this->getRootClassFromExpression(
                $assignment->expr
            );

            if ($className === null) {
                continue;
            }

            $classes[$assignment->var->name] = $className;
        }

        return $classes;
    }

    private function getRootClassFromExpression(
        Node\Expr $expr
    ): ?string {
        if ($expr instanceof Node\Expr\StaticCall) {
            if (! $expr->class instanceof Node\Name) {
                return null;
            }

            return $expr->class->toString();
        }

        if ($expr instanceof Node\Expr\MethodCall) {
            return $this->getRootClassFromExpression(
                $expr->var
            );
        }

        return null;
    }
}