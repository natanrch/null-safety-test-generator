<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Illuminate\Http\Resources\Json\JsonResource;
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

class JsonResponseAnalyzer
{
    public function __construct(
        private ControllerMethodAnalyzer $methodAnalyzer,
        private EloquentAccessChainResolver $accessChainResolver
    ) {
    }

    public function analyze(string $controllerClass, string $methodName): array
    {
        try {
            $method = new ReflectionMethod($controllerClass, $methodName);
            $methodNode = $this->parseMethod($method);
        } catch (Throwable) {
            return [];
        }

        if ($methodNode === null) {
            return [];
        }

        $aliases = $this->controllerAliases($controllerClass, $methodName);
        $accesses = [];
        $visitedResources = [];
        $visitedMethods = [];
        $jsonFound = $this->analyzeMethodReturns(
            $controllerClass,
            $methodName,
            $aliases,
            $accesses,
            $visitedResources,
            $visitedMethods
        );

        if (! $jsonFound) {
            return [];
        }

        $result = [
            'responseType' => 'json',
            'accesses' => array_values($accesses),
        ];

        $requestPreconditions = [];

        foreach ($aliases as $variable => $alias) {
            if (is_array($alias['input'] ?? null)) {
                $requestPreconditions[] = [
                    'root' => $variable,
                    'class' => $alias['class'],
                    'input' => $alias['input'],
                ];
            }
        }

        if ($requestPreconditions !== []) {
            $result['requestPreconditions'] = $requestPreconditions;
        }

        $requestParameters = $this->methodAnalyzer->getScalarRequestInputs(
            $controllerClass,
            $methodName
        );

        if ($requestParameters !== []) {
            $result['requestParameters'] = $requestParameters;
        }

        return $result;
    }

    private function controllerAliases(
        string $controllerClass,
        string $methodName
    ): array {
        $aliases = [];

        foreach ($this->methodAnalyzer->getObjectClasses(
            $controllerClass,
            $methodName
        ) as $variable => $metadata) {
            if (
                is_string($variable)
                && is_string($metadata['class'] ?? null)
                && is_string($metadata['type'] ?? null)
            ) {
                $aliases[$variable] = [
                    'root' => $variable,
                    'class' => $metadata['class'],
                    'type' => $metadata['type'],
                    'accesses' => [],
                ];

                if (is_array($metadata['input'] ?? null)) {
                    $aliases[$variable]['input'] = $metadata['input'];
                }
            }
        }

        return $aliases;
    }

    private function analyzeMethodReturns(
        string $className,
        string $methodName,
        array $aliases,
        array &$accesses,
        array &$visitedResources,
        array &$visitedMethods
    ): bool {
        $visitKey = $className . '::' . $methodName . ':'
            . serialize($aliases);

        if (isset($visitedMethods[$visitKey])) {
            return false;
        }

        $visitedMethods[$visitKey] = true;

        try {
            $method = new ReflectionMethod($className, $methodName);

            if (! $this->isApplicationMethod($method)) {
                return false;
            }

            $methodNode = $this->parseMethod($method);
        } catch (Throwable) {
            return false;
        }

        if ($methodNode === null) {
            return false;
        }

        $jsonFound = false;
        $objectVariables = $this->objectVariables(
            $method,
            $methodNode
        );

        foreach ((new NodeFinder())->findInstanceOf(
            $methodNode->stmts ?? [],
            Node\Stmt\Return_::class
        ) as $return) {
            if (! $return->expr instanceof Node\Expr) {
                continue;
            }

            $expressions = $this->assignedExpressionsBefore(
                $methodNode,
                $return
            );
            $expression = $return->expr;
            $payload = $this->jsonPayload(
                $expression,
                $aliases,
                $expressions
            );

            if ($payload !== null) {
                $jsonFound = true;
                $payloadExpression = $payload['expression'];

                if (! ($payloadExpression instanceof Node\Expr\Variable
                    && is_string($payloadExpression->name)
                    && isset($aliases[$payloadExpression->name]))) {
                    $payloadExpression = $this->resolveAssignedExpression(
                        $payloadExpression,
                        $expressions
                    );
                }
                $this->collectExpression(
                    $payloadExpression,
                    $aliases,
                    $accesses
                );

                if ($payload['resource'] !== null) {
                    $this->addAccess(
                        $payload['resourceAlias'],
                        null,
                        $accesses
                    );
                    $this->analyzeResource(
                        $payload['resource'],
                        $payload['resourceAlias'],
                        $accesses,
                        $visitedResources
                    );
                }

                $this->analyzeReturnedCalls(
                    $payloadExpression,
                    $className,
                    $objectVariables,
                    $aliases,
                    $accesses,
                    $visitedResources,
                    $visitedMethods
                );

                continue;
            }

            if ($this->analyzeReturnedCalls(
                $this->resolveAssignedExpression(
                    $expression,
                    $expressions
                ),
                $className,
                $objectVariables,
                $aliases,
                $accesses,
                $visitedResources,
                $visitedMethods
            )) {
                $jsonFound = true;
            }
        }

        return $jsonFound;
    }

