<?php

namespace Natan\NullSafetyTestGenerator\Resolvers;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

class EloquentAttributeTypeResolver
{
    public function resolve(string $className, string $property): ?array
    {
        if (! class_exists($className)) {
            return null;
        }

        return $this->resolveCast($className, $property)
            ?? $this->resolveLegacyAccessor($className, $property)
            ?? $this->resolveModernAccessor($className, $property)
            ?? $this->resolveTypedProperty($className, $property);
    }

    private function resolveCast(string $className, string $property): ?array
    {
        if (! is_subclass_of($className, Model::class)) {
            return null;
        }

        try {
            /** @var Model $model */
            $model = (new ReflectionClass($className))->newInstance();
            $cast = $model->getCasts()[$property] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (! is_string($cast)) {
            return null;
        }

        $castType = strtolower(explode(':', $cast, 2)[0]);
        $valueClass = match ($castType) {
            'date', 'datetime', 'immutable_date', 'immutable_datetime' =>
                Carbon::class,
            default => class_exists($cast) ? $cast : null,
        };

        return $this->objectAttribute(
            $className,
            $property,
            $valueClass,
            'cast'
        );
    }

    private function resolveLegacyAccessor(
        string $className,
        string $property
    ): ?array {
        $method = 'get' . Str::studly($property) . 'Attribute';

        if (! method_exists($className, $method)) {
            return null;
        }

        $valueClass = $this->namedReturnClass(
            new ReflectionMethod($className, $method)
        );

        return $this->objectAttribute(
            $className,
            $property,
            $valueClass,
            'accessor'
        );
    }

    private function resolveModernAccessor(
        string $className,
        string $property
    ): ?array {
        if (! method_exists($className, $property)) {
            return null;
        }

        $method = new ReflectionMethod($className, $property);

        if ($this->namedReturnClass($method) !== Attribute::class) {
            return null;
        }

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
        $finder = new NodeFinder();
        $methodNode = $finder->findFirst(
            $ast,
            fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $property
        );

        if (! $methodNode instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        $newValue = $finder->findFirstInstanceOf(
            $methodNode->stmts ?? [],
            Node\Expr\New_::class
        );
        $valueClass = $newValue instanceof Node\Expr\New_
            && $newValue->class instanceof Node\Name
                ? $newValue->class->toString()
                : null;

        return $this->objectAttribute(
            $className,
            $property,
            $valueClass,
            'accessor'
        );
    }

    private function resolveTypedProperty(
        string $className,
        string $property
    ): ?array {
        if (! property_exists($className, $property)) {
            return null;
        }

        $type = (new ReflectionProperty($className, $property))->getType();
        $valueClass = $type instanceof ReflectionNamedType
            && ! $type->isBuiltin()
                ? $type->getName()
                : null;

        return $this->objectAttribute(
            $className,
            $property,
            $valueClass,
            'value_object'
        );
    }

    private function namedReturnClass(ReflectionMethod $method): ?string
    {
        $type = $method->getReturnType();

        return $type instanceof ReflectionNamedType && ! $type->isBuiltin()
            ? $type->getName()
            : null;
    }

    private function objectAttribute(
        string $className,
        string $property,
        ?string $valueClass,
        string $source
    ): ?array {
        if (
            $valueClass === null
            || ! class_exists($valueClass)
            || $valueClass === DateTimeInterface::class
        ) {
            return null;
        }

        return [
            'model' => $className,
            'property' => $property,
            'kind' => 'attribute',
            'valueClass' => $valueClass,
            'valueSource' => $source,
        ];
    }
}
