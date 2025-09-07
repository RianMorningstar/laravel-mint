<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Mint;

class AnalyzeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mint:analyze 
                            {model : The model class to analyze}
                            {--json : Output as JSON}
                            {--detailed : Show detailed analysis}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze a model structure and relationships';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $model = $this->argument('model');

        try {
            $mint = app(Mint::class);
            $analysis = $mint->analyze($model);

            if ($this->option('json')) {
                $this->line(json_encode($analysis, JSON_PRETTY_PRINT));
            } else {
                $this->displayAnalysis($analysis);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to analyze model: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Display analysis in table format
     */
    protected function displayAnalysis(array $analysis): void
    {
        // Extract model name from the analysis
        $modelName = is_array($analysis['model'])
            ? ($analysis['model']['class'] ?? $analysis['model']['name'] ?? 'Unknown')
            : ($analysis['model'] ?? 'Unknown');

        $this->info('Model Analysis: '.$modelName);
        $this->newLine();

        // Display attributes/fields
        // First check if model analysis has attributes
        $modelAttributes = is_array($analysis['model']) ? ($analysis['model']['attributes'] ?? []) : [];
        // Then check schema columns and top-level attributes
        $fields = ! empty($modelAttributes) ? $modelAttributes :
                  ($analysis['attributes'] ?? $analysis['schema']['columns'] ?? []);

        if (! empty($fields)) {
            $this->info('Attributes:');
            $rows = [];
            foreach ($fields as $name => $details) {
                // Handle both array and scalar details
                if (is_array($details)) {
                    $rows[] = [
                        $name,
                        $details['type'] ?? 'unknown',
                        isset($details['nullable']) && $details['nullable'] ? 'Yes' : 'No',
                        $details['default'] ?? '-',
                    ];
                } else {
                    $rows[] = [$name, $details, 'No', '-'];
                }
            }
            $this->table(['Field', 'Type', 'Nullable', 'Default'], $rows);
        } else {
            // If no attributes found, show a message
            $this->info('Attributes: No attributes information available');
        }

        // Display relationships
        if (! empty($analysis['relationships'])) {
            $this->newLine();
            $this->info('Relationships:');
            $rows = [];
            foreach ($analysis['relationships'] as $name => $details) {
                if (is_array($details)) {
                    $rows[] = [
                        $name,
                        $details['type'] ?? 'unknown',
                        $details['model'] ?? $details['related'] ?? '-',
                    ];
                } else {
                    $rows[] = [$name, 'unknown', $details];
                }
            }
            $this->table(['Relationship', 'Type', 'Model'], $rows);
        }

        // Display record count - check the model class for actual count
        try {
            $modelClass = is_array($analysis['model'])
                ? ($analysis['model']['class'] ?? null)
                : null;

            if (! $modelClass) {
                // Try to get from the argument
                $modelClass = $this->argument('model');
            }

            if ($modelClass && class_exists($modelClass)) {
                $count = $modelClass::count();
                $this->newLine();
                $this->line('Record Count: '.$count);
            }
        } catch (\Exception $e) {
            // Silently fail if we can't get the count
        }

        // Display statistics if detailed
        if ($this->option('detailed') && ! empty($analysis['statistics'])) {
            $this->newLine();
            $this->info('Statistics:');
            foreach ($analysis['statistics'] as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
        }
    }
}
