<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use JsonSerializable;
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
use ReflectionProperty;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
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
                $expressions,
                $className
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
                $this->analyzeSerializedModels(
                    $payloadExpression,
                    $aliases,
                    $methodNode,
                    $accesses,
                    $className
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
        array $expressions = [],
        ?string $currentClass = null
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
            && $expression->name->toString() === 'streamJson'
            && isset($expression->args[0])
        ) {
            return $this->payload($expression->args[0]->value);
        }

        if (
            $expression instanceof Node\Expr\MethodCall
            && $expression->name instanceof Node\Identifier
            && $expression->name->toString() === 'stream'
            && isset($expression->args[0])
            && $this->isJsonStream($expression)
        ) {
            return $this->payload($expression->args[0]->value);
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
            $objectClass = $this->expressionClass(
                $expression->class,
                $currentClass
            );

            if (
                $objectClass !== null
                && is_a($objectClass, StreamedJsonResponse::class, true)
                && isset($expression->args[0])
            ) {
                return $this->payload($expression->args[0]->value);
            }

            $resource = $this->resourceClass($expression->class);
            $alias = isset($expression->args[0])
                ? $this->trackedExpression(
                    $expression->args[0]->value,
                    $aliases
                )
                : null;

            if ($resource !== null && $alias !== null) {
                return $this->payload($expression, $resource, $alias);
            }

            return $objectClass !== null
                ? $this->payload($expression)
                : null;
        }

        if (
            $expression instanceof Node\Expr\StaticCall
            && $expression->name instanceof Node\Identifier
            && in_array(
                $expression->name->toString(),
                ['make', 'collection'],
                true
            )
            && isset($expression->args[0])
        ) {
            $resource = $this->resourceClass($expression->class);
            $alias = $this->trackedExpression(
                $expression->args[0]->value,
                $aliases
            );

            if ($resource !== null && $alias !== null) {
                if ($expression->name->toString() === 'collection') {
                    $alias['type'] = 'collection';
                }

                return $this->payload($expression, $resource, $alias);
            }
        }

        return null;
    }

    private function isJsonStream(Node\Expr\MethodCall $stream): bool
    {
        $callback = $stream->args[0]->value ?? null;

        if ($callback instanceof Node\Expr) {
            foreach ((new NodeFinder())->findInstanceOf(
                $callback,
                Node\Expr\FuncCall::class
            ) as $call) {
                if (
                    $call->name instanceof Node\Name
                    && strtolower($call->name->toString()) === 'json_encode'
                ) {
                    return true;
                }
            }
        }

        $headers = null;

        foreach ($stream->args as $position => $argument) {
            if (
                ($argument->name instanceof Node\Identifier
                    && $argument->name->toString() === 'headers')
                || ($argument->name === null && $position === 2)
            ) {
                $headers = $argument->value;
                break;
            }
        }

        if (! $headers instanceof Node\Expr\Array_) {
            return false;
        }

        foreach ($headers->items as $item) {
            if (
                ! $item instanceof Node\Expr\ArrayItem
                || ! $item->key instanceof Node\Scalar\String_
                || strtolower($item->key->value) !== 'content-type'
                || ! $item->value instanceof Node\Scalar\String_
            ) {
                continue;
            }

            if (str_contains(strtolower($item->value->value), 'json')) {
                return true;
            }
        }

        return false;
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
                $access = $this->withConditionalProtection(
                    $propertyFetch,
                    $access,
                    $aliases
                );
                $this->addAccess(
                    $access,
                    $this->usage($propertyFetch),
                    $accesses
                );
            }
        }
    }

    private function analyzeSerializedModels(
        Node\Expr $expression,
        array $aliases,
        Node\Stmt\ClassMethod $methodNode,
        array &$accesses,
        string $currentClass
    ): void {
        $serialized = [];

        if ($expression instanceof Node\Expr\Variable
            || $expression instanceof Node\Expr\MethodCall) {
            $alias = $this->serializedModelAlias(
                $expression,
                $aliases
            );

            if ($alias !== null) {
                $serialized[] = $alias;
            }
        }

        if ($expression instanceof Node\Expr\Array_) {
            foreach ($expression->items as $item) {
                if (! $item?->value instanceof Node\Expr) {
                    continue;
                }

                $alias = $this->serializedModelAlias(
                    $item->value,
                    $aliases
                );

                if ($alias !== null) {
                    $serialized[] = $alias;
                }
            }
        }

        $visited = [];

        foreach ($serialized as $alias) {
            $loaded = $this->loadedRelationships(
                $methodNode,
                $alias
            );
            $this->analyzeSerializedModel(
                $alias,
                $loaded,
                $accesses,
                $visited
            );
        }

        $this->analyzeSerializedObjects(
            $expression,
            $currentClass,
            $aliases,
            $accesses,
            $visited
        );
    }

    private function analyzeSerializedObjects(
        Node\Expr $expression,
        string $currentClass,
        array $aliases,
        array &$accesses,
        array &$visited
    ): void {
        foreach ((new NodeFinder())->findInstanceOf(
            [$expression],
            Node\Expr\New_::class
        ) as $new) {
            $className = $this->expressionClass(
                $new->class,
                $currentClass
            );

            if ($className === null
                || is_subclass_of($className, Model::class)
                || is_subclass_of($className, JsonResource::class)) {
                continue;
            }

            $this->analyzeSerializedObject(
                $className,
                $new,
                $aliases,
                $accesses,
                $visited
            );
        }
    }

    private function analyzeSerializedObject(
        string $className,
        Node\Expr\New_ $new,
        array $callerAliases,
        array &$accesses,
        array &$visited
    ): void {
        $visitKey = $className . ':' . $new->getStartFilePos()
            . ':' . serialize($callerAliases);

        if (isset($visited[$visitKey])) {
            return;
        }

        $visited[$visitKey] = true;
        $parameterAliases = [];
        $propertyAliases = [];

        try {
            $class = new ReflectionClass($className);
            $constructor = $class->getConstructor();
        } catch (Throwable) {
            return;
        }

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $index => $parameter) {
                $argument = $new->args[$index]->value ?? null;

                foreach ($new->args as $candidate) {
                    if ($candidate->name?->toString()
                        === $parameter->getName()) {
                        $argument = $candidate->value;
                        break;
                    }
                }

                if (! $argument instanceof Node\Expr) {
                    continue;
                }

                $tracked = $this->trackedExpression(
                    $argument,
                    $callerAliases
                );

                if ($tracked !== null) {
                    $parameterAliases[$parameter->getName()] = $tracked;

                    if ($parameter->isPromoted()
                        && $this->isModelParameter($parameter)) {
                        $propertyAliases[$parameter->getName()] = $tracked;
                    }
                }
            }

            $this->analyzeObjectConstructor(
                $constructor,
                $parameterAliases,
                $propertyAliases,
                $accesses
            );
        }

        $serializationMethod = match (true) {
            $class->implementsInterface(JsonSerializable::class)
                && $class->hasMethod('jsonSerialize') => 'jsonSerialize',
            $class->implementsInterface(Arrayable::class)
                && $class->hasMethod('toArray') => 'toArray',
            $class->hasMethod('toArray') => 'toArray',
            default => null,
        };

        if ($serializationMethod !== null) {
            $this->analyzeObjectSerializationMethod(
                $class->getMethod($serializationMethod),
                $propertyAliases,
                $accesses
            );

            return;
        }

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC)
            as $property) {
            $alias = $propertyAliases[$property->getName()] ?? null;

            if ($alias !== null) {
                $this->analyzeSerializedModel(
                    $alias,
                    [],
                    $accesses,
                    $visited
                );
            }
        }
    }

    private function analyzeObjectConstructor(
        ReflectionMethod $constructor,
        array $parameterAliases,
        array &$propertyAliases,
        array &$accesses
    ): void {
        $node = $this->parseMethod($constructor);

        if ($node === null) {
            return;
        }

        $modelParameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            if ($this->isModelParameter($parameter)) {
                $modelParameters[$parameter->getName()] = true;
            }
        }

        foreach ((new NodeFinder())->findInstanceOf(
            $node->stmts ?? [],
            Node\Expr\Assign::class
        ) as $assignment) {
            if ($assignment->var instanceof Node\Expr\PropertyFetch
                && $assignment->var->var instanceof Node\Expr\Variable
                && $assignment->var->var->name === 'this'
                && $assignment->var->name instanceof Node\Identifier
                && $assignment->expr instanceof Node\Expr\Variable
                && is_string($assignment->expr->name)
                && isset($parameterAliases[$assignment->expr->name])
                && isset($modelParameters[$assignment->expr->name])) {
                $propertyAliases[$assignment->var->name->toString()]
                    = $parameterAliases[$assignment->expr->name];
            }
        }

        foreach ((new NodeFinder())->findInstanceOf(
            $node->stmts ?? [],
            Node\Expr\PropertyFetch::class
        ) as $property) {
            $parent = $property->getAttribute('parent');

            if ($parent instanceof Node\Expr\PropertyFetch) {
                continue;
            }

            $access = $this->propertyAccess(
                $property,
                $parameterAliases
            );

            if ($access !== null) {
                $this->addAccess(
                    $access,
                    $this->usage($property),
                    $accesses
                );
            }
        }
    }

    private function isModelParameter(mixed $parameter): bool
    {
        if (! $parameter instanceof \ReflectionParameter) {
            return false;
        }

        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType
            && ! $type->isBuiltin()
            && is_subclass_of($type->getName(), Model::class);
    }

    private function analyzeObjectSerializationMethod(
        ReflectionMethod $method,
        array $propertyAliases,
        array &$accesses
    ): void {
        $node = $this->parseMethod($method);

        if ($node === null) {
            return;
        }

        $aliases = [];

        foreach ($propertyAliases as $property => $alias) {
            $aliases['this->' . $property] = $alias;
        }

        foreach ((new NodeFinder())->findInstanceOf(
            $node->stmts ?? [],
            Node\Stmt\Return_::class
        ) as $return) {
            if ($return->expr instanceof Node\Expr) {
                $this->collectExpression(
                    $return->expr,
                    $aliases,
                    $accesses
                );
            }
        }
    }

    private function expressionClass(
        Node\Name|Node\Expr $class,
        ?string $currentClass
    ): ?string {
        if (! $class instanceof Node\Name) {
            return null;
        }

        $name = $class->toString();
        $resolved = match (strtolower($name)) {
            'self', 'static' => $currentClass,
            'parent' => $currentClass !== null
                ? (get_parent_class($currentClass) ?: null)
                : null,
            default => $name,
        };

        return is_string($resolved) && class_exists($resolved)
            ? $resolved
            : null;
    }

    private function serializedModelAlias(
        Node\Expr $expression,
        array $aliases
    ): ?array {
        $alias = null;

        if ($expression instanceof Node\Expr\Variable) {
            $alias = $this->trackedExpression($expression, $aliases);
        }

        if ($expression instanceof Node\Expr\MethodCall
            && $expression->name instanceof Node\Identifier
            && in_array(
                $expression->name->toString(),
                ['load', 'loadMissing'],
                true
            )) {
            $alias = $this->trackedExpression(
                $expression->var,
                $aliases
            );
        }

        return is_array($alias)
            && is_string($alias['class'] ?? null)
            && is_subclass_of($alias['class'], Model::class)
                ? $alias
                : null;
    }

    private function analyzeSerializedModel(
        array $alias,
        array $loaded,
        array &$accesses,
        array &$visited
    ): void {
        $modelClass = $this->serializedModelClass($alias);

        if ($modelClass === null) {
            return;
        }

        $visitKey = $modelClass . ':'
            . serialize([$alias, $loaded]);

        if (isset($visited[$visitKey])) {
            return;
        }

        $visited[$visitKey] = true;

        try {
            /** @var Model $model */
            $model = (new ReflectionClass($modelClass))->newInstance();
        } catch (Throwable) {
            return;
        }

        $visible = $model->getVisible();
        $hidden = $model->getHidden();

        foreach ($this->defaultEagerLoads($model) as $relationship) {
            if (is_string($relationship)) {
                $this->addRelationshipPath($loaded, $relationship);
            }
        }

        foreach ($model->getCasts() as $attribute => $_cast) {
            if (! is_string($attribute)
                || ! $this->isSerializedAttribute(
                    $attribute,
                    $visible,
                    $hidden
                )) {
                continue;
            }

            $this->addAccess(
                $this->appendSerializedProperty($alias, $attribute),
                null,
                $accesses
            );
        }

        foreach ($model->getAppends() as $attribute) {
            if (! is_string($attribute)
                || ! $this->isSerializedAttribute(
                    $attribute,
                    $visible,
                    $hidden
                )) {
                continue;
            }

            $this->analyzeSerializedAccessor(
                $modelClass,
                $attribute,
                $alias,
                $accesses
            );
        }

        foreach ($loaded as $relationship => $nested) {
            if (! is_string($relationship)
                || ! is_array($nested)
                || ! $this->isSerializedAttribute(
                    $relationship,
                    $visible,
                    $hidden
                )) {
                continue;
            }

            $relationshipAccess = $this->appendSerializedProperty(
                $alias,
                $relationship
            );
            $resolved = $this->accessChainResolver->resolve(
                $alias['class'],
                $relationshipAccess['accesses']
            );
            $target = $resolved === []
                ? null
                : $resolved[array_key_last($resolved)];

            if (($target['kind'] ?? null) !== 'relationship'
                || ! is_string($target['relatedClass'] ?? null)) {
                continue;
            }

            $relationshipAccess['protectedAccessCount'] = max(
                $relationshipAccess['protectedAccessCount'] ?? 0,
                count($relationshipAccess['accesses'])
            );
            $relationshipAccess['serializedModelClass']
                = $target['relatedClass'];

            $this->analyzeSerializedModel(
                $relationshipAccess,
                $nested,
                $accesses,
                $visited
            );
        }
    }

    private function serializedModelClass(array $alias): ?string
    {
        $class = $alias['serializedModelClass']
            ?? $alias['class']
            ?? null;

        return is_string($class)
            && is_subclass_of($class, Model::class)
                ? $class
                : null;
    }

    private function isSerializedAttribute(
        string $attribute,
        array $visible,
        array $hidden
    ): bool {
        return ! in_array($attribute, $hidden, true)
            && ($visible === []
                || in_array($attribute, $visible, true));
    }

    private function defaultEagerLoads(Model $model): array
    {
        try {
            $property = (new ReflectionClass($model))->getProperty('with');
            $value = $property->getValue($model);
        } catch (Throwable) {
            return [];
        }

        return is_array($value) ? $value : [];
    }

    private function analyzeSerializedAccessor(
        string $modelClass,
        string $attribute,
        array $alias,
        array &$accesses
    ): void {
        $legacy = 'get' . Str::studly($attribute) . 'Attribute';
        $methodName = method_exists($modelClass, $legacy)
            ? $legacy
            : (method_exists($modelClass, $attribute)
                ? $attribute
                : null);

        if ($methodName === null) {
            return;
        }

        try {
            $method = new ReflectionMethod($modelClass, $methodName);
            $methodNode = $this->parseMethod($method);
        } catch (Throwable) {
            return;
        }

        if ($methodNode === null) {
            return;
        }

        foreach ((new NodeFinder())->findInstanceOf(
            $methodNode->stmts ?? [],
            Node\Stmt\Return_::class
        ) as $return) {
            if ($return->expr instanceof Node\Expr) {
                $this->collectExpression(
                    $return->expr,
                    ['this' => $alias],
                    $accesses
                );
            }
        }
    }

    private function appendSerializedProperty(
        array $alias,
        string $property
    ): array {
        $alias['accesses'][] = [
            'type' => 'property',
            'name' => $property,
        ];

        return $alias;
    }

    private function loadedRelationships(
        Node\Stmt\ClassMethod $method,
        array $alias
    ): array {
        if (($alias['accesses'] ?? []) !== []) {
            return [];
        }

        $root = $alias['root'] ?? null;

        if (! is_string($root)) {
            return [];
        }

        $tree = [];

        foreach ((new NodeFinder())->findInstanceOf(
            $method->stmts ?? [],
            Node\Expr\MethodCall::class
        ) as $call) {
            if (! $call->name instanceof Node\Identifier
                || ! in_array(
                    $call->name->toString(),
                    ['load', 'loadMissing'],
                    true
                )
                || ! $call->var instanceof Node\Expr\Variable
                || $call->var->name !== $root) {
                continue;
            }

            foreach ($this->relationshipNames($call->args) as $name) {
                $this->addRelationshipPath($tree, $name);
            }
        }

        foreach ((new NodeFinder())->findInstanceOf(
            $method->stmts ?? [],
            Node\Expr\Assign::class
        ) as $assignment) {
            if (! $assignment->var instanceof Node\Expr\Variable
                || $assignment->var->name !== $root) {
                continue;
            }

            foreach ((new NodeFinder())->findInstanceOf(
                [$assignment->expr],
                Node\Expr\StaticCall::class
            ) as $call) {
                if (! $call->name instanceof Node\Identifier
                    || $call->name->toString() !== 'with') {
                    continue;
                }

                foreach ($this->relationshipNames($call->args) as $name) {
                    $this->addRelationshipPath($tree, $name);
                }
            }
        }

        return $tree;
    }

    private function relationshipNames(array $arguments): array
    {
        $names = [];

        foreach ($arguments as $argument) {
            $value = $argument->value;

            if ($value instanceof Node\Scalar\String_) {
                $names[] = $value->value;
            }

            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    if ($item?->value instanceof Node\Scalar\String_) {
                        $names[] = $item->value->value;
                    }
                }
            }
        }

        return $names;
    }

    private function addRelationshipPath(array &$tree, string $path): void
    {
        $current =& $tree;

        foreach (array_filter(explode('.', $path)) as $segment) {
            $current[$segment] ??= [];
            $current =& $current[$segment];
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

            foreach ((new NodeFinder())->findInstanceOf(
                [$return->expr],
                Node\Expr\StaticCall::class
            ) as $staticCall) {
                if (! $staticCall->name instanceof Node\Identifier
                    || ! in_array(
                        $staticCall->name->toString(),
                        ['make', 'collection'],
                        true
                    )
                    || ! isset($staticCall->args[0])) {
                    continue;
                }

                $nestedClass = $this->resourceClass(
                    $staticCall->class
                );
                $nestedAlias = $this->trackedExpression(
                    $staticCall->args[0]->value,
                    $aliases
                );

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
            $access['protectedAccessCount'] ?? null,
            $access['protectedAccessIndices'] ?? [],
        ])] = $access;
    }

    private function trackedExpression(
        Node\Expr $expression,
        array $aliases
    ): ?array {
        $conditional = $this->conditionalHelperAlias(
            $expression,
            $aliases
        );

        if ($conditional !== null) {
            return $conditional;
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

        if ($current instanceof Node\Expr\NullsafePropertyFetch
            || ! $current instanceof Node\Expr\Variable
            || ! is_string($current->name)) {
            return null;
        }

        $aliasKey = $current->name;

        if ($aliasKey === 'this'
            && isset($properties[0]['name'])
            && isset($aliases['this->' . $properties[0]['name']])) {
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
            'type' => $alias['type'],
            'accesses' => [...$alias['accesses'], ...$properties],
        ];

        if (is_array($alias['input'] ?? null)) {
            $access['input'] = $alias['input'];
        }

        if (isset($alias['protectedAccessCount'])) {
            $access['protectedAccessCount']
                = $alias['protectedAccessCount'];
        }

        if (isset($alias['protectedAccessIndices'])) {
            $access['protectedAccessIndices']
                = $alias['protectedAccessIndices'];
        }

        return $access;
    }

    private function conditionalHelperAlias(
        Node\Expr $expression,
        array $aliases
    ): ?array {
        if (! $expression instanceof Node\Expr\MethodCall
            || ! $expression->name instanceof Node\Identifier) {
            return null;
        }

        $helper = $expression->name->toString();

        if ($helper === 'whenLoaded'
            && ($expression->args[0]->value ?? null)
                instanceof Node\Scalar\String_) {
            $base = $this->trackedExpression(
                $expression->var,
                $aliases
            );

            if ($base === null) {
                return null;
            }

            $base['accesses'][] = [
                'type' => 'property',
                'name' => $expression->args[0]->value->value,
            ];
            $base['protectedAccessCount'] = max(
                $base['protectedAccessCount'] ?? 0,
                count($base['accesses'])
            );

            return $base;
        }

        if ($helper === 'whenNotNull' && isset($expression->args[0])) {
            $alias = $this->trackedExpression(
                $expression->args[0]->value,
                $aliases
            );

            if ($alias !== null) {
                $alias['protectedAccessCount'] = max(
                    $alias['protectedAccessCount'] ?? 0,
                    count($alias['accesses'])
                );
            }

            return $alias;
        }

        return null;
    }

    private function withConditionalProtection(
        Node\Expr\PropertyFetch $property,
        array $access,
        array $aliases
    ): array {
        $child = $property;

        while (($parent = $child->getAttribute('parent')) instanceof Node) {
            if ($parent instanceof Node\Arg) {
                $call = $parent->getAttribute('parent');

                if ($call instanceof Node\Expr\MethodCall
                    && $call->name instanceof Node\Identifier) {
                    $argumentIndex = array_search(
                        $parent,
                        $call->args,
                        true
                    );
                    $helper = $call->name->toString();

                    if (in_array(
                        $helper,
                        ['when', 'mergeWhen'],
                        true
                    )) {
                        if ($argumentIndex === 0) {
                            $access['protectedAccessIndices'][]
                                = count($access['accesses']) - 1;
                        } elseif (is_int($argumentIndex)
                            && $argumentIndex > 0
                            && isset($call->args[0])) {
                            $access = $this->withConditionGuards(
                                $access,
                                $call->args[0]->value,
                                $aliases,
                                $argumentIndex === 1
                            );
                        }
                    }

                    if ($helper === 'whenNotNull'
                        && $argumentIndex === 0) {
                        $access['protectedAccessIndices'][]
                            = count($access['accesses']) - 1;
                    }
                }
            }

            $child = $parent;
        }

        if (isset($access['protectedAccessIndices'])) {
            $access['protectedAccessIndices'] = array_values(
                array_unique($access['protectedAccessIndices'])
            );
        }

        return $access;
    }

    private function withConditionGuards(
        array $access,
        Node\Expr $condition,
        array $aliases,
        bool $truthy
    ): array {
        $protected = $access['protectedAccessCount'] ?? 0;

        foreach ((new ConditionGuardAnalyzer())->analyze(
            $condition,
            $truthy
        ) as $guardExpression) {
            $guard = $this->trackedExpression(
                $guardExpression,
                $aliases
            );

            if ($guard !== null
                && $this->isAccessPrefix($guard, $access)) {
                $protected = max(
                    $protected,
                    count($guard['accesses'])
                );
            }
        }

        if ($protected > 0) {
            $access['protectedAccessCount'] = $protected;
        }

        return $access;
    }

    private function isAccessPrefix(array $prefix, array $access): bool
    {
        if (($prefix['root'] ?? null) !== ($access['root'] ?? null)) {
            return false;
        }

        $prefixNames = array_column($prefix['accesses'] ?? [], 'name');
        $accessNames = array_column($access['accesses'] ?? [], 'name');

        return $prefixNames !== []
            && $prefixNames === array_slice(
                $accessNames,
                0,
                count($prefixNames)
            );
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
