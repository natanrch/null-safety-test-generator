<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Natan\NullSafetyTestGenerator\Inspectors\DatabaseColumnInspector;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

class RequestValidationAnalyzer
{
    private DatabaseColumnInspector $columnInspector;

    public function __construct(?DatabaseColumnInspector $columnInspector = null)
    {
        $this->columnInspector = $columnInspector
            ?? new DatabaseColumnInspector();
    }

    public function analyze(
        string $controllerClass,
        string $controllerMethod
    ): array {
        try {
            $method = new ReflectionMethod(
                $controllerClass,
                $controllerMethod
            );
        } catch (Throwable) {
            return ['fields' => [], 'payload' => []];
        }

        $requestParameters = $this->requestParameters($method);
        $ruleSets = [];

        foreach ($requestParameters as $request) {
            if (is_subclass_of($request['class'], FormRequest::class)) {
                $rules = $this->formRequestRules($request['class']);

                if ($rules !== []) {
                    $ruleSets[] = ['source' => 'form_request', 'rules' => $rules];
                }
            }
        }

        $methodNode = $this->parseMethod($method, $controllerMethod);

        if ($methodNode !== null) {
            $ruleSets = [
                ...$ruleSets,
                ...$this->inlineRuleSets(
                    $methodNode,
                    array_column($requestParameters, 'variable')
                ),
            ];
        }

        $fields = [];

        foreach ($ruleSets as $ruleSet) {
            foreach ($ruleSet['rules'] as $field => $rules) {
                $fields[$field] = [
                    'rules' => $rules,
                    'source' => $ruleSet['source'],
                    'value' => $this->valueForRules($rules),
                ];
            }
        }

        $result = [
            'fields' => $fields,
            'payload' => array_map(
                static fn (array $field): mixed => $field['value'],
                $fields
            ),
        ];

        if ($methodNode !== null) {
            $dependencies = $this->existsDependencies(
                $fields,
                $methodNode,
                array_column($requestParameters, 'variable')
            );

            if ($dependencies !== []) {
                $result['dependencies'] = $dependencies;
            }
        }

        return $result;
    }

    private function existsDependencies(
        array $fields,
        Node\Stmt\ClassMethod $method,
        array $requestVariables
    ): array {
        $dependencies = [];

        foreach ($fields as $field => $metadata) {
            $exists = $this->existsRule($metadata['rules'] ?? []);

            if ($exists === null) {
                continue;
            }

            [$table, $column] = $exists;
            $loadedModel = $this->findLoadedModel(
                $method,
                $requestVariables,
                $field
            );
            $modelClass = $loadedModel['model']
                ?? $this->modelForTable($table);

            if ($modelClass === null) {
                continue;
            }

            $accessedProperties = isset($loadedModel['variable'])
                ? $this->accessedProperties(
                    $method,
                    $loadedModel['variable']
                )
                : [];
            $nullableProperties = array_values(array_filter(
                $accessedProperties,
                fn (string $property): bool =>
                    ($this->columnInspector->inspect(
                        $modelClass,
                        $property
                    )['nullable'] ?? null) === true
            ));

            $dependencies[$field] = [
                'model' => $modelClass,
                'table' => $table,
                'column' => $column,
                'variable' => $loadedModel['variable']
                    ?? Str::camel(class_basename($modelClass)),
                'accessedProperties' => $accessedProperties,
                'nullableProperties' => $nullableProperties,
            ];
        }

        return $dependencies;
    }

    private function existsRule(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (! is_string($rule) || ! str_starts_with($rule, 'exists:')) {
                continue;
            }

            $arguments = explode(',', substr($rule, 7));
            $table = trim($arguments[0] ?? '');
            $column = trim($arguments[1] ?? 'id');

            if ($table !== '' && $column !== '') {
                return [$table, $column];
            }
        }

