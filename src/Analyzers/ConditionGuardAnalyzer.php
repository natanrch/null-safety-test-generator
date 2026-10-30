<?php

namespace Natan\NullSafetyTestGenerator\Analyzers;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/** Non-null facts that hold when a condition evaluates to the requested boolean. */
class ConditionGuardAnalyzer
{
    public function analyze(Node\Expr $condition, bool $truthy): array
    {
        if ($condition instanceof Node\Expr\BooleanNot) {
            return $this->analyze($condition->expr, ! $truthy);
        }
        $and = $condition instanceof Node\Expr\BinaryOp\BooleanAnd
            || $condition instanceof Node\Expr\BinaryOp\LogicalAnd;
        $or = $condition instanceof Node\Expr\BinaryOp\BooleanOr
            || $condition instanceof Node\Expr\BinaryOp\LogicalOr;
        if ($and || $or) {
            $left = $this->keyed($this->analyze($condition->left, $truthy));
            $right = $this->keyed($this->analyze($condition->right, $truthy));
            return array_values(($and && $truthy) || ($or && ! $truthy)
                ? $left + $right : array_intersect_key($left, $right));
        }
        if ($condition instanceof Node\Expr\Isset_) {
            return $truthy ? array_values($this->keyed($condition->vars)) : [];
        }
        if ($condition instanceof Node\Expr\Empty_) {
            return $truthy ? [] : array_values($this->keyed([$condition->expr]));
        }
        if ($condition instanceof Node\Expr\FuncCall && $condition->name instanceof Node\Name
            && strtolower($condition->name->toString()) === 'is_null' && count($condition->args) === 1) {
            return $truthy ? [] : array_values($this->keyed([$condition->args[0]->value]));
        }
        if ($condition instanceof Node\Expr\BinaryOp\Identical
            || $condition instanceof Node\Expr\BinaryOp\NotIdentical) {
            foreach ([[$condition->left, $condition->right], [$condition->right, $condition->left]] as [$value, $null]) {
                if ($null instanceof Node\Expr\ConstFetch && strtolower($null->name->toString()) === 'null') {
                    return $truthy === ($condition instanceof Node\Expr\BinaryOp\NotIdentical)
                        ? array_values($this->keyed([$value])) : [];
                }
            }
        }
        return $truthy ? array_values($this->keyed([$condition])) : [];
    }

    private function keyed(array $expressions): array
    {
        $result = [];
        $printer = new Standard();
        foreach ($expressions as $expression) {
            while ($expression instanceof Node\Expr\PropertyFetch
                || $expression instanceof Node\Expr\Variable) {
                $result[$printer->prettyPrintExpr($expression)] = $expression;
                if ($expression instanceof Node\Expr\Variable) {
                    break;
                }
                $expression = $expression->var;
            }
        }
        return $result;
    }
}
