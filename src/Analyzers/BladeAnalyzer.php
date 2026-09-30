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
            $visitedPaths,
            []
        );
    }

    private function analyzeFile(
        string $viewPath,
        ?ViewPathResolver $viewPathResolver,
        array &$visitedPaths,
        array $aliases
    ): array
    {
        $resolvedViewPath = realpath($viewPath);

        $visitKey = $resolvedViewPath === false
            ? null
            : $resolvedViewPath . '|' . serialize($aliases);

        if ($resolvedViewPath === false || isset($visitedPaths[$visitKey])) {
            return [];
        }

        $visitedPaths[$visitKey] = true;
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

        $this->analyzeStatements($ast, $aliases, $accesses);

        foreach ($this->extractInheritedViewNames($blade) as $viewName) {
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
                    $visitedPaths,
                    []
                ),
            ];
        }


        foreach ($this->extractBladeComponents($blade) as $component) {
            $includedPath = $this->resolveIncludedViewPath(
                $component['view'],
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
                    $visitedPaths,
                    $component['aliases']
                ),
            ];
        }

        return $this->uniqueAccesses($accesses);
    }

    private function extractInheritedViewNames(string $blade): array
    {
        $matched = preg_match_all(
            '/@(?:include(?:If|When|Unless)?|extends)\s*'
                . '\(\s*([\'\"])([^\'\"]+)\1/',
            $blade,
            $matches
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        return array_values(array_unique($matches[2]));
    }

    private function extractBladeComponents(string $blade): array
    {
        $matched = preg_match_all(
            '/<x-([a-zA-Z0-9_.-]+)\b'
                . '((?:"[^"]*"|\'[^\']*\'|[^>])*)>/',
            $blade,
            $matches,
            PREG_SET_ORDER
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        $components = [];

        foreach ($matches as $match) {
            $view = 'components.' . $match[1];
            $aliases = $this->extractComponentAliases($match[2]);
            $components[$view . '|' . serialize($aliases)] = [
                'view' => $view,
                'aliases' => $aliases,
            ];
        }

        return array_values($components);
    }

    private function extractComponentAliases(string $attributes): array
    {
        $matched = preg_match_all(
            '/:([a-zA-Z_][a-zA-Z0-9_-]*)\s*=\s*"([^"]+)"/',
            $attributes,
            $matches,
            PREG_SET_ORDER
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        $aliases = [];

        foreach ($matches as $match) {
            $access = $this->parseSimpleBladeAccess($match[2]);

            if ($access !== null) {
                $aliases[$match[1]] = $access;
            }
        }

        return $aliases;
    }

    private function parseSimpleBladeAccess(string $expression): ?array
    {
        $matched = preg_match(
            '/^\$([a-zA-Z_][a-zA-Z0-9_]*)'
                . '((?:->[a-zA-Z_][a-zA-Z0-9_]*)*)$/',
            trim($expression),
            $matches
        );

        if ($matched !== 1) {
            return null;
        }

        preg_match_all(
            '/->([a-zA-Z_][a-zA-Z0-9_]*)/',
            $matches[2],
            $properties
        );

        return [
            'root' => $matches[1],
            'accesses' => array_map(
                static fn (string $property): array => [
                    'type' => 'property',
                    'name' => $property,
                ],
                $properties[1]
            ),
        ];
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
        $bladeWithoutComments = preg_replace(
            '/{{--.*?--}}/s',
            '',
            $blade
        );

        if ($bladeWithoutComments === null) {
            return $blade;
        }

        $php = preg_replace_callback(
            '/{{\s*(.*?)\s*}}/s',
            static fn (array $matches): string => sprintf(
                '<?php echo %s; ?>',
                $matches[1]
            ),
            $bladeWithoutComments
        );

        if ($php === null) {
            return $blade;
        }

        $php = preg_replace(
            '/@php\b(?!\s*\()/',
            '<?php ',
            $php
        );

        if ($php === null) {
            return $blade;
        }

        $php = str_replace('@endphp', ' ?>', $php);

        foreach ([
            'forelse' => static fn (string $expression): string =>
                '<?php foreach (' . $expression . '): ?>',
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

        $php = preg_replace(
            '/@empty(?!\s*\()/',
            '<?php endforeach; if (true): ?>',
            $php
        );

        if ($php === null) {
            return $blade;
        }

        return str_replace(
            [
                '@endforelse',
                '@endforeach',
                '@endif',
                '@endunless',
                '@else',
            ],
            [
                '<?php endif; ?>',
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
            if (
                $statement instanceof Node\Stmt\Expression
                && $statement->expr instanceof Node\Expr\Assign
                && $statement->expr->var instanceof Node\Expr\Variable
                && is_string($statement->expr->var->name)
            ) {
                $assignment = $statement->expr;

                $this->collectExpressionAccesses(
                    $assignment->expr,
                    $aliases,
                    $results
                );

                $assignedAccess = $this->analyzeExpression(
                    $assignment->expr,
                    $aliases
                );

                if ($assignedAccess !== null) {
                    unset($assignedAccess['alias']);
                    $aliases[$assignment->var->name] = $assignedAccess;
                }

                continue;
            }

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
                $statement->valueVar instanceof Node\Expr\Variable
                && is_string($statement->valueVar->name)
            ) {
                $collectionAccess = $this->analyzeExpression(
                    $statement->expr,
                    $aliases
                );

                if ($collectionAccess !== null) {
                    unset($collectionAccess['alias']);
                    $foreachAliases[$statement->valueVar->name] =
                        $collectionAccess;
                }
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
        array &$results,
        string $usage = 'direct_output'
    ): void {
        $access = $this->analyzeExpression($expression, $aliases);

        if (
            $access !== null
            && $access['accesses'] !== []
            && ($access['nullsafe'] ?? false) !== true
        ) {
            unset($access['nullsafe']);

            if ($this->isNullSensitiveUsage($usage)) {
                $access['usage'] = $usage;
            }

            $results[] = $access;
        }

        if ($expression instanceof Node\Expr\FuncCall) {
            $this->collectFunctionArguments($expression, $aliases, $results);

            return;
        }

        if (
            $expression instanceof Node\Expr\MethodCall
            || $expression instanceof Node\Expr\NullsafeMethodCall
        ) {
            $this->collectMethodArguments($expression, $aliases, $results);

            return;
        }

        if ($expression instanceof Node\Expr\StaticCall) {
            $this->collectStaticMethodArguments(
                $expression,
                $aliases,
                $results
            );

            return;
        }

        if ($expression instanceof Node\Expr\ArrayDimFetch) {
            $this->collectArrayAccess($expression, $aliases, $results);

            return;
        }

        if ($expression instanceof Node\Expr\Ternary) {
            $this->collectExpressionAccesses(
                $expression->cond,
                $aliases,
                $results,
                'condition'
            );

            if ($expression->if !== null) {
                $this->collectExpressionAccesses(
                    $expression->if,
                    $aliases,
                    $results,
                    $usage
                );
            }

            $this->collectExpressionAccesses(
                $expression->else,
                $aliases,
                $results,
                $usage
            );

            return;
        }

        if ($expression instanceof Node\Expr\BinaryOp) {
            $binaryUsage = $expression instanceof Node\Expr\BinaryOp\Coalesce
                ? 'null_coalescing'
                : 'binary_operation';

            $this->collectExpressionAccesses(
                $expression->left,
                $aliases,
                $results,
                $binaryUsage
            );
            $this->collectExpressionAccesses(
                $expression->right,
                $aliases,
                $results,
                $binaryUsage
            );

            return;
        }

        if ($expression instanceof Node\Expr\BooleanNot) {
            $this->collectExpressionAccesses(
                $expression->expr,
                $aliases,
                $results,
                'condition'
            );
        }
    }

    private function collectFunctionArguments(
        Node\Expr\FuncCall $expression,
        array $aliases,
        array &$results
    ): void {
        if (
            $expression->name instanceof Node\Name
            && in_array(
                strtolower($expression->name->toString()),
                ['is_null'],
                true
            )
        ) {
            return;
        }

        $this->collectArguments(
            $expression->args,
            $aliases,
            $results,
            'function_argument'
        );
    }

    private function collectMethodArguments(
        Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $expression,
        array $aliases,
        array &$results
    ): void {
        $this->collectArguments(
            $expression->args,
            $aliases,
            $results,
            'method_argument'
        );
    }

    private function collectStaticMethodArguments(
        Node\Expr\StaticCall $expression,
        array $aliases,
        array &$results
    ): void {
        $this->collectArguments(
            $expression->args,
            $aliases,
            $results,
            'static_method_argument'
        );
    }

    private function collectArguments(
        array $arguments,
        array $aliases,
        array &$results,
        string $usage
    ): void {
        foreach ($arguments as $argument) {
            $this->collectExpressionAccesses(
                $argument->value,
                $aliases,
                $results,
                $usage
            );
        }
    }

    private function collectArrayAccess(
        Node\Expr\ArrayDimFetch $expression,
        array $aliases,
        array &$results
    ): void {
        $this->collectExpressionAccesses(
            $expression->var,
            $aliases,
            $results,
            'array_access'
        );

        if ($expression->dim !== null) {
            $this->collectExpressionAccesses(
                $expression->dim,
                $aliases,
                $results,
                'array_access'
            );
        }
    }

    private function isNullSensitiveUsage(string $usage): bool
    {
        return $this->isFunctionArgument($usage)
            || $this->isMethodArgument($usage)
            || $this->isStaticMethodArgument($usage)
            || $this->isBinaryOperation($usage)
            || $this->isArrayAccess($usage);
    }

    private function isFunctionArgument(string $usage): bool
    {
        return $usage === 'function_argument';
    }

    private function isMethodArgument(string $usage): bool
    {
        return $usage === 'method_argument';
    }

    private function isStaticMethodArgument(string $usage): bool
    {
        return $usage === 'static_method_argument';
    }

    private function isBinaryOperation(string $usage): bool
    {
        return $usage === 'binary_operation';
    }

    private function isArrayAccess(string $usage): bool
    {
        return $usage === 'array_access';
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
                $alias = $aliases[$expression->name];

                if (is_string($alias)) {
                    return [
                        'root' => $alias,
                        'alias' => $expression->name,
                        'accesses' => [],
                    ];
                }

                if (
                    is_array($alias)
                    && isset($alias['root'], $alias['accesses'])
                    && is_string($alias['root'])
                    && is_array($alias['accesses'])
                ) {
                    $result = [
                        'root' => $alias['root'],
                        'alias' => $expression->name,
                        'accesses' => $alias['accesses'],
                    ];

                    if (($alias['nullsafe'] ?? false) === true) {
                        $result['nullsafe'] = true;
                    }

                    return $result;
                }
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
