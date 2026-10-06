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

class ControllerDataDependencyAnalyzer
{
    private const MODEL_RETRIEVAL_METHODS = [
        'find',
        'findOrFail',
        'first',
        'firstOrFail',
        'firstWhere',
        'sole',
    ];

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

        $assignments = (new NodeFinder())->findInstanceOf(
            $methodNode->stmts ?? [],
            Node\Expr\Assign::class
        );
        usort(
            $assignments,
            static fn (Node\Expr\Assign $left, Node\Expr\Assign $right): int =>
                $left->getStartFilePos() <=> $right->getStartFilePos()
        );

        $modelSources = [];
        $dependencyGraph = [];

        foreach ($assignments as $assignment) {
            if (
                ! $assignment->var instanceof Node\Expr\Variable
                || ! is_string($assignment->var->name)
            ) {
                continue;
            }

            $variable = $assignment->var->name;
            $modelSource = $this->modelSource($variable, $assignment->expr);

            if ($modelSource !== null) {
                $modelSources[$variable] = $modelSource;
            }

            $dependencies = [];

            foreach ($this->expressionVariables($assignment->expr) as $dependency) {
                if ($dependency === $variable) {
                    foreach ($dependencyGraph[$variable] ?? [] as $previous) {
                        $dependencies[$previous] = true;
                    }

                    continue;
                }

                $dependencies[$dependency] = true;
            }

            $dependencyGraph[$variable] = array_keys($dependencies);
        }

        $preconditionsByVariable = [];

        foreach (array_keys($dependencyGraph) as $variable) {
            $preconditions = $this->resolvePreconditions(
                $variable,
                $dependencyGraph,
                $modelSources,
                []
            );

            if ($preconditions !== []) {
                $preconditionsByVariable[$variable] = array_values(
                    $preconditions
                );
            }
        }

        return $preconditionsByVariable;
    }

    private function modelSource(
        string $variable,
        Node\Expr $expression
    ): ?array {
        $retrievalMethod = $this->lastCalledMethod($expression);
        $modelClass = $this->rootClass($expression);

        if (
            ! in_array(
                $retrievalMethod,
                self::MODEL_RETRIEVAL_METHODS,
                true
            )
            || $modelClass === null
            || ! class_exists($modelClass)
            || ! is_subclass_of($modelClass, Model::class)
        ) {
            return null;
        }

        $constraints = $this->literalWhereConstraints($expression);

        if (in_array($retrievalMethod, ['find', 'findOrFail'], true)) {
            $keyConstraint = $this->literalFindConstraint(
                $modelClass,
                $expression
            );

            if ($keyConstraint !== []) {
                $constraints = [...$constraints, ...$keyConstraint];
            }
        }

        return [
            'root' => $variable,
            'class' => $modelClass,
            'retrievalMethod' => $retrievalMethod,
            'constraints' => $constraints,
        ];
    }

    private function expressionVariables(Node\Expr $expression): array
    {
        $variables = [];

        foreach ((new NodeFinder())->findInstanceOf(
            [$expression],
            Node\Expr\Variable::class
        ) as $variable) {
            if (is_string($variable->name)) {
                $variables[$variable->name] = true;
            }
        }

        return array_keys($variables);
    }

    private function resolvePreconditions(
        string $variable,
        array $dependencyGraph,
        array $modelSources,
        array $visited
    ): array {
        if (isset($visited[$variable])) {
            return [];
        }

        $visited[$variable] = true;
        $preconditions = [];

        foreach ($dependencyGraph[$variable] ?? [] as $dependency) {
            if (isset($modelSources[$dependency])) {
                $preconditions[$dependency] = $modelSources[$dependency];
            }

            foreach ($this->resolvePreconditions(
                $dependency,
                $dependencyGraph,
                $modelSources,
                $visited
            ) as $root => $precondition) {
                $preconditions[$root] = $precondition;
            }
        }

        return $preconditions;
    }

    private function literalWhereConstraints(Node\Expr $expression): array
    {
        $constraints = [];
        $current = $expression;

        while (
            $current instanceof Node\Expr\MethodCall
            || $current instanceof Node\Expr\StaticCall
        ) {
            if (
                $current->name instanceof Node\Identifier
                && in_array(
                    $current->name->toString(),
                    ['where', 'firstWhere'],
                    true
                )
                && ($current->args[0]->value ?? null)
                    instanceof Node\Scalar\String_
            ) {
                $valueArgument = $this->whereValueArgument($current->args);
                $value = $valueArgument instanceof Node\Expr
                    ? $this->literalValue($valueArgument)
                    : ['resolved' => false, 'value' => null];

                if ($value['resolved']) {
                    $constraints[$current->args[0]->value->value]
                        = $value['value'];
                }
            }

            $current = $current instanceof Node\Expr\MethodCall
                ? $current->var
                : null;
        }

        return array_reverse($constraints, true);
    }

    private function literalFindConstraint(
        string $modelClass,
        Node\Expr $expression
    ): array {
        if (
            ! ($expression instanceof Node\Expr\MethodCall
                || $expression instanceof Node\Expr\StaticCall)
            || ! isset($expression->args[0])
            || ! $expression->args[0]->value instanceof Node\Expr
        ) {
            return [];
        }

        $value = $this->literalValue($expression->args[0]->value);

        if (! $value['resolved']) {
            return [];
        }

        try {
            /** @var Model $model */
            $model = new $modelClass();

            return [$model->getKeyName() => $value['value']];
        } catch (Throwable) {
            return [];
        }
    }

    private function whereValueArgument(array $arguments): ?Node\Expr
    {
        if (count($arguments) === 2) {
            return $arguments[1]->value;
        }

        if (
            count($arguments) >= 3
            && $arguments[1]->value instanceof Node\Scalar\String_
            && in_array($arguments[1]->value->value, ['=', '=='], true)
        ) {
            return $arguments[2]->value;
        }

        return null;
    }

    private function literalValue(Node\Expr $expression): array
    {
        if ($expression instanceof Node\Scalar\String_) {
            return ['resolved' => true, 'value' => $expression->value];
        }

        if ($expression instanceof Node\Scalar\Int_) {
            return ['resolved' => true, 'value' => $expression->value];
        }

        if ($expression instanceof Node\Scalar\Float_) {
            return ['resolved' => true, 'value' => $expression->value];
        }

        if ($expression instanceof Node\Expr\ConstFetch) {
            return match (strtolower($expression->name->toString())) {
                'true' => ['resolved' => true, 'value' => true],
                'false' => ['resolved' => true, 'value' => false],
                'null' => ['resolved' => true, 'value' => null],
                default => ['resolved' => false, 'value' => null],
            };
        }

        return ['resolved' => false, 'value' => null];
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
