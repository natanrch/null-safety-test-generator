<?php

namespace Natan\NullSafetyTestGenerator\Tests\Unit;

use Natan\NullSafetyTestGenerator\Generators\FeatureTestFileGenerator;
use Natan\NullSafetyTestGenerator\Writers\GeneratedTestFileWriter;
use PHPUnit\Framework\TestCase;

class GeneratedTestFileWriterMergeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir()
            . '/null-safety-merge-'
            . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $path = $this->directory . '/ApplicationNullSafetyTest.php';

        if (is_file($path)) {
            unlink($path);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_it_preserves_existing_tests_and_appends_only_new_methods(): void
    {
        $fileGenerator = new FeatureTestFileGenerator();
        $writer = new GeneratedTestFileWriter();
        $existing = $fileGenerator->generate(
            'ApplicationNullSafetyTest',
            [$this->method('test_existing', '// original setup')]
        );

        $writer->write($existing, $this->directory);
        $path = $this->directory . '/ApplicationNullSafetyTest.php';
        $manuallyEditedCode = str_replace(
            '// original setup',
            '// manually edited setup',
            file_get_contents($path)
        );
        file_put_contents($path, $manuallyEditedCode);

        $newGeneration = $fileGenerator->generate(
            'ApplicationNullSafetyTest',
            [
                $this->method('test_existing', '// regenerated setup'),
                $this->method('test_new_scenario', '// new setup'),
            ]
        );

        $result = $writer->merge($newGeneration, $this->directory);
        $mergedCode = file_get_contents($path);

        $this->assertSame([
            'written' => true,
            'path' => $path,
            'merged' => true,
            'addedTests' => 1,
            'preservedTests' => 1,
        ], $result);
        $this->assertStringContainsString(
            '// manually edited setup',
            $mergedCode
        );
        $this->assertStringNotContainsString(
            '// regenerated setup',
            $mergedCode
        );
        $this->assertStringContainsString('// new setup', $mergedCode);
        $this->assertSame(1, substr_count($mergedCode, 'test_existing'));
        $this->assertSame(1, substr_count($mergedCode, 'test_new_scenario'));
    }

    private function method(string $name, string $body): string
    {
        return implode("\n", [
            'public function ' . $name . '(): void',
            '{',
            '    ' . $body,
            '}',
        ]);
    }
}
