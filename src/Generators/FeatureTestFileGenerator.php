<?php

namespace Natan\NullSafetyTestGenerator\Generators;

use PhpParser\Error;
use PhpParser\ParserFactory;

class FeatureTestFileGenerator
{
    public function generate(
        string $className,
        array $testMethods
    ): array {
        if (! $this->isValidClassName($className)) {
            return [
                'generated' => false,
                'message' => 'The test class name is invalid; the file could not be generated.',
            ];
        }

        $testMethods = array_values(array_unique(array_filter(
            $testMethods,
            static fn (mixed $method): bool => is_string($method)
                && trim($method) !== ''
        )));

        if ($testMethods === []) {
            return [
                'generated' => false,
                'message' => 'No test methods were provided; the file could not be generated.',
            ];
        }

        $indentedMethods = array_map(
            fn (string $method): string => $this->indent(
                trim($method),
                4
            ),
            $testMethods
        );

        $code = implode("\n", [
            '<?php',
            '',
            'namespace Tests\\Feature\\Generated;',
            '',
            'use Illuminate\\Foundation\\Testing\\RefreshDatabase;',
            'use Tests\\TestCase;',
            '',
            'class ' . $className . ' extends TestCase',
            '{',
            '    use RefreshDatabase;',
            '',
            implode("\n\n", $indentedMethods),
            '}',
        ]);

        if (! $this->hasValidPhpSyntax($code)) {
            return [
                'generated' => false,
                'message' => 'The generated PHP is invalid; the file could not be generated.',
            ];
        }

        return [
            'generated' => true,
            'fileName' => $className . '.php',
            'code' => $code,
        ];
    }

    private function isValidClassName(string $className): bool
    {
        return preg_match(
            '/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            $className
        ) === 1;
    }

    private function hasValidPhpSyntax(string $code): bool
    {
        $parser = (new ParserFactory())
            ->createForNewestSupportedVersion();

        try {
            return $parser->parse($code) !== null;
        } catch (Error) {
            return false;
        }
    }

    private function indent(string $code, int $spaces): string
    {
        $indentation = str_repeat(' ', $spaces);

        return implode("\n", array_map(
            static fn (string $line): string => $line === ''
                ? ''
                : $indentation . $line,
            explode("\n", $code)
        ));
    }
}