    private function jsonPayload(
        Node\Expr $expression,
        array $aliases,
        array $expressions = []
    ): ?array
    {
        if (! ($expression instanceof Node\Expr\Variable
            && is_string($expression->name)
            && isset($aliases[$expression->name]))) {
            $expression = $this->resolveAssignedExpression(
                $expression,
                $expressions
            );
        }

        if ($expression instanceof Node\Expr\Array_) {
            return $this->payload($expression);
        }

        if (
            $expression instanceof Node\Expr\MethodCall
            && $expression->name instanceof Node\Identifier
            && $expression->name->toString() === 'json'
            && isset($expression->args[0])
        ) {
            return $this->payload($expression->args[0]->value);
        }

        if ($expression instanceof Node\Expr\Variable) {
            if (! is_string($expression->name) || ! isset($aliases[$expression->name])) {
                return null;
            }

            return $this->payload($expression);
        }

        if ($expression instanceof Node\Expr\New_) {
            $resource = $this->resourceClass($expression->class);
            $alias = isset($expression->args[0])
                ? $this->trackedExpression(
                    $expression->args[0]->value,
                    $aliases
                )
                : null;

            return $resource !== null && $alias !== null
                ? $this->payload($expression, $resource, $alias)
                : null;
        }

        if (
            $expression instanceof Node\Expr\StaticCall
            && $expression->name instanceof Node\Identifier
            && $expression->name->toString() === 'collection'
            && isset($expression->args[0])
        ) {
            $resource = $this->resourceClass($expression->class);
            $alias = $this->trackedExpression(
                $expression->args[0]->value,
                $aliases
            );

            if ($resource !== null && $alias !== null) {
                $alias['type'] = 'collection';

                return $this->payload($expression, $resource, $alias);
            }
        }

        return null;
    }

    private function assignedExpressionsBefore(
        Node\Stmt\ClassMethod $method,
        Node\Stmt\Return_ $return
    ): array {
        $expressions = [];
        $assignments = (new NodeFinder())->findInstanceOf(
            $method->stmts ?? [],
            Node\Expr\Assign::class
        );

        usort(
            $assignments,
            static fn (Node\Expr\Assign $left, Node\Expr\Assign $right): int =>
                $left->getStartFilePos() <=> $right->getStartFilePos()
        );

        foreach ($assignments as $assignment) {
            if ($assignment->getStartFilePos() >= $return->getStartFilePos()) {
                break;
            }

            if ($assignment->var instanceof Node\Expr\Variable
                && is_string($assignment->var->name)) {
                $expressions[$assignment->var->name] = $assignment->expr;
            }
        }

        return $expressions;
    }

    private function resolveAssignedExpression(
        Node\Expr $expression,
        array $expressions,
        array $visited = []
    ): Node\Expr {
        if (! $expression instanceof Node\Expr\Variable
            || ! is_string($expression->name)
            || ! isset($expressions[$expression->name])
            || isset($visited[$expression->name])) {
            return $expression;
        }

        $visited[$expression->name] = true;

        return $this->resolveAssignedExpression(
            $expressions[$expression->name],
            $expressions,
            $visited
        );
    }

