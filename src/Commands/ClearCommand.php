<?php

namespace LaravelMint\Commands;

use Illuminate\Console\Command;
use LaravelMint\Facades\Mint;

class ClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mint:clear 
                            {model? : The model class to clear data for}
                            {--all : Clear all generated data}
                            {--force : Force clear without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear generated test data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $clearAll = $this->option('all');
        $force = $this->option('force');
        
        if (!$modelClass && !$clearAll) {
            $this->error('Please provide a model class or use --all option');
            return 1;
        }
        
        // Confirm action
        if (!$force) {
            $message = $clearAll 
                ? 'This will clear ALL generated data. Are you sure?'
                : "This will clear all data from the specified model. Are you sure?";
                
            if (!$this->confirm($message)) {
                $this->info('Operation cancelled');
                return 0;
            }
        }
        
        try {
            if ($clearAll) {
                // TODO: Implement clearing all generated data
                // This would require tracking which data was generated
                $this->warn('Clear all functionality not yet implemented');
                $this->info('Please clear individual models for now');
                return 1;
            }
            
            // Prepend App\Models if not fully qualified
            if (!str_contains($modelClass, '\\')) {
                $modelClass = 'App\\Models\\' . $modelClass;
            }
            
            // Check if model exists
            if (!class_exists($modelClass)) {
                $this->error("Model class {$modelClass} does not exist");
                return 1;
            }
            
            $this->info("Clearing data for: {$modelClass}");
            
            $deleted = Mint::clear($modelClass);
            
            $this->info("✓ Successfully deleted {$deleted} records");
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Error clearing data: " . $e->getMessage());
            
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            
            return 1;
        }
    }
}