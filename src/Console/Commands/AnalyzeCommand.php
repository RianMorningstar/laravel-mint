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
            $this->error("Failed to analyze model: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Display analysis in table format
     */
    protected function displayAnalysis(array $analysis): void
    {
        $this->info("Model Analysis: " . ($analysis['model'] ?? 'Unknown'));
        $this->newLine();
        
        // Display fields
        if (!empty($analysis['fields'])) {
            $this->info('Fields:');
            $rows = [];
            foreach ($analysis['fields'] as $name => $details) {
                $rows[] = [
                    $name,
                    $details['type'] ?? 'unknown',
                    isset($details['nullable']) && $details['nullable'] ? 'Yes' : 'No',
                    $details['default'] ?? '-',
                ];
            }
            $this->table(['Field', 'Type', 'Nullable', 'Default'], $rows);
        }
        
        // Display relationships
        if (!empty($analysis['relationships'])) {
            $this->newLine();
            $this->info('Relationships:');
            $rows = [];
            foreach ($analysis['relationships'] as $name => $details) {
                $rows[] = [
                    $name,
                    $details['type'] ?? 'unknown',
                    $details['model'] ?? '-',
                ];
            }
            $this->table(['Relationship', 'Type', 'Model'], $rows);
        }
        
        // Display statistics if detailed
        if ($this->option('detailed') && !empty($analysis['statistics'])) {
            $this->newLine();
            $this->info('Statistics:');
            foreach ($analysis['statistics'] as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
        }
    }
}