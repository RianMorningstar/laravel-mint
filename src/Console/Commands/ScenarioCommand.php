<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Mint;

class ScenarioCommand extends Command
{
    protected $signature = 'mint:scenario 
                            {scenario : The scenario to run}
                            {--list : List available scenarios}
                            {--scale=1 : Scale factor for scenario}
                            {--options=* : Scenario options in key=value format}';

    protected $description = 'Run a predefined data generation scenario';

    public function handle(): int
    {
        $mint = app(Mint::class);
        
        if ($this->option('list')) {
            $this->listScenarios($mint);
            return self::SUCCESS;
        }
        
        $scenario = $this->argument('scenario');
        
        $options = [];
        foreach ($this->option('options') as $option) {
            if (strpos($option, '=') !== false) {
                [$key, $value] = explode('=', $option, 2);
                $options[$key] = $value;
            }
        }
        
        // Add scale option
        $scale = (float) $this->option('scale');
        if ($scale != 1) {
            $options['scale'] = $scale;
        }
        
        try {
            $this->info("Running scenario: {$scenario}");
            $mint->generateWithScenario($scenario, $options);
            $this->info("Scenario completed successfully.");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Scenario failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
    
    protected function listScenarios($mint): void
    {
        $manager = $mint->getScenarioManager();
        $scenarios = $manager->getAvailableScenarios();
        
        $this->info("Available scenarios:");
        foreach ($scenarios as $key => $scenario) {
            $this->line(" - {$key}: " . ($scenario['description'] ?? 'No description'));
        }
    }
}