    private function analyzeReturnedCalls(
        Node\Expr $expression,
        string $currentClass,
        array $objectVariables,
        array $aliases,
        array &$accesses,
        array &$visitedResources,
        array &$visitedMethods
    ): bool {
        $jsonFound = false;
        $calls = [
            ...(new NodeFinder())->findInstanceOf(
                [$expression],
                Node\Expr\MethodCall::class
            ),
            ...(new NodeFinder())->findInstanceOf(
                [$expression],
                Node\Expr\StaticCall::class
            ),
        ];

        foreach ($calls as $call) {
            $target = $this->calledMethod(
                $call,
                $currentClass,
                $objectVariables
            );

            if ($target === null) {
                continue;
            }

            [$targetClass, $targetMethod] = $target;
            $calledAliases = $this->calledAliases(
                $call,
                $targetClass,
                $targetMethod,
                $aliases
            );

            if ($this->analyzeMethodReturns(
                $targetClass,
                $targetMethod,
                $calledAliases,
                $accesses,
                $visitedResources,
                $visitedMethods
            )) {
                $jsonFound = true;
            }
        }

        return $jsonFound;
    }

    private function calledMethod(
        Node\Expr\MethodCall|Node\Expr\StaticCall $call,
        string $currentClass,
        array $objectVariables
    ): ?array {
        if (! $call->name instanceof Node\Identifier) {
            return null;
        }

        $targetClass = null;

        if ($call instanceof Node\Expr\StaticCall
            && $call->class instanceof Node\Name) {
            $targetClass = match (strtolower($call->class->toString())) {
                'self', 'static' => $currentClass,
                'parent' => get_parent_class($currentClass) ?: null,
                default => $call->class->toString(),
            };
        }

        if ($call instanceof Node\Expr\MethodCall) {
            if ($call->var instanceof Node\Expr\Variable
                && $call->var->name === 'this') {
                $targetClass = $currentClass;
            } elseif ($call->var instanceof Node\Expr\Variable
                && is_string($call->var->name)) {
                $targetClass = $objectVariables[$call->var->name]
                    ?? null;
            } elseif ($call->var instanceof Node\Expr\PropertyFetch
                && $call->var->var instanceof Node\Expr\Variable
                && $call->var->var->name === 'this'
                && $call->var->name instanceof Node\Identifier) {
                $targetClass = $this->objectProperties($currentClass)[
                    $call->var->name->toString()
                ] ?? null;
            } elseif ($call->var instanceof Node\Expr\New_
                && $call->var->class instanceof Node\Name) {
                $targetClass = $call->var->class->toString();
            }
        }

        if (! is_string($targetClass)
            || ! method_exists($targetClass, $call->name->toString())) {
            return null;
        }

        return [$targetClass, $call->name->toString()];
    }

