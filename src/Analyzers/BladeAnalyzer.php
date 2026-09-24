<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Natan\NullSafetyTestGenerator\Resolvers\ViewPathResolver;
use PhpParser\Node;
use PhpParser\ParserFactory;

class BladeAnalyzer
{
    public function analyze(
        string $viewPath,
        ?ViewPathResolver $viewPathResolver = null
    ): array {
        $visitedPaths = [];

        return $this->analyzeFile(
            $viewPath,
            $viewPathResolver,
            $visitedPaths
        );
    }

    private function analyzeFile(
        string $viewPath,
        ?ViewPathResolver $viewPathResolver,
        array &$visitedPaths
    ): array
    {
        $resolvedViewPath = realpath($viewPath);

        if (
            $resolvedViewPath === false
            || isset($visitedPaths[$resolvedViewPath])
        ) {
            return [];
        }

        $visitedPaths[$resolvedViewPath] = true;
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

        foreach ($this->extractIncludedViewNames($blade) as $viewName) {
            $includedPath = $this->resolveIncludedViewPath(
                $viewName,
                $resolvedViewPath,
                $viewPathResolver
            );

            if ($includedPath === null) {
                continue;
            }

            $accesses = [
                ...$accesses,
                ...$this->analyzeFile(
                    $includedPath,
                    $viewPathResolver,
                    $visitedPaths
                ),
            ];
        }

        return $this->uniqueAccesses($accesses);
    }

    private function extractIncludedViewNames(string $blade): array
    {
        $matched = preg_match_all(
            '/@include(?:If)?\s*\(\s*([\'\"])([^\'\"]+)\1/',
            $blade,
            $matches
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        return array_values(array_unique($matches[2]));
    }

    private function resolveIncludedViewPath(
        string $viewName,
        string $parentPath,
        ?ViewPathResolver $viewPathResolver
    ): ?string {
        $resolvedByLaravel = $viewPathResolver?->resolve($viewName);

        if ($resolvedByLaravel !== null) {
            return $resolvedByLaravel;
        }

        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $viewName)
            . '.blade.php';
        $directory = dirname($parentPath);

        for ($depth = 0; $depth < 10; $depth++) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $relativePath;
            $resolvedCandidate = realpath($candidate);

            if (
                $resolvedCandidate !== false
                && is_file($resolvedCandidate)
            ) {
                return $resolvedCandidate;
            }

            $parentDirectory = dirname($directory);

            if ($parentDirectory === $directory) {
                break;
            }

            $directory = $parentDirectory;
        }

