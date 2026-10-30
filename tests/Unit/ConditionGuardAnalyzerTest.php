<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Analyzers\ConditionGuardAnalyzer;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConditionGuardAnalyzerTest extends TestCase
{
    public static function conditions(): array
    {
        return [
            ['($a && $b)', true, ['$a', '$b']],
            ['($a && $b)', false, []],
            ['($a || $b)', true, []],
            ['(!$a || !$b)', false, ['$a', '$b']],
            ['(($a && $b) || ($a && $c))', true, ['$a']],
            ['isset($a, $b)', true, ['$a', '$b']],
            ['empty($a)', false, ['$a']],
            ['is_null($a)', false, ['$a']],
            ['$a === null', false, ['$a']],
            ['$a !== null', true, ['$a']],
            ['$a->hasAuthor()', true, []],
            ['$a == false', false, []],
        ];
    }

    #[DataProvider('conditions')]
    public function test_it_computes_only_proven_non_null_facts(string $source, bool $truthy, array $expected): void
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $source . ';');
        $guards = (new ConditionGuardAnalyzer())->analyze($ast[0]->expr, $truthy);
        $printer = new Standard();
        $this->assertSame($expected, array_map($printer->prettyPrintExpr(...), $guards));
    }
}
