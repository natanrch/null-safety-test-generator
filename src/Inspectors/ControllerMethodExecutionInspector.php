<?php

namespace Natan\NullSafetyTestGenerator\Inspectors;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;

class ControllerMethodExecutionInspector
{
    public const EMPTY_METHOD_MESSAGE =
        'The controller method has no executable statements; no test was generated.';

    public function hasExecutableStatements(
        string $controllerClass,
        string $methodName
    ): ?bool {
        try {
            $method = new ReflectionMethod($controllerClass, $methodName);
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

            $methodNode = (new NodeFinder())->findFirst(
                $ast,
                fn (Node $node): bool =>
                    $node instanceof Node\Stmt\ClassMethod
                    && $node->name->toString() === $methodName
                    && $node->getStartLine() === $method->getStartLine()
            );

            if (! $methodNode instanceof Node\Stmt\ClassMethod) {
                return null;
            }

            foreach ($methodNode->stmts ?? [] as $statement) {
                if (! $statement instanceof Node\Stmt\Nop) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return null;
        }
    }
}
