<?php

namespace Natan\NullSafetyTestGenerator\Console;

use Illuminate\Console\Command;
use Natan\NullSafetyTestGenerator\Services\BatchNullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Services\NullSafetyTestGenerationService;
use Natan\NullSafetyTestGenerator\Writers\GeneratedTestFileWriter;
use Throwable;

class GenerateNullSafetyTestsCommand extends Command
{
    protected $signature = 'null-safety:generate
        {--all : Generate tests for all GET controller routes that return views}
        {--controller= : Fully qualified controller class}
        {--method=show : Controller method}
        {--output= : Directory where the generated test will be written}
        {--force : Overwrite an existing generated test file}';

    protected $description = 'Generate null-safety feature tests for a controller view';

    public function handle(
        NullSafetyTestGenerationService $generator,
        BatchNullSafetyTestGenerationService $batchGenerator,
        GeneratedTestFileWriter $writer
    ): int {
        if ((bool) $this->option('all')) {
            if ($this->option('controller') !== null) {
                $this->error(
                    'The --all and --controller options cannot be used together.'
                );

                return self::FAILURE;
            }

            try {
                $generatedFile = $batchGenerator->generate();
            } catch (Throwable $exception) {
                $this->error(sprintf(
                    'The test batch could not be generated: %s',
                    $exception->getMessage()
                ));

                return self::FAILURE;
            }

            return $this->writeGeneratedFile(
                $generatedFile,
                $writer,
                true
            );
        }

        $controller = $this->option('controller');
        $method = $this->option('method');

        if (! is_string($controller) || trim($controller) === '') {
            $this->error('The --controller option is required.');

            return self::FAILURE;
        }

        if (! is_string($method) || trim($method) === '') {
            $this->error('The --method option must not be empty.');

            return self::FAILURE;
        }

        $controller = ltrim(trim($controller), '\\');

        if (! class_exists($controller)) {
            $this->error(sprintf(
                'Controller class %s does not exist.',
                $controller
            ));

            return self::FAILURE;
        }

        if (! method_exists($controller, $method)) {
            $this->error(sprintf(
                'Method %s::%s does not exist.',
                $controller,
                $method
            ));

            return self::FAILURE;
        }

        try {
            $generatedFile = $generator->generate($controller, $method);
        } catch (Throwable $exception) {
            $this->error(sprintf(
                'The test could not be generated: %s',
                $exception->getMessage()
            ));

            return self::FAILURE;
        }

        return $this->writeGeneratedFile($generatedFile, $writer);
    }

    private function writeGeneratedFile(
        array $generatedFile,
        GeneratedTestFileWriter $writer,
        bool $batch = false
    ): int {
        $this->displayWarnings($generatedFile['warnings'] ?? []);

        if (($generatedFile['generated'] ?? false) !== true) {
            $this->error(
                $generatedFile['message']
                    ?? 'The test file could not be generated.'
            );

            return self::FAILURE;
        }

        $output = $this->option('output');
        $directory = is_string($output) && trim($output) !== ''
            ? trim($output)
            : base_path('tests/Feature/Generated');

        $writeResult = $writer->write(
            $generatedFile,
            $directory,
            (bool) $this->option('force')
        );

        if (($writeResult['written'] ?? false) !== true) {
            $this->error(
                $writeResult['message']
                    ?? 'The generated test file could not be written.'
            );

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Null-safety test generated: %s',
            $writeResult['path']
        ));

        if ($batch) {
            $this->info(sprintf(
                'Analyzed routes: %d; generated tests: %d.',
                $generatedFile['analyzedRoutes'] ?? 0,
                $generatedFile['generatedTests'] ?? 0
            ));
        }

        return self::SUCCESS;
    }

    private function displayWarnings(mixed $warnings): void
    {
        if (! is_array($warnings)) {
            return;
        }

        foreach ($warnings as $warning) {
            if (is_string($warning) && $warning !== '') {
                $this->warn($warning);
            }
        }
    }
}
