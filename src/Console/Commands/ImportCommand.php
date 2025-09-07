<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Import\ImportManager;

class ImportCommand extends Command
{
    protected $signature = 'mint:import 
                            {file : Path to the file to import}
                            {--format= : File format (csv, json, excel, sql)}
                            {--model=* : Model class to import to}
                            {--mapping=* : Field mappings in format field:column}
                            {--chunk-size=1000 : Number of records to process at once}
                            {--no-validation : Skip validation before import}
                            {--no-transaction : Don\'t use database transactions}
                            {--template= : Use a predefined import template}';

    protected $description = 'Import data from various file formats';

    public function handle()
    {
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            return 1;
        }

        $this->info("Importing from: {$file}");

        $manager = new ImportManager;

        // Load template if specified
        if ($template = $this->option('template')) {
            try {
                $manager = ImportManager::fromTemplate($template);
                $this->info("Using template: {$template}");
            } catch (\Exception $e) {
                $this->error("Failed to load template: {$e->getMessage()}");

                return 1;
            }
        }

        // Configure mappings
        $models = $this->option('model');
        $mappings = $this->option('mapping');

        if (! empty($models)) {
            foreach ($models as $model) {
                if (! class_exists($model)) {
                    $this->error("Model not found: {$model}");

                    return 1;
                }

                // Parse mappings for this model
                $modelMappings = [];
                foreach ($mappings as $mapping) {
                    if (strpos($mapping, ':') !== false) {
                        [$field, $column] = explode(':', $mapping, 2);
                        $modelMappings[$field] = $column;
                    }
                }

                if (! empty($modelMappings)) {
                    $manager->mapping($model, $modelMappings);
                    $this->info('Mapped '.count($modelMappings)." fields for {$model}");
                }
            }
        }

        // Configure options
        $manager->chunkSize((int) $this->option('chunk-size'))
            ->withValidation(! $this->option('no-validation'))
            ->withTransactions(! $this->option('no-transaction'));

        // Show progress
        $this->info('Starting import...');
        $bar = $this->output->createProgressBar();

        try {
            $result = $manager->import($file, $this->option('format'));

            $bar->finish();
            $this->newLine(2);

            // Display results
            if ($result->isSuccess()) {
                $this->info('✓ Import completed successfully');

                $data = $result->toArray();
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Format', $data['format']],
                        ['Records Processed', $data['processed']],
                        ['Records Imported', $data['total_imported']],
                        ['Execution Time', $data['execution_time']],
                    ]
                );

                if (! empty($data['imported'])) {
                    $this->newLine();
                    $this->info('Imported by model:');
                    foreach ($data['imported'] as $model => $count) {
                        $this->line("  • {$model}: {$count}");
                    }
                }
            } else {
                $this->error('✗ Import failed');

                $data = $result->toArray();
                if ($data['errors'] > 0) {
                    $this->error("Errors: {$data['errors']}");
                }
                if ($data['validation_errors'] > 0) {
                    $this->error("Validation errors: {$data['validation_errors']}");
                }
            }

            return $result->isSuccess() ? 0 : 1;
        } catch (\Exception $e) {
            $bar->finish();
            $this->newLine(2);
            $this->error('Import failed: '.$e->getMessage());

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return 1;
        }
    }
}
