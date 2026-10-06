<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

class NullableRootObjectAnalyzer
{
    private const NULLABLE_RETRIEVAL_METHODS = ['find', 'first'];

    public function analyze(
        string $controllerClass,
        string $methodName
    ): array {
        try {
            $method = new ReflectionMethod($controllerClass, $methodName);
            $methodNode = $this->parseMethod($method);
        } catch (Throwable) {
            return [];
        }

        if ($methodNode === null) {
            return [];
        }

        $finder = new NodeFinder();
        $candidates = [];

        foreach ($finder->findInstanceOf(
            $methodNode->stmts ?? [],
            Node\Expr\Assign::class
        ) as $assignment) {
            if (
                ! $assignment->var instanceof Node\Expr\Variable
                || ! is_string($assignment->var->name)
            ) {
                continue;
            }

            $retrievalMethod = $this->lastCalledMethod($assignment->expr);
            $modelClass = $this->rootClass($assignment->expr);

            if (
                ! in_array(
                    $retrievalMethod,
                    self::NULLABLE_RETRIEVAL_METHODS,
                    true
                )
                || $modelClass === null
                || ! class_exists($modelClass)
                || ! is_subclass_of($modelClass, Model::class)
            ) {
                continue;
            }

            $candidates[$assignment->var->name] = [
                'class' => $modelClass,
                'retrievalMethod' => $retrievalMethod,
            ];
        }

        $dereferenced = [];

        foreach ($finder->findInstanceOf(
            $methodNode->stmts ?? [],
            Node\Expr\PropertyFetch::class
        ) as $propertyFetch) {
            $root = $this->rootVariable($propertyFetch);

            if ($root !== null && isset($candidates[$root])) {
                $dereferenced[$root] = true;
            }
        }

        foreach ($finder->findInstanceOf(
            $methodNode->stmts ?? [],
            Node\Expr\MethodCall::class
        ) as $methodCall) {
            $root = $this->rootVariable($methodCall);

            if ($root !== null && isset($candidates[$root])) {
                $dereferenced[$root] = true;
            }
        }

        $accesses = [];

        foreach ($candidates as $variable => $candidate) {
            if (! isset($dereferenced[$variable])) {
                continue;
            }

            $accesses[] = [
                'root' => $variable,
                'class' => $candidate['class'],
                'type' => 'object',
                'accesses' => [],
                'resolvedAccesses' => [],
                'nullableRoot' => true,
                'retrievalMethod' => $candidate['retrievalMethod'],
            ];
        }

        return $accesses;
    }

    private function lastCalledMethod(Node\Expr $expression): ?string
    {
        if (
            ($expression instanceof Node\Expr\MethodCall
                || $expression instanceof Node\Expr\StaticCall)
            && $expression->name instanceof Node\Identifier
        ) {
            return $expression->name->toString();
        }

        return null;
    }

    private function rootClass(Node\Expr $expression): ?string
    {
        if (
            $expression instanceof Node\Expr\StaticCall
            && $expression->class instanceof Node\Name
        ) {
            return $expression->class->toString();
        }

        if ($expression instanceof Node\Expr\MethodCall) {
            return $this->rootClass($expression->var);
        }

        return null;
    }

    private function rootVariable(Node\Expr $expression): ?string
    {
        $current = $expression;

        while (
            $current instanceof Node\Expr\PropertyFetch
            || $current instanceof Node\Expr\MethodCall
        ) {
            $current = $current->var;
        }

        return $current instanceof Node\Expr\Variable
            && is_string($current->name)
                ? $current->name
                : null;
    }

    private function parseMethod(
        ReflectionMethod $method
    ): ?Node\Stmt\ClassMethod {
        $fileName = $method->getFileName();
        $code = $fileName === false ? false : file_get_contents($fileName);

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

        $methodNode = (new NodeFinder())->findFirst(
            $ast,
            fn (Node $node): bool =>
                $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $method->getName()
                && $node->getStartLine() === $method->getStartLine()
        );

        return $methodNode instanceof Node\Stmt\ClassMethod
            ? $methodNode
            : null;
    }
}
