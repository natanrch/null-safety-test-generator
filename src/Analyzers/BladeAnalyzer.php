<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use PhpParser\Node;
use PhpParser\ParserFactory;

class BladeAnalyzer
{
    public function analyze(string $viewPath): array
    {
        $blade = file_get_contents($viewPath);

        if ($blade === false) {
            return [];
        }

        $php = $this->compileSupportedSyntax($blade);

        $parser = (new ParserFactory())
            ->createForNewestSupportedVersion();

        $ast = $parser->parse($php);

        if ($ast === null) {
            return [];
        }

        $accesses = [];

        $this->analyzeStatements($ast, [], $accesses);

        return $accesses;
    }

    private function compileSupportedSyntax(string $blade): string
    {
        $php = preg_replace_callback(
            '/{{\s*(.*?)\s*}}/s',
            static fn (array $matches): string => sprintf(
                '<?php echo %s; ?>',
                $matches[1]
            ),
            $blade
        );

        if ($php === null) {
            return $blade;
        }

        $php = preg_replace(
            '/@foreach\s*\((.*)\)/',
            '<?php foreach ($1): ?>',
            $php
        );

        if ($php === null) {
            return $blade;
        }

        return str_replace(
            '@endforeach',
            '<?php endforeach; ?>',
            $php
        );
    }

    private function analyzeStatements(
        array $statements,
        array $aliases,
        array &$results
    ): void {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Echo_) {
                foreach ($statement->exprs as $expression) {
                    $access = $this->analyzeExpression(
                        $expression,
                        $aliases
                    );

                    if ($access !== null && $access['accesses'] !== []) {
                        $results[] = $access;
                    }
                }

                continue;
            }

            if (! $statement instanceof Node\Stmt\Foreach_) {
                continue;
            }

            $foreachAliases = $aliases;

            if (
                $statement->expr instanceof Node\Expr\Variable
                && is_string($statement->expr->name)
                && $statement->valueVar instanceof Node\Expr\Variable
                && is_string($statement->valueVar->name)
            ) {
                $collectionName = $statement->expr->name;

                $foreachAliases[$statement->valueVar->name] =
                    $aliases[$collectionName] ?? $collectionName;
            }

            $this->analyzeStatements(
                $statement->stmts,
                $foreachAliases,
                $results
            );
        }
    }

    private function analyzeExpression(
        Node\Expr $expression,
        array $aliases
    ): ?array {
        if ($expression instanceof Node\Expr\Variable) {
            if (! is_string($expression->name)) {
                return null;
            }

            if (array_key_exists($expression->name, $aliases)) {
                return [
                    'root' => $aliases[$expression->name],
                    'alias' => $expression->name,
                    'accesses' => [],
                ];
            }

            return [
                'root' => $expression->name,
                'accesses' => [],
            ];
        }

        if (
            $expression instanceof Node\Expr\PropertyFetch
            && $expression->name instanceof Node\Identifier
        ) {
            $result = $this->analyzeExpression(
                $expression->var,
                $aliases
            );

            if ($result === null) {
                return null;
            }

            $result['accesses'][] = [
                'type' => 'property',
                'name' => $expression->name->toString(),
            ];

            return $result;
        }

        if (
            $expression instanceof Node\Expr\MethodCall
            && $expression->name instanceof Node\Identifier
        ) {
            $result = $this->analyzeExpression(
                $expression->var,
                $aliases
            );

            if ($result === null) {
                return null;
            }

            $result['accesses'][] = [
                'type' => 'method',
                'name' => $expression->name->toString(),
            ];

            return $result;
        }

        return null;
    }
}