        return null;
    }

    private function uniqueAccesses(array $accesses): array
    {
        $unique = [];

        foreach ($accesses as $access) {
            $key = serialize($access);
            $unique[$key] = $access;
        }

        return array_values($unique);
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

        foreach ([
            'foreach' => static fn (string $expression): string =>
                '<?php foreach (' . $expression . '): ?>',
            'elseif' => static fn (string $expression): string =>
                '<?php elseif (' . $expression . '): ?>',
            'unless' => static fn (string $expression): string =>
                '<?php if (!(' . $expression . ')): ?>',
            'if' => static fn (string $expression): string =>
                '<?php if (' . $expression . '): ?>',
        ] as $directive => $compiler) {
            $php = $this->compileParenthesizedDirective(
                $php,
                $directive,
                $compiler
            );
        }

        return str_replace(
            ['@endforeach', '@endif', '@endunless', '@else'],
            [
                '<?php endforeach; ?>',
                '<?php endif; ?>',
                '<?php endif; ?>',
                '<?php else: ?>',
            ],
            $php
        );
    }

    private function compileParenthesizedDirective(
        string $blade,
        string $directive,
        callable $compiler
    ): string {
        $offset = 0;
        $needle = '@' . $directive;

        while (($start = strpos($blade, $needle, $offset)) !== false) {
            $openParenthesis = $start + strlen($needle);

            while (
                isset($blade[$openParenthesis])
                && ctype_space($blade[$openParenthesis])
            ) {
                $openParenthesis++;
            }

            if (($blade[$openParenthesis] ?? null) !== '(') {
                $offset = $openParenthesis;
                continue;
            }

            $closeParenthesis = $this->findClosingParenthesis(
                $blade,
                $openParenthesis
            );

            if ($closeParenthesis === null) {
                break;
            }

            $expression = substr(
                $blade,
                $openParenthesis + 1,
                $closeParenthesis - $openParenthesis - 1
            );
            $replacement = $compiler($expression);
            $length = $closeParenthesis - $start + 1;
            $blade = substr_replace(
                $blade,
                $replacement,
                $start,
                $length
            );
            $offset = $start + strlen($replacement);
        }

        return $blade;
    }

    private function findClosingParenthesis(
        string $value,
        int $openParenthesis
    ): ?int {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($value);

        for ($index = $openParenthesis; $index < $length; $index++) {
            $character = $value[$index];

            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($character === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function analyzeStatements(
        array $statements,
        array $aliases,
        array &$results
    ): void 
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Echo_) {
                foreach ($statement->exprs as $expression) {
                    $this->collectExpressionAccesses(
                        $expression,
                        $aliases,
                        $results
                    );
                }

                continue;
            }

            if ($statement instanceof Node\Stmt\If_) {
                $this->collectExpressionAccesses(
                    $statement->cond,
                    $aliases,
                    $results
                );
                $this->analyzeStatements(
                    $statement->stmts,
                    $aliases,
                    $results
                );

                foreach ($statement->elseifs as $elseif) {
                    $this->collectExpressionAccesses(
                        $elseif->cond,
                        $aliases,
                        $results
                    );
                    $this->analyzeStatements(
                        $elseif->stmts,
                        $aliases,
                        $results
                    );
                }

                if ($statement->else !== null) {
                    $this->analyzeStatements(
                        $statement->else->stmts,
                        $aliases,
                        $results
                    );
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

    private function collectExpressionAccesses(
        Node\Expr $expression,
        array $aliases,
        array &$results
    ): void {
        $access = $this->analyzeExpression($expression, $aliases);

        if (
            $access !== null
            && $access['accesses'] !== []
            && ($access['nullsafe'] ?? false) !== true
        ) {
            unset($access['nullsafe']);
            $results[] = $access;
        }

        if (
            $expression instanceof Node\Expr\MethodCall
            || $expression instanceof Node\Expr\NullsafeMethodCall
            || $expression instanceof Node\Expr\StaticCall
            || $expression instanceof Node\Expr\FuncCall
        ) {
            if (
                $expression instanceof Node\Expr\FuncCall
                && $expression->name instanceof Node\Name
                && strtolower($expression->name->toString()) === 'is_null'
            ) {
                return;
            }

            foreach ($expression->args as $argument) {
                $this->collectExpressionAccesses(
                    $argument->value,
                    $aliases,
                    $results
                );
            }

            return;
        }

        if ($expression instanceof Node\Expr\Ternary) {
            $this->collectExpressionAccesses(
                $expression->cond,
                $aliases,
                $results
            );

            if ($expression->if !== null) {
                $this->collectExpressionAccesses(
                    $expression->if,
                    $aliases,
                    $results
                );
            }

            $this->collectExpressionAccesses(
                $expression->else,
                $aliases,
                $results
            );

            return;
        }

        if ($expression instanceof Node\Expr\BinaryOp) {
            $this->collectExpressionAccesses(
                $expression->left,
                $aliases,
                $results
            );
            $this->collectExpressionAccesses(
                $expression->right,
                $aliases,
                $results
            );

            return;
        }

        if ($expression instanceof Node\Expr\BooleanNot) {
            $this->collectExpressionAccesses(
                $expression->expr,
                $aliases,
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
            ($expression instanceof Node\Expr\PropertyFetch
                || $expression instanceof Node\Expr\NullsafePropertyFetch)
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

            if ($expression instanceof Node\Expr\NullsafePropertyFetch) {
                $result['nullsafe'] = true;
            }

            return $result;
        }

        if (
            ($expression instanceof Node\Expr\MethodCall
                || $expression instanceof Node\Expr\NullsafeMethodCall)
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

            if ($expression instanceof Node\Expr\NullsafeMethodCall) {
                $result['nullsafe'] = true;
            }

            return $result;
        }

        return null;
    }
}
