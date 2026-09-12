<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use ReflectionNamedType;

class ControllerMethodAnalyzer
{
    public function getObjectClasses(
        string $controllerClass,
        string $method
    ): array {
        return array_merge(
            $this->getParameterClasses($controllerClass, $method),
            $this->getLocalVariableClasses($controllerClass, $method)
        );
    }

    private function getParameterClasses(
        string $controllerClass,
        string $method
    ): array {
        $reflectionMethod = new ReflectionMethod(
            $controllerClass,
            $method
        );

        $classes = [];

        foreach ($reflectionMethod->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            if ($type->isBuiltin()) {
                continue;
            }

            $classes[$parameter->getName()] = $type->getName();
        }

        return $classes;
    }

    private function getLocalVariableClasses(
        string $controllerClass,
        string $method
    ): array {
        $reflectionMethod = new ReflectionMethod(
            $controllerClass,
            $method
        );

        $fileName = $reflectionMethod->getFileName();

        if ($fileName === false) {
            return [];
        }

        $code = file_get_contents($fileName);

        if ($code === false) {
            return [];
        }

        /*
         * 1. Transforma o código PHP em AST.
         */
        $parser = (new ParserFactory())
            ->createForNewestSupportedVersion();

        $ast = $parser->parse($code);

        if ($ast === null) {
            return [];
        }

        /*
         * 2. Resolve namespaces e imports.
         *
         * Exemplo:
         *
         * use App\Models\Proposicao;
         *
         * $proposicao = Proposicao::find(1);
         *
         * Depois do NameResolver:
         *
         * App\Models\Proposicao
         */
        $traverser = new NodeTraverser();

        $traverser->addVisitor(
            new NameResolver()
        );

        $ast = $traverser->traverse($ast);

        /*
         * 3. Procura o método que estamos analisando.
         */
        $nodeFinder = new NodeFinder();

        $classMethod = $nodeFinder->findFirst(
            $ast,
            function (Node $node) use ($method) {
                return $node instanceof Node\Stmt\ClassMethod
                    && $node->name->toString() === $method;
            }
        );

        if (! $classMethod instanceof Node\Stmt\ClassMethod) {
            return [];
        }

        /*
         * 4. Procura atribuições:
         *
         * $variavel = algumaCoisa;
         */
        $assignments = $nodeFinder->findInstanceOf(
            $classMethod->stmts ?? [],
            Node\Expr\Assign::class
        );

        $classes = [];

        foreach ($assignments as $assignment) {

            /*
             * Queremos apenas variáveis simples:
             *
             * $proposicao = ...
             *
             * e não, por enquanto:
             *
             * $this->proposicao = ...
             */
            if (! $assignment->var instanceof Node\Expr\Variable) {
                continue;
            }

            if (! is_string($assignment->var->name)) {
                continue;
            }

            /*
             * Por enquanto reconhecemos chamadas estáticas:
             *
             * Proposicao::find(...)
             * Proposicao::first()
             * Proposicao::create(...)
             */
            if (! $assignment->expr instanceof Node\Expr\StaticCall) {
                continue;
            }

            if (! $assignment->expr->class instanceof Node\Name) {
                continue;
            }

            $variableName = $assignment->var->name;

            /*
             * Como o NameResolver já percorreu a AST,
             * aqui teremos o nome completo da classe.
             */
            $className = $assignment->expr->class->toString();

            $classes[$variableName] = $className;
        }

        return $classes;
    }
}