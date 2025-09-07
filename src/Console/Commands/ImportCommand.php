<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Mint;

class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mint:import 
                            {model : The model class to import data into}
                            {file : Path to the import file}
                            {--format=json : File format (json, csv)}
                            {--chunk=1000 : Import chunk size}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import test data from a file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $model = $this->argument('model');
        $file = $this->argument('file');
        $format = $this->option('format');
        $chunkSize = (int) $this->option('chunk');
        
        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }
        
        try {
            $mint = app(Mint::class);
            
            $this->info("Importing data from {$file}...");
            
            $result = $mint->import($model, $file, $format, [
                'chunk_size' => $chunkSize,
            ]);
            
            $this->info("Successfully imported {$result['imported']} records.");
            
            if (!empty($result['errors'])) {
                $this->warn("Encountered {$result['errors']} errors during import.");
            }
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to import data: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}