        return null;
    }

    private function findLoadedModel(
        Node\Stmt\ClassMethod $method,
        array $requestVariables,
        string $field
    ): ?array {
        foreach ((new NodeFinder())->findInstanceOf(
            $method->stmts ?? [],
            Node\Expr\Assign::class
        ) as $assignment) {
            if (
                ! $assignment->var instanceof Node\Expr\Variable
                || ! is_string($assignment->var->name)
                || ! $assignment->expr instanceof Node\Expr\StaticCall
                || ! $assignment->expr->class instanceof Node\Name
                || ! $assignment->expr->name instanceof Node\Identifier
                || ! in_array(
                    $assignment->expr->name->toString(),
                    ['find', 'findOrFail'],
                    true
                )
            ) {
                continue;
            }

            $argument = $assignment->expr->args[0]->value ?? null;

            if (! $this->isRequestField(
                $argument,
                $requestVariables,
                $field
            )) {
                continue;
            }

            return [
                'model' => $assignment->expr->class->toString(),
                'variable' => $assignment->var->name,
            ];
        }

        return null;
    }

    private function isRequestField(
        mixed $expression,
        array $requestVariables,
        string $field
    ): bool {
        if (
            $expression instanceof Node\Expr\PropertyFetch
            && $expression->var instanceof Node\Expr\Variable
            && is_string($expression->var->name)
            && in_array($expression->var->name, $requestVariables, true)
            && $expression->name instanceof Node\Identifier
        ) {
            return $expression->name->toString() === $field;
        }

        return $expression instanceof Node\Expr\MethodCall
            && $expression->var instanceof Node\Expr\Variable
            && is_string($expression->var->name)
            && in_array($expression->var->name, $requestVariables, true)
            && $expression->name instanceof Node\Identifier
            && in_array(
                $expression->name->toString(),
                ['input', 'get', 'integer'],
                true
            )
            && ($expression->args[0]->value ?? null)
                instanceof Node\Scalar\String_
            && $expression->args[0]->value->value === $field;
    }

    private function accessedProperties(
        Node\Stmt\ClassMethod $method,
        string $variable
    ): array {
        $properties = [];

        foreach ((new NodeFinder())->findInstanceOf(
            $method->stmts ?? [],
            Node\Expr\PropertyFetch::class
        ) as $propertyFetch) {
            if (
                $propertyFetch->var instanceof Node\Expr\Variable
                && $propertyFetch->var->name === $variable
                && $propertyFetch->name instanceof Node\Identifier
            ) {
                $properties[$propertyFetch->name->toString()] = true;
            }
        }

        return array_keys($properties);
    }

    private function modelForTable(string $table): ?string
    {
        foreach (get_declared_classes() as $className) {
            if (! is_subclass_of($className, Model::class)) {
                continue;
            }

            try {
                if ((new $className())->getTable() === $table) {
                    return $className;
                }
            } catch (Throwable) {
                continue;
            }
        }

        $conventionalClass = 'App\\Models\\'
            . Str::studly(Str::singular($table));

        return class_exists($conventionalClass)
            && is_subclass_of($conventionalClass, Model::class)
                ? $conventionalClass
                : null;
    }

    private function requestParameters(ReflectionMethod $method): array
    {
        $parameters = [];

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
                $parameters[] = [
                    'variable' => $parameter->getName(),
                    'class' => $className,
                ];
            }
        }

        return $parameters;
    }

    private function formRequestRules(string $requestClass): array
    {
        if (! method_exists($requestClass, 'rules')) {
            return [];
        }

        $method = new ReflectionMethod($requestClass, 'rules');
        $methodNode = $this->parseMethod($method, 'rules');

        if ($methodNode === null) {
            return [];
        }

        $return = (new NodeFinder())->findFirstInstanceOf(
            $methodNode->stmts ?? [],
            Node\Stmt\Return_::class
        );

        return $return instanceof Node\Stmt\Return_
            && $return->expr instanceof Node\Expr\Array_
                ? $this->parseRulesArray($return->expr)
                : [];
    }

    private function inlineRuleSets(
        Node\Stmt\ClassMethod $method,
        array $requestVariables
    ): array {
        $finder = new NodeFinder();
        $ruleSets = [];

        foreach ($finder->findInstanceOf(
            $method->stmts ?? [],
            Node\Expr\MethodCall::class
        ) as $call) {
            if (
                ! $call->name instanceof Node\Identifier
                || $call->name->toString() !== 'validate'
                || ! $call->var instanceof Node\Expr\Variable
                || ! is_string($call->var->name)
                || ! in_array($call->var->name, $requestVariables, true)
                || ! ($call->args[0]->value ?? null)
                    instanceof Node\Expr\Array_
            ) {
                continue;
            }

            $ruleSets[] = [
                'source' => 'inline',
                'rules' => $this->parseRulesArray($call->args[0]->value),
            ];
        }

        foreach ($finder->findInstanceOf(
            $method->stmts ?? [],
            Node\Expr\StaticCall::class
        ) as $call) {
            if (
                ! $call->class instanceof Node\Name
                || ! str_ends_with($call->class->toString(), '\\Validator')
                || ! $call->name instanceof Node\Identifier
                || $call->name->toString() !== 'make'
                || ! ($call->args[1]->value ?? null)
                    instanceof Node\Expr\Array_
            ) {
                continue;
            }

            $ruleSets[] = [
                'source' => 'validator',
                'rules' => $this->parseRulesArray($call->args[1]->value),
            ];
        }

        return $ruleSets;
    }

    private function parseRulesArray(Node\Expr\Array_ $array): array
    {
        $rules = [];

        foreach ($array->items as $item) {
            if (
                $item === null
                || ! $item->key instanceof Node\Scalar\String_
            ) {
                continue;
            }

            $fieldRules = $this->parseRuleValue($item->value);

            if ($fieldRules !== []) {
                $rules[$item->key->value] = $fieldRules;
            }
        }

        return $rules;
    }

    private function parseRuleValue(Node\Expr $value): array
    {
        if ($value instanceof Node\Scalar\String_) {
            return array_values(array_filter(explode('|', $value->value)));
        }

        if (! $value instanceof Node\Expr\Array_) {
            return [];
        }

        $rules = [];

        foreach ($value->items as $item) {
            if ($item?->value instanceof Node\Scalar\String_) {
                $rules[] = $item->value->value;
                continue;
            }

            if (
                $item?->value instanceof Node\Expr\StaticCall
                && $item->value->name instanceof Node\Identifier
                && $item->value->name->toString() === 'in'
                && ($item->value->args[0]->value ?? null)
                    instanceof Node\Expr\Array_
            ) {
                $values = [];

                foreach ($item->value->args[0]->value->items as $inItem) {
                    if ($inItem?->value instanceof Node\Scalar) {
                        $values[] = (string) $inItem->value->value;
                    }
                }

                if ($values !== []) {
                    $rules[] = 'in:' . implode(',', $values);
                }
            }
        }

        return $rules;
    }

    private function valueForRules(array $rules): mixed
    {
        foreach ($rules as $rule) {
            if (str_starts_with($rule, 'in:')) {
                return explode(',', substr($rule, 3))[0];
            }
        }

        if ($this->hasRule($rules, ['accepted'])) {
            return 'yes';
        }

        if ($this->hasRule($rules, ['boolean'])) {
            return true;
        }

        if ($this->hasRule($rules, ['integer', 'numeric', 'exists'])) {
            return 1;
        }

        if ($this->hasRule($rules, ['array'])) {
            return [];
        }

        if ($this->hasRule($rules, ['date', 'date_format'])) {
            return '2026-01-01';
        }

        if ($this->hasRule($rules, ['email'])) {
            return 'test@example.com';
        }

        if ($this->hasRule($rules, ['uuid'])) {
            return '00000000-0000-4000-8000-000000000001';
        }

        return 'test';
    }

    private function hasRule(array $rules, array $names): bool
    {
        foreach ($rules as $rule) {
            $name = explode(':', $rule, 2)[0];

            if (in_array($name, $names, true)) {
                return true;
            }
        }

        return false;
    }

    private function parseMethod(
        ReflectionMethod $method,
        string $methodName
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

        $node = (new NodeFinder())->findFirst(
            $ast,
            fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $methodName
        );

        return $node instanceof Node\Stmt\ClassMethod ? $node : null;
    }
}
