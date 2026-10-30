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

    private array $returnCache = [];

    private array $propertyStateCache = [];

    public function __construct(
        private EloquentAccessChainResolver $accessChainResolver,
        private bool $includeEntryMethodAccesses = true
    ) {
    }

    public function analyze(string $controllerClass, string $methodName): array
    {
        $this->returnCache = [];
        $this->propertyStateCache = [];

        try {
            $method = new ReflectionMethod($controllerClass, $methodName);
        } catch (Throwable) {
            return [];
        }

        $results = [];
        $visited = [];
        $propertyAliases = [];
        $objectStates = [];
        $returnedAliases = [];

        $this->analyzeMethod(
            $controllerClass,
            $methodName,
            $this->modelParameters($method),
            $results,
            $visited,
            0,
            [],
            $propertyAliases,
            $objectStates,
            $returnedAliases
        );

        return array_values($results);
    }

    /**
     * Returns roots that may have been mutated, or null when effects are unknown.
     * The cache includes incoming facts: the same method can be safe in one
     * call context and unsafe in another.
     */
    private function analyzeMethod(
        string $className,
        string $methodName,
        array $aliases,
        array &$results,
        array &$visited,
        int $depth,
        array $guards = [],
        array &$propertyAliases = [],
        array &$objectStates = [],
        array &$returnedAliases = []
    ): ?array {
        if ($depth > self::MAX_CALL_DEPTH) {
            return null;
        }

        try {
            $method = new ReflectionMethod($className, $methodName);
            if (! $this->isApplicationMethod($method)) {
                return null;
            }
            $classMethod = $this->parseMethod($method);
        } catch (Throwable) {
            return null;
        }

        if ($classMethod === null) {
            return null;
        }

        $visitKey = $className . '::' . $methodName . ':'
            . serialize([$aliases, $guards, $propertyAliases]);
        if (array_key_exists($visitKey, $visited)) {
            $returnedAliases = $this->returnCache[$visitKey] ?? [];

            if (isset($this->propertyStateCache[$visitKey])) {
                $propertyAliases = $this->propertyStateCache[$visitKey];
            }

            return $visited[$visitKey];
        }
        $visited[$visitKey] = null;

        $parameters = $this->modelParameters($method);

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isPromoted()
                && isset($aliases[$parameter->getName()])) {
                $propertyAliases[$parameter->getName()]
                    = $aliases[$parameter->getName()];
            }
        }

        $localModels = $depth === 0
            ? $this->entryModelAliases($className, $methodName, $method)
            : [];
        $objectVariables = $this->objectParameters($method);
        $objectProperties = $this->objectProperties($className);
        $mutations = [];
        $events = [];

        foreach ((new NodeFinder())->findInstanceOf($classMethod->stmts ?? [], Node\Expr::class) as $node) {
            // Capture aliases before evaluating the expression, then process its
            // effect after its arguments/RHS have been evaluated.
            $events[] = [
                $node->getStartFilePos(),
                0,
                $node->getStartFilePos(),
                $node,
            ];
            $events[] = [
                $node->getEndFilePos(),
                1,
                -$node->getStartFilePos(),
                $node,
            ];
        }
        usort($events, static fn (array $a, array $b): int =>
            [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        foreach ($events as [, $phase, , $node]) {
            $effectiveAliases = $this->withPropertyAliases(
                $aliases,
                $propertyAliases
            );

            if ($phase === 0) {
                $node->setAttribute('modelAliases', $effectiveAliases);

                if ($node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction) {
                    $callbackAliases = $this->callbackAliases(
                        $node,
                        $effectiveAliases
                    );
                    $previous = [];

                    foreach ($callbackAliases as $variable => $alias) {
                        $previous[$variable] = $aliases[$variable] ?? null;
                        $aliases[$variable] = $alias;
                    }

                    $node->setAttribute(
                        'previousCallbackAliases',
                        $previous
                    );
                }

                continue;
            }

            if ($node instanceof Node\Expr\Closure
                || $node instanceof Node\Expr\ArrowFunction) {
                foreach ($node->getAttribute(
                    'previousCallbackAliases',
                    []
                ) as $variable => $previous) {
                    if ($previous === null) {
                        unset($aliases[$variable]);
                    } else {
                        $aliases[$variable] = $previous;
                    }
                }

                continue;
            }

            if ($node instanceof Node\Expr\Assign) {
                $storedProperty = $this->storedPropertyName($node->var);
                $tracked = $this->trackedExpression(
                    $node->expr,
                    $effectiveAliases
                );

                if ($storedProperty !== null) {
                    unset($propertyAliases[$storedProperty]);

                    if ($tracked !== null) {
                        $propertyAliases[$storedProperty] = $tracked;
                    }

                    continue;
                }

                $target = $this->trackedExpression(
                    $node->var,
                    $effectiveAliases
                );
                if ($target !== null) {
                    $mutations[] = ['root' => $target['root'], 'position' => $node->getEndFilePos()];
                }
                if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                    $variable = $node->var->name;
                    unset($aliases[$variable]);
                    unset($objectVariables[$variable]);

                    if ($node->expr instanceof Node\Expr\New_) {
                        $newClass = $this->newClassName($node->expr);

                        if ($newClass !== null) {
                            $objectVariables[$variable] = $newClass;
                        }
                    }

                    if ($tracked !== null) {
                        $aliases[$variable] = $tracked;
                    } elseif (isset($localModels[$variable])
                        && ($node->expr instanceof Node\Expr\StaticCall
                            || $node->expr instanceof Node\Expr\MethodCall)) {
                        $aliases[$variable] = $localModels[$variable];
                    }
                }
                continue;
            }

            $context = $this->callGuards(
                $node,
                $effectiveAliases,
                $guards,
                $mutations
            );

            if ($node instanceof Node\Expr\PropertyFetch
                && (
                    $depth > 0
                    || $this->includeEntryMethodAccesses
                    || $this->hasTrackedReturnReceiver($node)
                )) {
                $this->recordAccess(
                    $node,
                    $effectiveAliases,
                    $context,
                    $parameters,
                    $depth,
                    $results
                );
            }

            if (! ($node instanceof Node\Expr\MethodCall
                || $node instanceof Node\Expr\StaticCall
                || $node instanceof Node\Expr\FuncCall
                || $node instanceof Node\Expr\New_)) {
                continue;
            }

            $targetClass = match (true) {
                $node instanceof Node\Expr\MethodCall =>
                    $this->calledObjectClass(
                        $node->var,
                        $className,
                        $objectVariables,
                        $objectProperties
                    ),
                $node instanceof Node\Expr\StaticCall =>
                    $this->staticClassName($node, $className),
                $node instanceof Node\Expr\New_ =>
                    $this->newClassName($node),
                default => null,
            };
            $calledAliases = [];
            $effects = null;
            $callReturns = [];

            $targetMethod = $node instanceof Node\Expr\New_
                ? '__construct'
                : ($node->name instanceof Node\Identifier
                    ? $node->name->toString()
                    : null);

            if ($targetClass !== null && $targetMethod !== null) {
                try {
                    $reflection = new ReflectionMethod(
                        $targetClass,
                        $targetMethod
                    );
                    foreach ($reflection->getParameters() as $index => $parameter) {
                        $argument = $node->args[$index]->value ?? null;
                        foreach ($node->args as $arg) {
                            if ($arg->name?->toString() === $parameter->getName()) {
                                $argument = $arg->value;
                                break;
                            }
                        }
                        if ($argument instanceof Node\Expr) {
                            $tracked = $this->trackedExpression(
                                $argument,
                                $effectiveAliases
                            );
                            if ($tracked !== null) {
                                $calledAliases[$parameter->getName()] = $tracked;
                            }
                        }
                    }
                    $calleeState =& $this->calleePropertyState(
                        $node,
                        $targetClass,
                        $propertyAliases,
                        $objectStates
                    );

                    if ($calledAliases !== [] || $calleeState !== []) {
                        $effects = $this->analyzeMethod(
                            $targetClass, $targetMethod, $calledAliases,
                            $results, $visited, $depth + 1, $context,
                            $calleeState, $objectStates, $callReturns
                        );

                        if ($callReturns !== []) {
                            $node->setAttribute(
                                'trackedReturn',
                                $callReturns[0]
                            );
                        }
                    }
                } catch (Throwable) {
                    // An unresolved call must not preserve assumptions about
                    // the models passed to it.
                }
            }

            if ($node instanceof Node\Expr\MethodCall
                && $this->isCollectionPassthroughCall($node)) {
                $collection = $this->trackedExpression(
                    $node->var,
                    $effectiveAliases
                );

                if ($collection !== null) {
                    $node->setAttribute('trackedReturn', $collection);
                }
            }

            if ($effects === null) {
                $effects = [];
                foreach ($node->args as $arg) {
                    $tracked = $this->trackedExpression(
                        $arg->value,
                        $effectiveAliases
                    );
                    if ($tracked !== null) {
                        $effects[] = $tracked['root'];
                    }
                }
                if ($node instanceof Node\Expr\MethodCall) {
                    $receiver = $this->trackedExpression(
                        $node->var,
                        $effectiveAliases
                    );
                    if ($receiver !== null) {
                        $effects[] = $receiver['root'];
                    }
                }
                if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                    && in_array(strtolower($node->name->toString()), ['is_null', 'optional'], true)) {
                    $effects = [];
                }
            }
            foreach ($effects as $root) {
                $mutations[] = ['root' => $root, 'position' => $node->getEndFilePos()];
            }
        }

        foreach ((new NodeFinder())->findInstanceOf(
            $classMethod->stmts ?? [],
            Node\Stmt\Return_::class
        ) as $return) {
            if (! $return->expr instanceof Node\Expr) {
                continue;
            }

            $returnAliases = $return->expr->getAttribute(
                'modelAliases',
                $this->withPropertyAliases($aliases, $propertyAliases)
            );
            $returned = $this->trackedExpression(
                $return->expr,
                $returnAliases
            );

            if ($returned !== null) {
                $returnedAliases[serialize($returned)] = $returned;
            }
        }

        $returnedAliases = array_values($returnedAliases);

        $this->returnCache[$visitKey] = $returnedAliases;
        $this->propertyStateCache[$visitKey] = $propertyAliases;

        return $visited[$visitKey] = array_values(array_unique(array_column($mutations, 'root')));
    }

    private function withPropertyAliases(
        array $aliases,
        array $propertyAliases
    ): array {
        foreach ($propertyAliases as $property => $alias) {
            $aliases['this->' . $property] = $alias;
        }

        return $aliases;
    }

    private function hasTrackedReturnReceiver(
        Node\Expr\PropertyFetch $property
    ): bool {
        $current = $property->var;

        while ($current instanceof Node\Expr\PropertyFetch) {
            $current = $current->var;
        }

        return is_array($current->getAttribute('trackedReturn'));
    }

    private function storedPropertyName(Node\Expr $expression): ?string
    {
        return $expression instanceof Node\Expr\PropertyFetch
            && $expression->var instanceof Node\Expr\Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Node\Identifier
                ? $expression->name->toString()
                : null;
    }

    private function &calleePropertyState(
        Node\Expr\MethodCall|Node\Expr\StaticCall|Node\Expr\New_ $call,
        string $targetClass,
        array &$propertyAliases,
        array &$objectStates
    ): array {
        if ($call instanceof Node\Expr\MethodCall
            && $call->var instanceof Node\Expr\Variable
            && $call->var->name === 'this'
        ) {
            return $propertyAliases;
        }

        $key = match (true) {
            $call instanceof Node\Expr\StaticCall =>
                $targetClass . ':static',
            $call instanceof Node\Expr\New_ =>
                $targetClass . ':' . $this->receiverIdentity($call),
            default => $targetClass . ':'
                . $this->receiverIdentity($call->var),
        };
        $objectStates[$key] ??= [];

        return $objectStates[$key];
    }

    private function receiverIdentity(Node\Expr $receiver): string
    {
        if (
            $receiver instanceof Node\Expr\Variable
            && is_string($receiver->name)
        ) {
            return '$' . $receiver->name;
        }

        if (
            $receiver instanceof Node\Expr\PropertyFetch
            && $receiver->var instanceof Node\Expr\Variable
            && is_string($receiver->var->name)
            && $receiver->name instanceof Node\Identifier
        ) {
            return '$' . $receiver->var->name
                . '->' . $receiver->name->toString();
        }
        if ($receiver instanceof Node\Expr\New_) {
            $parent = $receiver->getAttribute('parent');

            if ($parent instanceof Node\Expr\Assign
                && $parent->expr === $receiver
                && $parent->var instanceof Node\Expr\Variable
                && is_string($parent->var->name)) {
                return '$' . $parent->var->name;
            }
        }

        return 'expression@' . $receiver->getStartFilePos();
    }

    private function recordAccess(
        Node\Expr\PropertyFetch $property,
        array $aliases,
        array $guards,
        array $parameters,
        int $depth,
        array &$results
    ): void {
        $parent = $property->getAttribute('parent');
        if ($parent instanceof Node\Expr\PropertyFetch
            || $parent instanceof Node\Expr\NullsafePropertyFetch
            || ($parent instanceof Node\Expr\Assign && $parent->var === $property)
            || $this->isSafelyRead($property)) {
            return;
        }
        $access = $this->propertyAccess($property, $aliases);
        if ($access === null || $access['accesses'] === []
            || ($depth === 0 && ! isset($parameters[$access['root']]))) {
            return;
        }

        $protected = 0;
        foreach ($guards as $guard) {
            if ($this->isAccessPrefix($guard, $access)) {
                $protected = max($protected, count($guard['accesses']));
            }
        }
        if ($protected > 0) {
            $access['protectedAccessCount'] = $protected;
        }

        $usage = $this->propertyUsage($property);
        if ($this->isConditionValue($property)) {
            $access['protectedAccessIndices'] = [count($access['accesses']) - 1];
            $usage = null;
        }
        if ($usage !== null) {
            $access['usage'] = $usage;
        }
        $access['resolvedAccesses'] = $this->accessChainResolver->resolve(
            $access['class'], $access['accesses']
        );
        // Retain both guarded and unguarded uses, independently of call order.
        $results[serialize($access)] = $access;
    }

    private function isSafelyRead(Node\Expr\PropertyFetch $property): bool
    {
        $child = $property;
        while (($parent = $child->getAttribute('parent')) instanceof Node\Expr || $parent instanceof Node\Arg) {
            if ($parent instanceof Node\Expr\Isset_ || $parent instanceof Node\Expr\Empty_
                || $this->isOptionalCall($parent)
                || ($parent instanceof Node\Expr\BinaryOp\Coalesce && $child === $parent->left)) {
                return true;
            }
            $child = $parent;
        }
        return false;
    }

    private function isConditionValue(Node\Expr\PropertyFetch $property): bool
    {
        $parent = $property->getAttribute('parent');
        if ($parent instanceof Node\Stmt\If_ || $parent instanceof Node\Stmt\ElseIf_) {
            return $parent->cond === $property;
        }
        if ($parent instanceof Node\Expr\Ternary) {
            return $parent->cond === $property;
        }
        if ($parent instanceof Node\Expr\BooleanNot
            || $parent instanceof Node\Expr\BinaryOp\BooleanAnd
            || $parent instanceof Node\Expr\BinaryOp\BooleanOr
            || $parent instanceof Node\Expr\BinaryOp\LogicalAnd
            || $parent instanceof Node\Expr\BinaryOp\LogicalOr) {
            return true;
        }
        if ($parent instanceof Node\Expr\BinaryOp\Identical || $parent instanceof Node\Expr\BinaryOp\NotIdentical) {
            return $this->isNull($parent->left) || $this->isNull($parent->right);
        }
        if ($parent instanceof Node\Arg) {
            $call = $parent->getAttribute('parent');
            return $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name
                && strtolower($call->name->toString()) === 'is_null';
        }
        return false;
    }

    private function callGuards(Node\Expr $node, array $aliases, array $inherited, array $mutations): array
    {
        $conditions = [];
        $child = $node;
        while (($parent = $child->getAttribute('parent')) instanceof Node) {
            if (($parent instanceof Node\Stmt\If_ || $parent instanceof Node\Stmt\ElseIf_)
                && in_array($child, $parent->stmts, true)) {
                $conditions[] = [$parent->cond, true];
            }
            if ($parent instanceof Node\Stmt\Else_ || $parent instanceof Node\Stmt\ElseIf_) {
                $owner = $parent->getAttribute('parent');
                if ($owner instanceof Node\Stmt\If_) {
                    $conditions[] = [$owner->cond, false];
                    foreach ($owner->elseifs as $previous) {
                        if ($previous === $parent) {
                            break;
                        }
                        $conditions[] = [$previous->cond, false];
                    }
                }
            }
            if ($parent instanceof Node\Expr\Ternary && $child !== $parent->cond) {
                $conditions[] = [$parent->cond, $child === $parent->if];
            }
            if (($parent instanceof Node\Expr\BinaryOp\BooleanAnd || $parent instanceof Node\Expr\BinaryOp\LogicalAnd)
                && $child === $parent->right) {
                $conditions[] = [$parent->left, true];
            }
            if (($parent instanceof Node\Expr\BinaryOp\BooleanOr || $parent instanceof Node\Expr\BinaryOp\LogicalOr)
                && $child === $parent->right) {
                $conditions[] = [$parent->left, false];
            }
            $child = $parent;
        }
        $candidates = array_map(static fn (array $guard): array => [$guard, -1], $inherited);
        foreach ($conditions as [$condition, $truthy]) {
            foreach ((new ConditionGuardAnalyzer())->analyze($condition, $truthy) as $guard) {
                $resolved = $this->trackedExpression($guard, $guard->getAttribute('modelAliases', $aliases));
                if ($resolved !== null) {
                    $candidates[] = [$resolved, $condition->getEndFilePos()];
                }
            }
        }
        $result = [];
        foreach ($candidates as [$guard, $position]) {
            foreach ($mutations as $mutation) {
                if ($mutation['root'] === $guard['root'] && $mutation['position'] > $position) {
                    continue 2;
                }
            }
            $result[serialize($guard)] = $guard;
        }
        return array_values($result);
    }

    private function entryModelAliases(string $className, string $methodName, ReflectionMethod $method): array
    {
        $aliases = $this->modelParameters($method);
        foreach ((new ControllerMethodAnalyzer())->getObjectClasses($className, $methodName) as $variable => $metadata) {
            $class = $metadata['class'] ?? null;
            if (($metadata['type'] ?? null) !== 'object' || ! is_string($class)
                || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $aliases[$variable] = [
                ...$metadata,
                'root' => $variable,
                'accesses' => [],
            ];
        }
        return $aliases;
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

        if ($receiver instanceof Node\Expr\New_) {
            return $this->newClassName($receiver);
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

    private function newClassName(Node\Expr\New_ $new): ?string
    {
        return $new->class instanceof Node\Name
            ? $new->class->toString()
            : null;
    }

    private function staticClassName(
        Node\Expr\StaticCall $call,
        string $currentClass
    ): ?string {
        if (! $call->class instanceof Node\Name) {
            return null;
        }

        return match (strtolower($call->class->toString())) {
            'self', 'static' => $currentClass,
            'parent' => get_parent_class($currentClass) ?: null,
            default => $call->class->toString(),
        };
    }

    private function callbackAliases(
        Node\Expr\Closure|Node\Expr\ArrowFunction $callback,
        array $aliases
    ): array {
        $argument = $callback->getAttribute('parent');
        $call = $argument instanceof Node\Arg
            ? $argument->getAttribute('parent')
            : null;

        if (! $call instanceof Node\Expr\MethodCall) {
            return [];
        }

        $collection = $this->trackedExpression($call->var, $aliases);
        $parameter = $callback->params[0] ?? null;

        if ($collection === null
            || ! $parameter instanceof Node\Param
            || ! $parameter->var instanceof Node\Expr\Variable
            || ! is_string($parameter->var->name)
            || ! $this->isModelCollection($collection)) {
            return [];
        }

        return [$parameter->var->name => $collection];
    }

    private function isModelCollection(array $alias): bool
    {
        $resolved = $this->accessChainResolver->resolve(
            $alias['class'],
            $alias['accesses'] ?? []
        );
        $last = $resolved === [] ? null : $resolved[array_key_last($resolved)];

        return ($alias['type'] ?? null) === 'collection'
            || (($last['kind'] ?? null) === 'relationship'
                && in_array($last['relation'] ?? null, [
                    'belongsToMany',
                    'hasMany',
                    'hasManyThrough',
                    'morphMany',
                    'morphToMany',
                    'morphedByMany',
                ], true));
    }

    private function isCollectionPassthroughCall(
        Node\Expr\MethodCall $call
    ): bool {
        return $call->name instanceof Node\Identifier
            && in_array(strtolower($call->name->toString()), [
                'each',
                'filter',
                'map',
                'reject',
                'sort',
                'sortby',
                'sortbydesc',
                'values',
            ], true);
    }

    private function trackedExpression(
        Node\Expr $expression,
        array $aliases
    ): ?array {
        $returned = $expression->getAttribute('trackedReturn');

        if (is_array($returned)) {
            return $returned;
        }

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

        $returned = $current->getAttribute('trackedReturn');

        if (is_array($returned)) {
            $alias = $returned;

            return [
                'root' => $alias['root'],
                'class' => $alias['class'],
                'type' => 'object',
                'accesses' => [
                    ...$alias['accesses'],
                    ...$properties,
                ],
                ...(isset($alias['input'])
                    ? ['input' => $alias['input']]
                    : []),
            ];
        }

        if (! $current instanceof Node\Expr\Variable
            || ! is_string($current->name)) {
            return null;
        }

        $aliasKey = $current->name;

        if (
            $aliasKey === 'this'
            && isset($properties[0]['name'])
            && isset($aliases['this->' . $properties[0]['name']])
        ) {
            $aliasKey = 'this->' . $properties[0]['name'];
            array_shift($properties);
        }

        if (! isset($aliases[$aliasKey])) {
            return null;
        }

        $alias = $aliases[$aliasKey];

        $access = [
            'root' => $alias['root'],
            'class' => $alias['class'],
            'type' => 'object',
            'accesses' => [...$alias['accesses'], ...$properties],
        ];
        if (isset($alias['input'])) {
            $access['input'] = $alias['input'];
        }
        return $access;
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

    private function isOptionalCall(Node $node): bool
    {
        return $node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && $node->name->toString() === 'optional';
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
