<?php

namespace Natan\NullSafetyTestGenerator\Writers;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

class GeneratedTestFileWriter
{
    public function merge(array $generatedFile, string $directory): array
    {
        $validationError = $this->validateGeneratedFile($generatedFile);

        if ($validationError !== null) {
            return $this->failure($validationError);
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $generatedFile['fileName'];

        if (! is_file($path)) {
            return $this->write($generatedFile, $directory);
        }

        $existingCode = file_get_contents($path);

        if ($existingCode === false) {
            return $this->failure(
                'The existing test file could not be read.'
            );
        }

        $merged = $this->mergeCode(
            $existingCode,
            $generatedFile['code']
        );

        if (($merged['merged'] ?? false) !== true) {
            return $this->failure(
                $merged['message']
                    ?? 'The generated tests could not be merged.'
            );
        }

        if (($merged['addedTests'] ?? 0) > 0) {
            $bytesWritten = file_put_contents(
                $path,
                $merged['code'],
                LOCK_EX
            );

            if ($bytesWritten === false) {
                return $this->failure(
                    'The merged test file could not be written.'
                );
            }
        }

        return [
            'written' => true,
            'path' => $path,
            'merged' => true,
            'addedTests' => $merged['addedTests'],
            'preservedTests' => $merged['preservedTests'],
        ];
    }

    public function write(
        array $generatedFile,
        string $directory,
        bool $overwrite = false
    ): array {
        $validationError = $this->validateGeneratedFile($generatedFile);

        if ($validationError !== null) {
            return $this->failure($validationError);
        }

        $fileName = $generatedFile['fileName'];
        $code = $generatedFile['code'];

        if (
            ! is_dir($directory)
            && ! mkdir($directory, 0775, true)
            && ! is_dir($directory)
        ) {
            return $this->failure(
                'The generated test directory could not be created.'
            );
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $fileName;

        if (is_file($path) && ! $overwrite) {
            return $this->failure(
                sprintf(
                    'The test file %s already exists and was not overwritten.',
                    $path
                )
            );
        }

        $bytesWritten = file_put_contents(
            $path,
            $code . "\n",
            LOCK_EX
        );

        if ($bytesWritten === false) {
            return $this->failure(
                'The generated test file could not be written.'
            );
        }

        return [
            'written' => true,
            'path' => $path,
        ];
    }

    private function mergeCode(
        string $existingCode,
        string $generatedCode
    ): array {
        $existingClass = $this->parseClass($existingCode);
        $generatedClass = $this->parseClass($generatedCode);

        if ($existingClass === null || $generatedClass === null) {
            return [
                'merged' => false,
                'message' => 'The existing or generated test file contains invalid PHP.',
            ];
        }

        if (
            $existingClass->name?->toString()
            !== $generatedClass->name?->toString()
        ) {
            return [
                'merged' => false,
                'message' => 'The existing and generated test class names do not match.',
            ];
        }

        $existingMethods = [];

        foreach ($existingClass->getMethods() as $method) {
            $existingMethods[$method->name->toString()] = true;
        }

        $newMethods = [];
        $preservedTests = 0;

        foreach ($generatedClass->getMethods() as $method) {
            $methodName = $method->name->toString();

            if (isset($existingMethods[$methodName])) {
                $preservedTests++;
                continue;
            }

            $methodCode = substr(
                $generatedCode,
                $method->getStartFilePos(),
                $method->getEndFilePos()
                    - $method->getStartFilePos()
                    + 1
            );
            $newMethods[] = $this->indent($methodCode, 4);
        }

        if ($newMethods === []) {
            return [
                'merged' => true,
                'code' => $existingCode,
                'addedTests' => 0,
                'preservedTests' => $preservedTests,
            ];
        }

        $closingBrace = $existingClass->getEndFilePos();
        $beforeClosingBrace = rtrim(substr(
            $existingCode,
            0,
            $closingBrace
        ));
        $afterClosingBrace = substr($existingCode, $closingBrace);

        return [
            'merged' => true,
            'code' => $beforeClosingBrace
                . "\n\n"
                . implode("\n\n", $newMethods)
                . "\n"
                . $afterClosingBrace,
            'addedTests' => count($newMethods),
            'preservedTests' => $preservedTests,
        ];
    }

    private function parseClass(string $code): ?Node\Stmt\Class_
    {
        try {
            $ast = (new ParserFactory())
                ->createForNewestSupportedVersion()
                ->parse($code);
        } catch (Error) {
            return null;
        }

        if ($ast === null) {
            return null;
        }

        $class = (new NodeFinder())->findFirstInstanceOf(
            $ast,
            Node\Stmt\Class_::class
        );

        return $class instanceof Node\Stmt\Class_ ? $class : null;
    }

    private function indent(string $code, int $spaces): string
    {
        $indentation = str_repeat(' ', $spaces);

        return $indentation . str_replace(
            "\n",
            "\n" . $indentation,
            $code
        );
    }

    private function validateGeneratedFile(array $generatedFile): ?string
    {
        $fileName = $generatedFile['fileName'] ?? null;
        $code = $generatedFile['code'] ?? null;

        if (
            ($generatedFile['generated'] ?? false) !== true
            || ! is_string($fileName)
            || ! is_string($code)
            || basename($fileName) !== $fileName
            || ! str_ends_with($fileName, 'Test.php')
        ) {
            return 'The generated test file is invalid and could not be written.';
        }

        return null;
    }

    private function failure(string $message): array
    {
        return [
            'written' => false,
            'message' => $message,
        ];
    }
}
