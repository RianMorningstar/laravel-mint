<?php

namespace LaravelMint\Commands;

use Illuminate\Console\Command;
use LaravelMint\Facades\Mint;

class GenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mint:generate 
                            {model? : The model class to generate data for}
                            {--count=10 : Number of records to generate}
                            {--scenario= : Use a predefined scenario}
                            {--with-relationships : Generate related models}
                            {--truncate : Truncate table before generating}
                            {--silent : Suppress output}
                            {--seed= : Seed for random generation}
                            {--use-patterns : Enable pattern-based generation}
                            {--column-patterns= : JSON string of column patterns}
                            {--pattern-config= : Path to pattern configuration file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate realistic test data for Laravel models';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if using scenario
        if ($scenario = $this->option('scenario')) {
            return $this->handleScenario($scenario);
        }

        // Check if model is provided
        $modelClass = $this->argument('model');
        if (! $modelClass) {
            $this->error('Please provide a model class or use --scenario option');

            return 1;
        }

        // Prepend App\Models if not fully qualified
        if (! str_contains($modelClass, '\\')) {
            $modelClass = 'App\\Models\\'.$modelClass;
        }

        // Check if model exists
        if (! class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist");

            return 1;
        }

        $count = (int) $this->option('count');
        $truncate = $this->option('truncate');
        $silent = $this->option('silent');
        $seed = $this->option('seed');

        if (! $silent) {
            $this->info("Generating data for: {$modelClass}");
            $this->info("Count: {$count}");

            if ($truncate) {
                $this->warn('Table will be truncated before generation');
            }
        }

        try {
            // Truncate if requested
            if ($truncate) {
                $this->truncateTable($modelClass);
            }

            // Build options array
            $options = [];
            if ($seed) {
                $options['seed'] = $seed;
            }
            if ($silent) {
                $options['silent'] = true;
            }

            // Handle pattern options
            if ($this->option('use-patterns')) {
                $options['use_patterns'] = true;

                // Parse column patterns from JSON
                if ($columnPatterns = $this->option('column-patterns')) {
                    $options['column_patterns'] = json_decode($columnPatterns, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->error('Invalid JSON in column-patterns option: '.json_last_error_msg());

                        return 1;
                    }
                }

                // Load pattern config from file
                if ($configFile = $this->option('pattern-config')) {
                    if (! file_exists($configFile)) {
                        $this->error("Pattern config file not found: {$configFile}");

                        return 1;
                    }

                    $config = json_decode(file_get_contents($configFile), true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->error('Invalid JSON in pattern config file: '.json_last_error_msg());

                        return 1;
                    }

                    $options = array_merge($options, $config);
                }
            }

            // Start timing
            $startTime = microtime(true);

            // Generate data
            Mint::generate($modelClass, $count, $options);

            // Calculate execution time
            $executionTime = round(microtime(true) - $startTime, 2);

            if (! $silent) {
                $this->newLine();
                $this->info("✓ Successfully generated {$count} records in {$executionTime} seconds");

                // Show memory usage
                $memoryUsage = $this->formatBytes(memory_get_peak_usage(true));
                $this->info("Peak memory usage: {$memoryUsage}");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error generating data: '.$e->getMessage());

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return 1;
        }
    }

    /**
     * Handle scenario-based generation
     */
    protected function handleScenario(string $scenario): int
    {
        $silent = $this->option('silent');

        if (! $silent) {
            $this->info("Running scenario: {$scenario}");
            $this->newLine();
        }

        try {
            $options = [
                'count' => (int) $this->option('count'),
                'silent' => $silent,
            ];

            if ($seed = $this->option('seed')) {
                $options['seed'] = $seed;
            }

            // Start timing
            $startTime = microtime(true);

            // Run scenario
            Mint::generateWithScenario($scenario, $options);

            // Calculate execution time
            $executionTime = round(microtime(true) - $startTime, 2);

            if (! $silent) {
                $this->newLine();
                $this->info("✓ Scenario '{$scenario}' completed in {$executionTime} seconds");

                // Show memory usage
                $memoryUsage = $this->formatBytes(memory_get_peak_usage(true));
                $this->info("Peak memory usage: {$memoryUsage}");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error running scenario: '.$e->getMessage());

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return 1;
        }
    }

    /**
     * Truncate table for a model
     */
    protected function truncateTable(string $modelClass): void
    {
        $instance = new $modelClass;
        $table = $instance->getTable();
        $connection = $instance->getConnection();

        // Disable foreign key checks
        $driver = $connection->getDriverName();

        switch ($driver) {
            case 'mysql':
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');
                $connection->table($table)->truncate();
                $connection->statement('SET FOREIGN_KEY_CHECKS=1');
                break;

            case 'pgsql':
                $connection->statement("TRUNCATE TABLE {$table} RESTART IDENTITY CASCADE");
                break;

            case 'sqlite':
                $connection->statement('PRAGMA foreign_keys = OFF');
                $connection->table($table)->truncate();
                $connection->statement('PRAGMA foreign_keys = ON');
                break;

            default:
                $connection->table($table)->truncate();
        }
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
