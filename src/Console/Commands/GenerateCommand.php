<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Mint;

class GenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mint:generate 
                            {model : The model class to generate data for}
                            {count=10 : Number of records to generate}
                            {--attributes=* : Custom attributes in key=value format}
                            {--pattern= : Pattern to apply}
                            {--seed= : Random seed for consistent generation}
                            {--silent : Suppress output}
                            {--performance : Performance mode for large datasets}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate test data for a model';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $model = $this->argument('model');
        $count = (int) $this->argument('count');
        
        // Parse attributes
        $attributes = [];
        $attributeStrings = $this->option('attributes');
        
        if (is_string($attributeStrings)) {
            $attributeStrings = [$attributeStrings];
        }
        
        foreach ($attributeStrings as $attrString) {
            if (strpos($attrString, ',') !== false) {
                // Handle comma-separated attributes
                $pairs = explode(',', $attrString);
                foreach ($pairs as $pair) {
                    if (strpos($pair, '=') !== false) {
                        [$key, $value] = explode('=', $pair, 2);
                        $attributes[trim($key)] = $this->parseValue(trim($value));
                    }
                }
            } elseif (strpos($attrString, '=') !== false) {
                [$key, $value] = explode('=', $attrString, 2);
                $attributes[trim($key)] = $this->parseValue(trim($value));
            }
        }
        
        $options = array_merge($attributes, [
            'pattern' => $this->option('pattern'),
            'seed' => $this->option('seed'),
            'silent' => $this->option('silent'),
            'performance' => $this->option('performance'),
        ]);
        
        // Remove null options
        $options = array_filter($options, fn($v) => $v !== null);
        
        try {
            $mint = app(Mint::class);
            
            if (!$this->option('silent')) {
                $this->info("Generating {$count} {$model} records...");
            }
            
            $result = $mint->generate($model, $count, $options);
            
            if (!$this->option('silent')) {
                $this->info("Successfully generated {$count} records.");
            }
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to generate data: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    /**
     * Parse attribute value
     */
    protected function parseValue($value)
    {
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if ($value === 'null') return null;
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }
        return $value;
    }
}