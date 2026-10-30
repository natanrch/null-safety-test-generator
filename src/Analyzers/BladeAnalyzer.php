<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use Natan\NullSafetyTestGenerator\Resolvers\ViewPathResolver;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
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
        array $aliases,
        array $activePaths = [],
        array $inheritedGuards = []
    ): array
    {
        $resolvedViewPath = realpath($viewPath);

        $visitKey = $resolvedViewPath === false
            ? null
            : $resolvedViewPath . '|' . serialize([$aliases, $inheritedGuards]);

        if ($resolvedViewPath === false || isset($visitedPaths[$visitKey])
            || isset($activePaths[$resolvedViewPath])) {
            return [];
        }

        $visitedPaths[$visitKey] = true;
        $activePaths[$resolvedViewPath] = true;
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
        $traverser = new NodeTraverser(new ParentConnectingVisitor());
        $ast = $traverser->traverse($ast);
        $assignments = (new \PhpParser\NodeFinder())->findInstanceOf($ast, Node\Expr\Assign::class);
        foreach ((new \PhpParser\NodeFinder())->findInstanceOf($ast, Node\Expr::class) as $expression) {
            $expression->setAttribute('inheritedGuards', $inheritedGuards);
            $expression->setAttribute('fileAssignments', $assignments);
        }

        $includedViews = [];
        $this->analyzeStatements(
            $ast,
            $aliases,
            $accesses,
            static function (string $view, array $context, array $guards) use (&$includedViews): void {
                $includedViews[] = ['view' => $view, 'aliases' => $context, 'guards' => $guards];
            }
        );

        foreach ($includedViews as $includedView) {
            $includedPath = $this->resolveIncludedViewPath(
                $includedView['view'],
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
                    $includedView['aliases'],
                    $activePaths,
                    $includedView['guards']
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
                    $component['aliases'],
                    $activePaths
                ),
            ];
        }

        return $this->uniqueAccesses($accesses);
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

        $php = preg_replace_callback(
            '/{!!\s*(.*?)\s*!!}/s',
            static fn (array $matches): string => '<?php echo ' . $matches[1] . '; ?>',
            $php
        ) ?? $php;

        foreach (['includeWhen', 'includeUnless', 'includeIf', 'include', 'extends'] as $directive) {
            $php = $this->compileParenthesizedDirective(
                $php,
                $directive,
                static fn (string $expression): string =>
                    '<?php __null_safety_' . $directive . '(' . $expression . '); ?>'
            );
        }

        foreach (['class', 'style', 'checked', 'selected', 'disabled', 'readonly', 'required'] as $directive) {
            $php = $this->compileParenthesizedDirective(
                $php, $directive,
                static fn (string $expression): string => '<?php echo ' . $expression . '; ?>'
            );
        }

        foreach ([
            'isset' => static fn (string $expression): string =>
                '<?php if (isset(' . $expression . ')): ?>',
            'empty' => static fn (string $expression): string =>
                '<?php if (empty(' . $expression . ')): ?>',
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
                '@endisset',
                '@endempty',
                '@else',
            ],
            [
                '<?php endif; ?>',
                '<?php endforeach; ?>',
                '<?php endif; ?>',
                '<?php endif; ?>',
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
        array &$results,
        ?callable $includeView = null
    ): void 
    {
        foreach ($statements as $statement) {
            foreach ((new \PhpParser\NodeFinder())->findInstanceOf([$statement], Node\Expr::class) as $node) {
                $node->setAttribute('aliasesAtEvaluation', $aliases);
            }
            if ($statement instanceof Node\Stmt\Expression
                && $statement->expr instanceof Node\Expr\FuncCall
                && $statement->expr->name instanceof Node\Name
                && str_starts_with($statement->expr->name->toString(), '__null_safety_')) {
                $this->analyzeIncludedView($statement->expr, $aliases, $results, $includeView);
                continue;
            }
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

                unset($aliases[$assignment->var->name]);
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
                    $results,
                    'condition'
                );
                $this->analyzeStatements(
                    $statement->stmts,
                    $aliases,
                    $results,
                    $includeView
                );

                foreach ($statement->elseifs as $elseif) {
                    $this->collectExpressionAccesses(
                        $elseif->cond,
                        $aliases,
                        $results,
                        'condition'
                    );
                    $this->analyzeStatements(
                        $elseif->stmts,
                        $aliases,
                        $results,
                        $includeView
                    );
                }

                if ($statement->else !== null) {
                    $this->analyzeStatements(
                        $statement->else->stmts,
                        $aliases,
                        $results,
                        $includeView
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
                $results,
                $includeView
            );
        }
    }

    private function analyzeIncludedView(
        Node\Expr\FuncCall $call,
        array $aliases,
        array &$results,
        ?callable $includeView
    ): void {
        $conditional = in_array($call->name->toString(), [
            '__null_safety_includeWhen', '__null_safety_includeUnless',
        ], true);
        $viewIndex = $conditional ? 1 : 0;

        if ($conditional && isset($call->args[0])) {
            $this->collectExpressionAccesses($call->args[0]->value, $aliases, $results, 'condition');
        }

        $view = $call->args[$viewIndex]->value ?? null;
        $data = $call->args[$viewIndex + 1]->value ?? null;
        $context = $aliases;

        if ($data instanceof Node\Expr\Array_) {
            foreach ($data->items as $item) {
                if ($item === null || ! $item->key instanceof Node\Scalar\String_) {
                    continue;
                }
                $access = $this->analyzeExpression($item->value, $aliases);
                unset($context[$item->key->value]);
                if ($access !== null) {
                    $context[$item->key->value] = $access;
                }
            }
        }

        if ($view instanceof Node\Scalar\String_ && $includeView !== null) {
            $guards = $this->resolvedGuards($call, $aliases);
            if ($conditional && isset($call->args[0])) {
                foreach ($this->conditionGuards(
                    $call->args[0]->value,
                    $call->name->toString() === '__null_safety_includeWhen'
                ) as $guard) {
                    $resolved = $this->analyzeExpression($guard, $aliases);
                    if ($resolved !== null) {
                        $guards[] = $resolved;
                    }
                }
            }
            $includeView($view->value, $context, $guards);
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
            $protectedCount = $this->protectedAccessCount($expression, $access, $aliases);
            if ($usage === 'condition' && count($access['accesses']) === 1
                && $expression instanceof Node\Expr\PropertyFetch) {
                $protectedCount = 1;
            }
            if ($usage === 'condition' && count($access['accesses']) > 1
                && $expression instanceof Node\Expr\PropertyFetch) {
                $access['protectedAccessIndices'] = [count($access['accesses']) - 1];
            }
            if ($protectedCount > 0) {
                $access['protectedAccessCount'] = $protectedCount;
            }

            if ($this->isNullSensitiveUsage($usage)) {
                $access['usage'] = $usage;
            }

            $results[] = $access;
        }

        if ($expression instanceof Node\Expr\Array_) {
            foreach ($expression->items as $item) {
                if ($item !== null) {
                    if ($item->key instanceof Node\Expr) {
                        $this->collectExpressionAccesses($item->key, $aliases, $results);
                    }
                    $this->collectExpressionAccesses($item->value, $aliases, $results);
                }
            }
            return;
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
            if ($expression instanceof Node\Expr\BinaryOp\BooleanAnd
                || $expression instanceof Node\Expr\BinaryOp\BooleanOr
                || $expression instanceof Node\Expr\BinaryOp\LogicalAnd
                || $expression instanceof Node\Expr\BinaryOp\LogicalOr) {
                $binaryUsage = 'condition';
            }
            if (($expression instanceof Node\Expr\BinaryOp\Identical
                || $expression instanceof Node\Expr\BinaryOp\NotIdentical)
                && (($expression->left instanceof Node\Expr\ConstFetch && strtolower($expression->left->name->toString()) === 'null')
                    || ($expression->right instanceof Node\Expr\ConstFetch && strtolower($expression->right->name->toString()) === 'null'))) {
                $binaryUsage = 'condition';
            }

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

    private function protectedAccessCount(Node\Expr $expression, array $access, array $aliases): int
    {
        $count = 0;
        foreach ($this->resolvedGuards($expression, $aliases) as $resolved) {
            if ($resolved['root'] === $access['root']
                && array_slice($access['accesses'], 0, count($resolved['accesses'])) === $resolved['accesses']) {
                $count = max($count, count($resolved['accesses']));
            }
        }
        return $count;
    }

    private function resolvedGuards(Node\Expr $expression, array $aliases): array
    {
        $guards = [];
        $child = $expression;
        while (($parent = $child->getAttribute('parent')) instanceof Node) {
            if (($parent instanceof Node\Stmt\If_ || $parent instanceof Node\Stmt\ElseIf_)
                && in_array($child, $parent->stmts, true)) {
                $guards = [...$guards, ...$this->conditionGuards($parent->cond, true)];
            }
            if ($parent instanceof Node\Stmt\Else_) {
                $owner = $parent->getAttribute('parent');
                if ($owner instanceof Node\Stmt\If_) {
                    $guards = [...$guards, ...$this->conditionGuards($owner->cond, false)];
                    foreach ($owner->elseifs as $previous) {
                        $guards = [...$guards, ...$this->conditionGuards($previous->cond, false)];
                    }
                }
            }
            if ($parent instanceof Node\Stmt\ElseIf_) {
                $owner = $parent->getAttribute('parent');
                if ($owner instanceof Node\Stmt\If_) {
                    $guards = [...$guards, ...$this->conditionGuards($owner->cond, false)];
                    foreach ($owner->elseifs as $previous) {
                        if ($previous === $parent) {
                            break;
                        }
                        $guards = [...$guards, ...$this->conditionGuards($previous->cond, false)];
                    }
                }
            }
            if ($parent instanceof Node\Expr\Ternary && $child !== $parent->cond) {
                $guards = [...$guards, ...$this->conditionGuards($parent->cond, $child === $parent->if)];
            }
            if (($parent instanceof Node\Expr\BinaryOp\BooleanAnd || $parent instanceof Node\Expr\BinaryOp\LogicalAnd)
                && $child === $parent->right) {
                $guards = [...$guards, ...$this->conditionGuards($parent->left, true)];
            }
            if (($parent instanceof Node\Expr\BinaryOp\BooleanOr || $parent instanceof Node\Expr\BinaryOp\LogicalOr)
                && $child === $parent->right) {
                $guards = [...$guards, ...$this->conditionGuards($parent->left, false)];
            }
            $child = $parent;
        }
        $resolvedGuards = array_values(array_filter(
            $expression->getAttribute('inheritedGuards', []),
            fn (array $guard): bool => $this->guardSurvivesAssignments($guard, $expression, -1, $aliases)
        ));
        foreach ($guards as $guard) {
            $resolved = $this->analyzeExpression($guard, $guard->getAttribute('aliasesAtEvaluation', $aliases));
            if ($resolved !== null && $this->guardSurvivesAssignments(
                $resolved, $expression, $guard->getEndFilePos(), $aliases
            )) {
                $resolvedGuards[] = $resolved;
            }
        }
        return $resolvedGuards;
    }

    private function guardSurvivesAssignments(array $guard, Node\Expr $expression, int $after, array $aliases): bool
    {
        foreach ($expression->getAttribute('fileAssignments', []) as $assignment) {
            if ($assignment->getStartFilePos() <= $after
                || $assignment->getEndFilePos() >= $expression->getStartFilePos()) {
                continue;
            }
            $target = $this->analyzeExpression(
                $assignment->var,
                $assignment->getAttribute('aliasesAtEvaluation', $aliases)
            );
            if ($target !== null && $target['root'] === $guard['root']) {
                return false;
            }
        }
        return true;
    }

    private function conditionGuards(Node\Expr $condition, bool $truthy): array
    {
        return (new ConditionGuardAnalyzer())->analyze($condition, $truthy);
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
            foreach ($expression->args as $argument) {
                $argumentAccess = $this->analyzeExpression($argument->value, $aliases);
                if ($argumentAccess !== null && count($argumentAccess['accesses']) > 1) {
                    $this->collectExpressionAccesses($argument->value, $aliases, $results, 'condition');
                }
            }
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
