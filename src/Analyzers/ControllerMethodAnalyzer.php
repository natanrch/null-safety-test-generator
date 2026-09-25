<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use ReflectionNamedType;

class ControllerMethodAnalyzer
{
    private const OBJECT_RETURNING_METHODS = [
        'find',
        'findOrFail',
        'first',
        'firstOrFail',
        'sole',
        'create',
        'firstOrCreate',
        'firstOrNew',
    ];

    private const COLLECTION_RETURNING_METHODS = [
        'get',
        'all',
        'pluck',
        'paginate',
        'simplePaginate',
        'cursorPaginate',
    ];

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

            $classes[$parameter->getName()] = [
                'class' => $type->getName(),
                'type' => 'object',
            ];
        }

        return $classes;
    }

    private function getLocalVariableClasses(
        string $controllerClass,
        string $method
    ): array
    {
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
        $requestVariables = $this->getRequestParameterNames(
            $reflectionMethod
        );

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

            $resultType = $this->getExpressionResultType(
                $assignment->expr
            );

            if ($resultType === null) {
                continue;
            }

            $metadata = [
                'class' => $className,
                'type' => $resultType,
            ];

            $requestInput = $this->getRequestInput(
                $assignment->expr,
                $requestVariables
            );

            if ($requestInput !== null) {
                $metadata['input'] = $requestInput;
            }

            $classes[$assignment->var->name] = $metadata;
        }

        return $classes;
    }

    private function getRequestParameterNames(
        ReflectionMethod $method
    ): array {
        $requestParameters = [];

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
                $className === Request::class
                || is_subclass_of($className, Request::class)
            ) {
                $requestParameters[$parameter->getName()] = true;
            }
        }

        return $requestParameters;
    }

    private function getRequestInput(
        Node\Expr $expression,
        array $requestVariables
    ): ?array {
        if (
            ! $expression instanceof Node\Expr\StaticCall
            || ! $expression->name instanceof Node\Identifier
            || ! in_array(
                $expression->name->toString(),
                ['find', 'findOrFail'],
                true
            )
        ) {
            return null;
        }

        $argument = $expression->args[0]->value ?? null;

        if (
            ! $argument instanceof Node\Expr\PropertyFetch
            || ! $argument->var instanceof Node\Expr\Variable
            || ! is_string($argument->var->name)
            || ! isset($requestVariables[$argument->var->name])
            || ! $argument->name instanceof Node\Identifier
        ) {
            return null;
        }

        return [
            'source' => 'request',
            'parameter' => $argument->name->toString(),
            'valueFrom' => 'model_key',
        ];
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

    private function getExpressionResultType(
        Node\Expr $expr
    ): ?string {
        $method = $this->getLastCalledMethod($expr);

        if ($method === null) {
            return null;
        }

        if (in_array($method, self::OBJECT_RETURNING_METHODS, true)) {
            return 'object';
        }

        if (in_array($method, self::COLLECTION_RETURNING_METHODS, true)) {
            return 'collection';
        }

        return null;
    }

    private function getLastCalledMethod(
        Node\Expr $expr
    ): ?string {
        if (
            $expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
        ) {
            return $expr->name->toString();
        }

        if (
            $expr instanceof Node\Expr\StaticCall
            && $expr->name instanceof Node\Identifier
        ) {
            return $expr->name->toString();
        }

        return null;
    }
}
