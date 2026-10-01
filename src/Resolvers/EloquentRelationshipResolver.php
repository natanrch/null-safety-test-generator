<?php

namespace Natan\NullSafetyTestGenerator\Resolvers;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;

class EloquentRelationshipResolver
{
    private const RELATIONSHIP_METHODS = [
        'belongsTo',
        'belongsToMany',
        'hasOne',
        'hasMany',
        'hasOneThrough',
        'hasManyThrough',
        'morphOne',
        'morphMany',
        'morphTo',
        'morphToMany',
        'morphedByMany',
    ];

    public function resolve(
        string $modelClass,
        string $property
    ): ?array {
        if (! method_exists($modelClass, $property)) {
            return null;
        }

        $reflectionMethod = new ReflectionMethod(
            $modelClass,
            $property
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

        $method = $nodeFinder->findFirst(
            $ast,
            fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $property
        );

        if (! $method instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        $return = $nodeFinder->findFirstInstanceOf(
            $method->stmts ?? [],
            Node\Stmt\Return_::class
        );

        if (! $return instanceof Node\Stmt\Return_) {
            return null;
        }

        $relationshipCall = $this->findRelationshipCall(
            $return->expr
        );

        if ($relationshipCall === null) {
            return null;
        }

        $relationshipType = $relationshipCall->name->toString();

        if (! in_array($relationshipType, self::RELATIONSHIP_METHODS, true)) {
            return null;
        }

        $relatedClass = $this->getRelatedClass(
            $relationshipCall,
            $modelClass
        );

        if ($relatedClass === null && $relationshipType !== 'morphTo') {
            return null;
        }

        $result = [
            'model' => $modelClass,
            'property' => $property,
            'kind' => 'relationship',
            'relation' => $relationshipType,
        ];

        if ($relatedClass !== null) {
            $result['relatedClass'] = $relatedClass;
        }

        $constraints = $this->getEqualityConstraints($return->expr);

        if ($constraints !== []) {
            $result['constraints'] = $constraints;
        }

        return $result;
    }

    private function getEqualityConstraints(?Node\Expr $expression): array
    {
        $constraints = [];
        $current = $expression;

        while ($current instanceof Node\Expr\MethodCall) {
            if (
                $current->name instanceof Node\Identifier
                && $current->name->toString() === 'where'
                && ($current->args[0]->value ?? null)
                    instanceof Node\Scalar\String_
                && isset($current->args[1])
            ) {
                $value = $this->literalValue($current->args[1]->value);

                if ($value['resolved']) {
                    $constraints[$current->args[0]->value->value]
                        = $value['value'];
                }
            }

            $current = $current->var;
        }

        return array_reverse($constraints, true);
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

    private function findRelationshipCall(
        ?Node\Expr $expression
    ): ?Node\Expr\MethodCall {
        if (! $expression instanceof Node\Expr\MethodCall) {
            return null;
        }

        if (
            $expression->var instanceof Node\Expr\Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Node\Identifier
            && in_array(
                $expression->name->toString(),
                self::RELATIONSHIP_METHODS,
                true
            )
        ) {
            return $expression;
        }

        if ($expression->var instanceof Node\Expr\MethodCall) {
            return $this->findRelationshipCall($expression->var);
        }

        return null;
    }

    private function getRelatedClass(
        Node\Expr\MethodCall $relationshipCall,
        string $modelClass
    ): ?string {
        $argument = $relationshipCall->args[0] ?? null;

        if (
            $argument === null
            || ! $argument->value instanceof Node\Expr\ClassConstFetch
        ) {
            return null;
        }

        $classConstant = $argument->value;

        if (
            ! $classConstant->class instanceof Node\Name
            || ! $classConstant->name instanceof Node\Identifier
            || strtolower($classConstant->name->toString()) !== 'class'
        ) {
            return null;
        }

        $relatedClass = $classConstant->class->toString();

        return match (strtolower($relatedClass)) {
            'self', 'static' => $modelClass,
            'parent' => get_parent_class($modelClass) ?: null,
            default => $relatedClass,
        };
    }
}