    private function objectVariables(
        ReflectionMethod $method,
        Node\Stmt\ClassMethod $methodNode
    ): array {
        $variables = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType
                && ! $type->isBuiltin()) {
                $variables[$parameter->getName()] = $type->getName();
            }
        }

        foreach ((new NodeFinder())->findInstanceOf(
            $methodNode->stmts ?? [],
            Node\Expr\Assign::class
        ) as $assignment) {
            if ($assignment->var instanceof Node\Expr\Variable
                && is_string($assignment->var->name)
                && $assignment->expr instanceof Node\Expr\New_
                && $assignment->expr->class instanceof Node\Name) {
                $variables[$assignment->var->name]
                    = $assignment->expr->class->toString();
            }
        }

        return $variables;
    }

    private function calledAliases(
        Node\Expr\MethodCall|Node\Expr\StaticCall $call,
        string $className,
        string $methodName,
        array $aliases
    ): array {
        try {
            $method = new ReflectionMethod($className, $methodName);
        } catch (Throwable) {
            return [];
        }

        $calledAliases = [];

        foreach ($method->getParameters() as $index => $parameter) {
            $argument = $call->args[$index]->value ?? null;

            foreach ($call->args as $candidate) {
                if ($candidate->name?->toString()
                    === $parameter->getName()) {
                    $argument = $candidate->value;
                    break;
                }
            }

            if (! $argument instanceof Node\Expr) {
                continue;
            }

            $tracked = $this->trackedExpression($argument, $aliases);

            if ($tracked !== null) {
                $calledAliases[$parameter->getName()] = $tracked;
            }
        }

        return $calledAliases;
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

            if ($type instanceof ReflectionNamedType
                && ! $type->isBuiltin()) {
                $properties[$property->getName()] = $type->getName();
            }
        }

        return $properties;
    }

    private function isApplicationMethod(ReflectionMethod $method): bool
    {
        $file = $method->getFileName();

        return $file !== false
            && ! str_contains(str_replace('\\', '/', $file), '/vendor/');
    }

    private function payload(
        Node\Expr $expression,
        ?string $resource = null,
        ?array $resourceAlias = null
    ): array {
        return [
            'expression' => $expression,
            'resource' => $resource,
            'resourceAlias' => $resourceAlias,
        ];
    }

    private function collectExpression(
        Node\Expr $expression,
        array $aliases,
        array &$accesses
    ): void {
        if ($expression instanceof Node\Expr\Variable) {
            $alias = is_string($expression->name)
                ? ($aliases[$expression->name] ?? null)
                : null;

            if ($alias !== null) {
                $this->addAccess($alias, null, $accesses);
            }
        }

        foreach ((new NodeFinder())->findInstanceOf(
            [$expression],
            Node\Expr\PropertyFetch::class
        ) as $propertyFetch) {
            $parent = $propertyFetch->getAttribute('parent');

            if (
                $parent instanceof Node\Expr\PropertyFetch
                || $parent instanceof Node\Expr\NullsafePropertyFetch
            ) {
                continue;
            }

            $access = $this->propertyAccess($propertyFetch, $aliases);

            if ($access !== null) {
                $this->addAccess(
                    $access,
                    $this->usage($propertyFetch),
                    $accesses
                );
            }
        }
    }

    private function analyzeResource(
        string $resourceClass,
        array $alias,
        array &$accesses,
        array &$visited
    ): void {
        $visitKey = $resourceClass . ':' . serialize($alias);

        if (isset($visited[$visitKey])) {
            return;
        }

        $visited[$visitKey] = true;

        try {
            $method = new ReflectionMethod($resourceClass, 'toArray');
            $methodNode = $this->parseMethod($method);
        } catch (Throwable) {
            return;
        }

        if ($methodNode === null) {
            return;
        }

        $aliases = ['this' => $alias];

        foreach ((new NodeFinder())->findInstanceOf(
            $methodNode->stmts ?? [],
            Node\Stmt\Return_::class
        ) as $return) {
            if (! $return->expr instanceof Node\Expr) {
                continue;
            }

            $this->collectExpression($return->expr, $aliases, $accesses);

            foreach ((new NodeFinder())->findInstanceOf(
                [$return->expr],
                Node\Expr\New_::class
            ) as $new) {
                $nestedClass = $this->resourceClass($new->class);
                $nestedAlias = isset($new->args[0])
                    ? $this->trackedExpression(
                        $new->args[0]->value,
                        $aliases
                    )
                    : null;

                if ($nestedClass !== null && $nestedAlias !== null) {
                    $this->analyzeResource(
                        $nestedClass,
                        $nestedAlias,
                        $accesses,
                        $visited
                    );
                }
            }
        }
    }

    private function addAccess(
        array $access,
        ?string $usage,
        array &$accesses
    ): void {
        $access['resolvedAccesses'] = $this->accessChainResolver->resolve(
            $access['class'],
            $access['accesses']
        );

        if ($usage !== null) {
            $access['usage'] = $usage;
        }

        $accesses[serialize([
            $access['root'],
            $access['type'],
            $access['accesses'],
            $usage,
        ])] = $access;
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
            $current instanceof Node\Expr\NullsafePropertyFetch
            || ! $current instanceof Node\Expr\Variable
            || ! is_string($current->name)
            || ! isset($aliases[$current->name])
        ) {
            return null;
        }

        $alias = $aliases[$current->name];

        $access = [
            'root' => $alias['root'],
            'class' => $alias['class'],
            'type' => $alias['type'],
            'accesses' => [...$alias['accesses'], ...$properties],
        ];

        if (is_array($alias['input'] ?? null)) {
            $access['input'] = $alias['input'];
        }

        return $access;
    }

    private function usage(Node\Expr\PropertyFetch $property): ?string
    {
        $parent = $property->getAttribute('parent');

        if ($parent instanceof Node\Expr\FuncCall) {
            return 'function_argument';
        }

        if ($parent instanceof Node\Expr\BinaryOp) {
            return 'binary_operation';
        }

        if ($parent instanceof Node\Expr\MethodCall) {
            return 'method_argument';
        }

        return null;
    }

    private function resourceClass(Node\Name|Node\Expr $class): ?string
    {
        if (! $class instanceof Node\Name) {
            return null;
        }

        $className = $class->toString();

        return class_exists($className)
            && is_subclass_of($className, JsonResource::class)
                ? $className
                : null;
    }

    private function parseMethod(ReflectionMethod $method): ?Node\Stmt\ClassMethod
    {
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
