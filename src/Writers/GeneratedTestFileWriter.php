<?php

namespace Natan\NullSafetyTestGenerator\Writers;

class GeneratedTestFileWriter
{
    public function write(
        array $generatedFile,
        string $directory,
        bool $overwrite = false
    ): array {
        $fileName = $generatedFile['fileName'] ?? null;
        $code = $generatedFile['code'] ?? null;

        if (
            ($generatedFile['generated'] ?? false) !== true
            || ! is_string($fileName)
            || ! is_string($code)
            || basename($fileName) !== $fileName
            || ! str_ends_with($fileName, 'Test.php')
        ) {
            return $this->failure(
                'The generated test file is invalid and could not be written.'
            );
        }

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

    private function failure(string $message): array
    {
        return [
            'written' => false,
            'message' => $message,
        ];
    }
}
