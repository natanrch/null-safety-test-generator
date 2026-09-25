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

        $relatedClass = $this->getRelatedClass($relationshipCall);

        if ($relatedClass === null) {
            return null;
        }

        return [
            'model' => $modelClass,
            'property' => $property,
            'kind' => 'relationship',
            'relation' => $relationshipType,
            'relatedClass' => $relatedClass,
        ];
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
        Node\Expr\MethodCall $relationshipCall
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

        return $classConstant->class->toString();
    }
}
