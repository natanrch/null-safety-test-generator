<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;

class GenerateNullSafetyTestsCommandTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDirectory = sys_get_temp_dir()
            . '/null-safety-test-generator-'
            . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $generatedFile = $this->outputDirectory
            . '/PostsShowNullSafetyTest.php';

        if (is_file($generatedFile)) {
            unlink($generatedFile);
        }

        if (is_dir($this->outputDirectory)) {
            rmdir($this->outputDirectory);
        }

        parent::tearDown();
    }

    public function test_it_generates_a_complete_feature_test_file(): void
    {
        $this->artisan('null-safety:generate', [
            '--controller' => PostController::class,
            '--method' => 'show',
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain('Null-safety test generated:')
            ->assertSuccessful();

        $path = $this->outputDirectory
            . '/PostsShowNullSafetyTest.php';

        $this->assertFileExists($path);
        $this->assertStringContainsString(
            'class PostsShowNullSafetyTest extends TestCase',
            file_get_contents($path)
        );
        $this->assertSame(
            4,
            substr_count(
                file_get_contents($path),
                'public function test_'
            )
        );
    }

    public function test_it_rejects_an_invalid_controller(): void
    {
        $this->artisan('null-safety:generate', [
            '--controller' => 'App\\Http\\Controllers\\MissingController',
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->outputDirectory);
    }

    public function test_it_reports_skipped_scenarios_and_writes_valid_tests(): void
    {
        $generator = $this->createMock(
            NullSafetyTestGenerationService::class
        );
        $generator->expects($this->once())
            ->method('generate')
            ->with(PostController::class, 'show')
            ->willReturn([
                'generated' => true,
                'fileName' => 'PostsShowNullSafetyTest.php',
                'code' => '<?php',
                'warnings' => [
                    'Factory for model App\\Models\\Author does not exist; the test could not be generated.',
                ],
            ]);

        $this->app->instance(
            NullSafetyTestGenerationService::class,
            $generator
        );

        $this->artisan('null-safety:generate', [
            '--controller' => PostController::class,
            '--method' => 'show',
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain(
                'Factory for model App\\Models\\Author does not exist'
            )
            ->expectsOutputToContain('Null-safety test generated:')
            ->assertSuccessful();

        $this->assertFileExists(
            $this->outputDirectory . '/PostsShowNullSafetyTest.php'
        );
    }
}
