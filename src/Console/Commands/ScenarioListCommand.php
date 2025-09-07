<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Scenarios\Presets\EcommerceScenario;
use LaravelMint\Scenarios\Presets\SaaSScenario;
use LaravelMint\Scenarios\ScenarioRunner;

class ScenarioListCommand extends Command
{
    protected $signature = 'mint:scenario:list 
                            {--detailed : Show detailed information about each scenario}';

    protected $description = 'List available data generation scenarios';

    protected ScenarioRunner $runner;

    public function __construct(ScenarioRunner $runner)
    {
        parent::__construct();
        $this->runner = $runner;
    }

    public function handle()
    {
        // Register built-in scenarios
        $this->runner->registerMany([
            'ecommerce' => EcommerceScenario::class,
            'saas' => SaaSScenario::class,
        ]);

        // Discover user-defined scenarios
        $scenarioPath = app_path('Scenarios');
        if (is_dir($scenarioPath)) {
            $this->runner->discover($scenarioPath);
        }

        $scenarios = $this->runner->list();

        if (empty($scenarios)) {
            $this->warn('No scenarios available.');

            return 0;
        }

        $this->info('Available Scenarios:');
        $this->newLine();

        if ($this->option('detailed')) {
            $this->displayDetailedList($scenarios);
        } else {
            $this->displaySimpleList($scenarios);
        }

        return 0;
    }

    protected function displaySimpleList(array $scenarios): void
    {
        $tableData = [];

        foreach ($scenarios as $name => $info) {
            $tableData[] = [
                $name,
                $info['description'],
                count($info['required_models']),
                count($info['parameters']),
            ];
        }

        $this->table(
            ['Name', 'Description', 'Required Models', 'Parameters'],
            $tableData
        );

        $this->newLine();
        $this->line('Run a scenario with: php artisan mint:scenario <name>');
        $this->line('View details with: php artisan mint:scenario:list --detailed');
    }

    protected function displayDetailedList(array $scenarios): void
    {
        foreach ($scenarios as $name => $info) {
            $this->info("Scenario: {$name}");
            $this->line("Description: {$info['description']}");

            // Display required models
            if (! empty($info['required_models'])) {
                $this->line('Required Models:');
                foreach ($info['required_models'] as $model) {
                    $modelName = class_basename($model);
                    $exists = class_exists($model) ? '✓' : '✗';
                    $this->line("  {$exists} {$modelName} ({$model})");
                }
            }

            // Display optional models
            if (! empty($info['optional_models'])) {
                $this->line('Optional Models:');
                foreach ($info['optional_models'] as $model) {
                    $modelName = class_basename($model);
                    $exists = class_exists($model) ? '✓' : '✗';
                    $this->line("  {$exists} {$modelName} ({$model})");
                }
            }

            // Display parameters
            if (! empty($info['parameters'])) {
                $this->line('Parameters:');
                foreach ($info['parameters'] as $param => $rules) {
                    $type = $rules['type'] ?? 'mixed';
                    $default = isset($rules['default']) ? json_encode($rules['default']) : 'none';
                    $required = isset($rules['required']) && $rules['required'] ? '*' : '';

                    $this->line("  • {$param}{$required} ({$type})");

                    if (isset($rules['description'])) {
                        $this->line("    {$rules['description']}");
                    }

                    $this->line("    Default: {$default}");

                    if (isset($rules['min']) || isset($rules['max'])) {
                        $range = [];
                        if (isset($rules['min'])) {
                            $range[] = "min: {$rules['min']}";
                        }
                        if (isset($rules['max'])) {
                            $range[] = "max: {$rules['max']}";
                        }
                        $this->line('    Range: '.implode(', ', $range));
                    }

                    if (isset($rules['enum'])) {
                        $this->line('    Options: '.implode(', ', $rules['enum']));
                    }
                }
            }

            $this->newLine();
            $this->line('Example usage:');
            $this->comment("  php artisan mint:scenario {$name}");

            if (! empty($info['parameters'])) {
                $exampleParams = [];
                foreach (array_slice($info['parameters'], 0, 2) as $param => $rules) {
                    $exampleValue = $rules['default'] ?? match ($rules['type'] ?? 'string') {
                        'integer' => 100,
                        'float' => 0.5,
                        'boolean' => 'true',
                        default => 'value',
                    };

                    if (is_array($exampleValue)) {
                        $exampleValue = json_encode($exampleValue);
                    }

                    $exampleParams[] = "--config={$param}={$exampleValue}";
                }

                if (! empty($exampleParams)) {
                    $this->comment("  php artisan mint:scenario {$name} ".implode(' ', $exampleParams));
                }
            }

            $this->newLine();
            $this->line(str_repeat('-', 60));
            $this->newLine();
        }
    }
}
