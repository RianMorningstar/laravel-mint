<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Scenarios\Presets\EcommerceScenario;
use LaravelMint\Scenarios\Presets\SaaSScenario;
use LaravelMint\Scenarios\ScenarioRunner;

class ScenarioRunCommand extends Command
{
    protected $signature = 'mint:scenario 
                            {scenario : The scenario to run (ecommerce, saas, or custom)}
                            {--dry-run : Run in dry-run mode to see what would be generated}
                            {--no-transaction : Run without database transactions}
                            {--config=* : Configuration options in key=value format}
                            {--file= : Path to scenario configuration file}';

    protected $description = 'Run a data generation scenario';

    protected ScenarioRunner $runner;

    public function __construct(ScenarioRunner $runner)
    {
        parent::__construct();
        $this->runner = $runner;
    }

    public function handle()
    {
        $scenarioName = $this->argument('scenario');
        $isDryRun = $this->option('dry-run');
        $useTransaction = ! $this->option('no-transaction');
        $configOptions = $this->parseConfigOptions();
        $configFile = $this->option('file');

        // Configure runner
        $this->runner
            ->withTransactions($useTransaction)
            ->dryRun($isDryRun)
            ->onProgress(function ($scenario, $message) {
                $this->info("[{$scenario}] {$message}");
            });

        // Register built-in scenarios
        $this->registerBuiltInScenarios();

        // Load configuration from file if provided
        if ($configFile) {
            $configOptions = $this->loadConfigFile($configFile, $configOptions);
        }

        // Handle custom scenario from file
        if ($scenarioName === 'custom' && $configFile) {
            $this->runCustomScenario($configFile, $configOptions);

            return 0;
        }

        // Run the scenario
        try {
            $this->info("Running scenario: {$scenarioName}");

            if ($isDryRun) {
                $this->warn('DRY RUN MODE - No data will be generated');
            }

            $result = $this->runner->run($scenarioName, $configOptions);

            // Display results
            $this->displayResults($result);

            return $result->isSuccess() ? 0 : 1;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->displayAvailableScenarios();

            return 1;
        } catch (\Exception $e) {
            $this->error('Scenario failed: '.$e->getMessage());

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return 1;
        }
    }

    protected function registerBuiltInScenarios(): void
    {
        $this->runner->registerMany([
            'ecommerce' => EcommerceScenario::class,
            'saas' => SaaSScenario::class,
        ]);

        // Discover user-defined scenarios
        $scenarioPath = app_path('Scenarios');
        if (is_dir($scenarioPath)) {
            $this->runner->discover($scenarioPath);
        }
    }

    protected function parseConfigOptions(): array
    {
        $config = [];

        foreach ($this->option('config') as $option) {
            if (strpos($option, '=') === false) {
                $this->warn("Invalid config option format: {$option}");

                continue;
            }

            [$key, $value] = explode('=', $option, 2);

            // Parse value type
            if (is_numeric($value)) {
                $value = strpos($value, '.') !== false ? (float) $value : (int) $value;
            } elseif (in_array(strtolower($value), ['true', 'false'])) {
                $value = strtolower($value) === 'true';
            }

            $config[$key] = $value;
        }

        return $config;
    }

    protected function loadConfigFile(string $path, array $overrides = []): array
    {
        if (! file_exists($path)) {
            throw new \RuntimeException("Configuration file not found: {$path}");
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        $config = match ($extension) {
            'json' => json_decode(file_get_contents($path), true),
            'php' => require $path,
            default => throw new \RuntimeException("Unsupported config file format: {$extension}"),
        };

        if (! is_array($config)) {
            throw new \RuntimeException('Configuration file must return an array');
        }

        return array_merge($config, $overrides);
    }

    protected function runCustomScenario(string $path, array $config): void
    {
        $this->info("Loading custom scenario from: {$path}");

        // Use ScenarioBuilder to load and run
        $builder = \LaravelMint\Scenarios\ScenarioBuilder::load($path);

        // Apply config overrides
        foreach ($config as $key => $value) {
            $builder->set($key, $value);
        }

        $result = $builder->generate();
        $this->displayResults($result);
    }

    protected function displayResults($result): void
    {
        $summary = $result->getSummary();

        $this->newLine();

        if ($result->isSuccess()) {
            $this->info('✓ Scenario completed successfully');
        } else {
            $this->error('✗ Scenario failed');
        }

        // Display statistics
        if (! empty($summary['generated'])) {
            $this->info('Generated Records:');
            $this->table(
                ['Model', 'Count'],
                collect($summary['generated'])->map(fn ($count, $model) => [
                    class_basename($model),
                    number_format($count),
                ])->toArray()
            );
        }

        // Display performance metrics
        $this->info('Performance:');
        $this->line("  Execution Time: {$summary['execution_time']}");
        $this->line("  Memory Usage: {$summary['memory_usage']}");
        $this->line('  Total Records: '.number_format($summary['total_records']));

        // Display custom statistics
        if (! empty($summary['statistics'])) {
            $this->newLine();
            $this->info('Statistics:');
            foreach ($summary['statistics'] as $key => $value) {
                if (is_array($value)) {
                    $this->line("  {$key}:");
                    foreach ($value as $subKey => $subValue) {
                        $this->line("    {$subKey}: ".(is_numeric($subValue) ? number_format($subValue) : $subValue));
                    }
                } else {
                    $this->line("  {$key}: ".(is_numeric($value) ? number_format($value) : $value));
                }
            }
        }

        // Display errors
        if (! empty($summary['errors'])) {
            $this->newLine();
            $this->error('Errors:');
            foreach ($summary['errors'] as $error) {
                $this->line("  • {$error}");
            }
        }
    }

    protected function displayAvailableScenarios(): void
    {
        $this->newLine();
        $this->info('Available scenarios:');

        $scenarios = $this->runner->list();

        foreach ($scenarios as $name => $info) {
            $this->line("  • {$name}: {$info['description']}");
        }

        $this->newLine();
        $this->line('Run with: php artisan mint:scenario <scenario-name>');
    }
}
