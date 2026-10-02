<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Illuminate\Database\Eloquent\Model;
use Natan\NullSafetyTestGenerator\Resolvers\EloquentAccessChainResolver;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
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
                $parent = $propertyFetch->getAttribute('parent');

                if (
                    $parent instanceof Node\Expr\PropertyFetch
                    || $parent instanceof Node\Expr\NullsafePropertyFetch
                ) {
                    continue;
                }

                if ($this->isProtectedAccess($propertyFetch, $aliases)) {
                    continue;
                }

                $access = $this->propertyAccess($propertyFetch, $aliases);

                if ($access === null || $access['accesses'] === []) {
                    continue;
                }

                $access['resolvedAccesses'] = $this->accessChainResolver->resolve(
                    $access['class'],
                    $access['accesses']
                );
                $usage = $this->propertyUsage($propertyFetch);

                if ($usage !== null) {
                    $access['usage'] = $usage;
                }
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

    private function propertyUsage(
        Node\Expr\PropertyFetch $property
    ): ?string {
        $parent = $property->getAttribute('parent');

        if ($parent instanceof Node\Arg) {
            $parent = $parent->getAttribute('parent');
        }

        if ($parent instanceof Node\Expr\FuncCall) {
            return 'function_argument';
        }

        if ($parent instanceof Node\Expr\StaticCall) {
            return 'static_method_argument';
        }

        if ($parent instanceof Node\Expr\MethodCall) {
            return 'method_argument';
        }

        if (
            $parent instanceof Node\Expr\BinaryOp
            && ! $parent instanceof Node\Expr\BinaryOp\Coalesce
        ) {
            return 'binary_operation';
        }

        if ($parent instanceof Node\Expr\ArrayDimFetch) {
            return 'array_access';
        }

        return null;
    }

    private function isProtectedAccess(
        Node\Expr\PropertyFetch $property,
        array $aliases
    ): bool {
        $current = $property;

        while (($parent = $current->getAttribute('parent')) instanceof Node) {
            if (
                $parent instanceof Node\Expr\BinaryOp\Coalesce
                || $parent instanceof Node\Expr\Isset_
                || $parent instanceof Node\Expr\Empty_
                || $this->isOptionalCall($parent)
            ) {
                return true;
            }

            if ($parent instanceof Node\Stmt\If_) {
                return $this->isProtectedByIf(
                    $property,
                    $current,
                    $parent,
                    $aliases
                );
            }

            if ($parent instanceof Node\Expr\Ternary) {
                return $this->isProtectedByTernary(
                    $property,
                    $current,
                    $parent,
                    $aliases
                );
            }

            $current = $parent;
        }

        return false;
    }

    private function isOptionalCall(Node $node): bool
    {
        return $node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && $node->name->toString() === 'optional';
    }

    private function isProtectedByIf(
        Node\Expr\PropertyFetch $property,
        Node $branch,
        Node\Stmt\If_ $if,
        array $aliases
    ): bool {
        $access = $this->propertyAccess($property, $aliases);

        if ($access === null) {
            return false;
        }

        if ($branch === $if->cond) {
            return $this->conditionGuardsAccess(
                $if->cond,
                $access,
                $aliases,
                true
            ) || $this->conditionGuardsAccess(
                $if->cond,
                $access,
                $aliases,
                false
            );
        }

        if (in_array($branch, $if->stmts, true)) {
            return $this->conditionGuardsAccess(
                $if->cond,
                $access,
                $aliases,
                true
            );
        }

        if ($branch === $if->else) {
            return $this->conditionGuardsAccess(
                $if->cond,
                $access,
                $aliases,
                false
            );
        }

        return false;
    }

    private function isProtectedByTernary(
        Node\Expr\PropertyFetch $property,
        Node $branch,
        Node\Expr\Ternary $ternary,
        array $aliases
    ): bool {
        $access = $this->propertyAccess($property, $aliases);

        if ($access === null) {
            return false;
        }

        if ($branch === $ternary->cond) {
            return $this->conditionGuardsAccess(
                $ternary->cond,
                $access,
                $aliases,
                true
            ) || $this->conditionGuardsAccess(
                $ternary->cond,
                $access,
                $aliases,
                false
            );
        }

        if ($branch === $ternary->if) {
            return $this->conditionGuardsAccess(
                $ternary->cond,
                $access,
                $aliases,
                true
            );
        }

        if ($branch === $ternary->else) {
            return $this->conditionGuardsAccess(
                $ternary->cond,
                $access,
                $aliases,
                false
            );
        }

        return false;
    }

    private function conditionGuardsAccess(
        Node\Expr $condition,
        array $access,
        array $aliases,
        bool $whenTrue
    ): bool {
        if ($condition instanceof Node\Expr\BooleanNot) {
            return $this->conditionGuardsAccess(
                $condition->expr,
                $access,
                $aliases,
                ! $whenTrue
            );
        }

        if (
            $condition instanceof Node\Expr\BinaryOp\BooleanAnd
            && $whenTrue
        ) {
            return $this->conditionGuardsAccess(
                $condition->left,
                $access,
                $aliases,
                true
            ) || $this->conditionGuardsAccess(
                $condition->right,
                $access,
                $aliases,
                true
            );
        }

        if ($condition instanceof Node\Expr\Isset_ && $whenTrue) {
            foreach ($condition->vars as $variable) {
                if ($variable instanceof Node\Expr\PropertyFetch) {
                    $guard = $this->propertyAccess($variable, $aliases);

                    if ($guard !== null && $this->isAccessPrefix($guard, $access)) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($condition instanceof Node\Expr\PropertyFetch) {
            $guard = $this->propertyAccess($condition, $aliases);

            return $whenTrue
                && $guard !== null
                && $this->isAccessPrefix($guard, $access);
        }

        if (
            $condition instanceof Node\Expr\BinaryOp\NotIdentical
            || $condition instanceof Node\Expr\BinaryOp\NotEqual
            || $condition instanceof Node\Expr\BinaryOp\Identical
            || $condition instanceof Node\Expr\BinaryOp\Equal
        ) {
            $leftIsNull = $this->isNull($condition->left);
            $rightIsNull = $this->isNull($condition->right);

            if ($leftIsNull === $rightIsNull) {
                return false;
            }

            $candidate = $leftIsNull
                ? $condition->right
                : $condition->left;
            $guard = $candidate instanceof Node\Expr\PropertyFetch
                ? $this->propertyAccess($candidate, $aliases)
                : null;
            $nonNullWhenTrue = $condition instanceof Node\Expr\BinaryOp\NotIdentical
                || $condition instanceof Node\Expr\BinaryOp\NotEqual;

            return $guard !== null
                && $whenTrue === $nonNullWhenTrue
                && $this->isAccessPrefix($guard, $access);
        }

        return false;
    }

    private function isNull(Node\Expr $expression): bool
    {
        return $expression instanceof Node\Expr\ConstFetch
            && strtolower($expression->name->toString()) === 'null';
    }

    private function isAccessPrefix(array $guard, array $access): bool
    {
        if (($guard['root'] ?? null) !== ($access['root'] ?? null)) {
            return false;
        }

        $guardNames = array_column($guard['accesses'] ?? [], 'name');
        $accessNames = array_column($access['accesses'] ?? [], 'name');

        return $guardNames !== []
            && $guardNames === array_slice(
                $accessNames,
                0,
                count($guardNames)
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
        $traverser->addVisitor(new ParentConnectingVisitor());
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
