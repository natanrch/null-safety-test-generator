<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Services\BatchNullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers\PostController;
use Illuminate\Routing\Router;
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
            'PostsDestroyWriteSafetyTest.php',
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
            2,
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

    public function test_it_displays_the_missing_exists_factory_message_for_a_write_route(): void
    {
        $this->app->make(Router::class)
            ->post('/categories-without-factory', [
                PostController::class,
                'storeWithExistingCategoryWithoutFactory',
            ])
            ->name('categories-without-factory.store');

        $this->artisan('null-safety:generate', [
            '--controller' => PostController::class,
            '--method' => 'storeWithExistingCategoryWithoutFactory',
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain(
                'does not exist; the test could not be generated.'
            )
            ->assertFailed();

        $this->assertDirectoryDoesNotExist($this->outputDirectory);
    }

    public function test_it_generates_a_delete_test_file(): void
    {
        $this->app->make(Router::class)
            ->delete('/posts/{post}', [PostController::class, 'destroy'])
            ->name('posts.destroy');

        $this->artisan('null-safety:generate', [
            '--controller' => PostController::class,
            '--method' => 'destroy',
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain('Null-safety test generated:')
            ->assertSuccessful();

        $path = $this->outputDirectory
            . '/PostsDestroyWriteSafetyTest.php';
        $code = file_get_contents($path);

        $this->assertFileExists($path);
        $this->assertStringContainsString(
            '$response = $this->delete(',
            $code
        );
        $this->assertStringContainsString(
            "['reason' => 'test']",
            $code
        );
    }

    public function test_it_reports_that_an_empty_controller_method_was_skipped(): void
    {
        $this->app->make(Router::class)
            ->delete('/empty/{id}', [PostController::class, 'emptyAction'])
            ->name('empty.destroy');

        $this->artisan('null-safety:generate', [
            '--controller' => PostController::class,
            '--method' => 'emptyAction',
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain(
                'The controller method has no executable statements; no test was generated.'
            )
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
            ->expectsOutputToContain('Generated 2 tests.')
            ->expectsOutputToContain('Null-safety test generated:')
            ->expectsOutputToContain(
                'Analyzed routes: 1; generated tests: 2.'
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
            2,
            substr_count(file_get_contents($path), 'public function test_')
        );
    }

    public function test_it_writes_get_and_write_route_tests_to_the_batch_file(): void
    {
        $this->app->make(Router::class)
            ->post('/posts/category', [PostController::class, 'storeWithExistingCategory'])
            ->name('posts.category.store');

        $this->artisan('null-safety:generate', [
            '--all' => true,
            '--output' => $this->outputDirectory,
        ])
            ->expectsOutputToContain('[2/2] Analyzing posts.category.store...')
            ->expectsOutputToContain(
                'Analyzed routes: 2; generated tests: 3.'
            )
            ->assertSuccessful();

        $path = $this->outputDirectory
            . '/ApplicationNullSafetyTest.php';
        $code = file_get_contents($path);

        $this->assertStringContainsString(
            'test_posts_category_store_does_not_return_a_server_error_for_post_request',
            $code
        );
        $this->assertStringContainsString(
            "['category_id' => \$category->id]",
            $code
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

    public function test_force_preserves_manual_changes_in_existing_tests(): void
    {
        $arguments = [
            '--all' => true,
            '--output' => $this->outputDirectory,
        ];

        $this->artisan('null-safety:generate', $arguments)
            ->assertSuccessful();

        $path = $this->outputDirectory
            . '/ApplicationNullSafetyTest.php';
        $manuallyEditedCode = str_replace(
            'use RefreshDatabase;',
            "use RefreshDatabase;\n\n    // manual customization",
            file_get_contents($path)
        );
        file_put_contents($path, $manuallyEditedCode);

        $arguments['--force'] = true;

        $this->artisan('null-safety:generate', $arguments)
            ->expectsOutputToContain(
                'Added tests: 0; preserved existing tests: 2.'
            )
            ->assertSuccessful();

        $mergedCode = file_get_contents($path);

        $this->assertStringContainsString(
            '// manual customization',
            $mergedCode
        );
        $this->assertSame(
            2,
            substr_count($mergedCode, 'public function test_')
        );
    }
}
