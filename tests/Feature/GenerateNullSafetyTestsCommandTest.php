<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Services\BatchNullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

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
        foreach ([
            'PostsShowNullSafetyTest.php',
            'ApplicationNullSafetyTest.php',
        ] as $fileName) {
            $generatedFile = $this->outputDirectory . '/' . $fileName;

            if (is_file($generatedFile)) {
                unlink($generatedFile);
            }
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

    public function test_it_generates_one_file_for_all_routes_with_views(): void
    {
        $this->artisan('null-safety:generate', [
            '--all' => true,
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain('[1/1] Analyzing posts.show...')
            ->expectsOutputToContain('Generated 4 tests.')
            ->expectsOutputToContain('Null-safety test generated:')
            ->expectsOutputToContain(
                'Analyzed routes: 1; generated tests: 4.'
            )
            ->assertSuccessful();

        $path = $this->outputDirectory
            . '/ApplicationNullSafetyTest.php';

        $this->assertFileExists($path);
        $this->assertStringContainsString(
            'class ApplicationNullSafetyTest extends TestCase',
            file_get_contents($path)
        );
        $this->assertSame(
            4,
            substr_count(file_get_contents($path), 'public function test_')
        );
    }

    public function test_it_reports_batch_warnings_and_still_writes_the_file(): void
    {
        $batchGenerator = $this->createMock(
            BatchNullSafetyTestGenerationService::class
        );
        $batchGenerator->expects($this->once())
            ->method('generate')
            ->willReturn([
                'generated' => true,
                'fileName' => 'ApplicationNullSafetyTest.php',
                'code' => '<?php',
                'warnings' => [
                    'reports.show: Factory for Report does not exist.',
                ],
                'analyzedRoutes' => 1,
                'generatedTests' => 1,
            ]);

        $this->app->instance(
            BatchNullSafetyTestGenerationService::class,
            $batchGenerator
        );

        $this->artisan('null-safety:generate', [
            '--all' => true,
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain(
                'reports.show: Factory for Report does not exist.'
            )
            ->expectsOutputToContain(
                'Analyzed routes: 1; generated tests: 1.'
            )
            ->assertSuccessful();

        $this->assertFileExists(
            $this->outputDirectory . '/ApplicationNullSafetyTest.php'
        );
    }

    public function test_it_rejects_all_and_controller_options_together(): void
    {
        $this->artisan('null-safety:generate', [
            '--all' => true,
            '--controller' => PostController::class,
        ])
            ->expectsOutputToContain(
                'The --all and --controller options cannot be used together.'
            )
            ->assertFailed();
    }
}
