<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;

class ControllerViewAnalyzer
{
    public function __construct(
        private ControllerMethodAnalyzer $methodAnalyzer
    ) 
    {

    }

    public function analyze(
        string $controllerClass,
        string $method
    ): array 
    {
        $viewCall = $this->findViewCall($controllerClass, $method);

        if ($viewCall === null) {
            return [];
        }

        $viewName = $this->getViewName($viewCall);

        if ($viewName === null) {
            return [];
        }

        $analyzedVariables = $this->methodAnalyzer->getObjectClasses(
            $controllerClass,
            $method
        );

        return [
            'view' => $viewName,
            'variables' => $this->getViewVariables(
                $viewCall,
                $analyzedVariables
            ),
        ];
    }

    private function findViewCall(
        string $controllerClass,
        string $method
    ): ?Node\Expr\FuncCall 
    {
        $reflectionMethod = new ReflectionMethod(
            $controllerClass,
            $method
        );

        $fileName = $reflectionMethod->getFileName();

        if ($fileName === false) {
            return null;
        }

        $code = file_get_contents($fileName);

        if ($code === false) {
            return null;
        }

        $parser = (new ParserFactory())
            ->createForNewestSupportedVersion();

        $ast = $parser->parse($code);

        if ($ast === null) {
            return null;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $ast = $traverser->traverse($ast);

        $nodeFinder = new NodeFinder();

        $classMethod = $nodeFinder->findFirst(
            $ast,
            fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $method
        );

        if (! $classMethod instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        $return = $nodeFinder->findFirstInstanceOf(
            $classMethod->stmts ?? [],
            Node\Stmt\Return_::class
        );

        if (! $return instanceof Node\Stmt\Return_) {
            return null;
        }

        if (! $return->expr instanceof Node\Expr\FuncCall) {
            return null;
        }

        if (! $return->expr->name instanceof Node\Name) {
            return null;
        }

        if ($return->expr->name->toString() !== 'view') {
            return null;
        }

        return $return->expr;
    }

    private function getViewName(
        Node\Expr\FuncCall $viewCall
    ): ?string 
    {
        $argument = $viewCall->args[0] ?? null;

        if (
            $argument === null
            || ! $argument->value instanceof Node\Scalar\String_
        ) {
            return null;
        }

        return $argument->value->value;
    }

    private function getViewVariables(
        Node\Expr\FuncCall $viewCall,
        array $analyzedVariables
    ): array 
    {
        $argument = $viewCall->args[1] ?? null;

        if (
            $argument === null
            || ! $argument->value instanceof Node\Expr\Array_
        ) {
            return [];
        }

        $viewVariables = [];

        foreach ($argument->value->items as $item) {
            if ($item === null) {
                continue;
            }

            if (! $item->key instanceof Node\Scalar\String_) {
                continue;
            }

            if (! $item->value instanceof Node\Expr\Variable) {
                continue;
            }

            if (! is_string($item->value->name)) {
                continue;
            }

            $variableName = $item->value->name;

            if (! array_key_exists($variableName, $analyzedVariables)) {
                continue;
            }

            $viewVariables[$item->key->value] =
                $analyzedVariables[$variableName];
        }

        return $viewVariables;
    }
}
