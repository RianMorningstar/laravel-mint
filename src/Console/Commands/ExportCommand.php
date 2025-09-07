<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Mint;

class ExportCommand extends Command
{
    protected $signature = 'mint:export 
                            {model : The model class to export}
                            {file : Path to save the export file}
                            {--format=json : Export format (json, csv)}
                            {--where=* : Conditions in key=value format}';

    protected $description = 'Export model data to a file';

    public function handle(): int
    {
        $model = $this->argument('model');
        $file = $this->argument('file');
        $format = $this->option('format');
        
        $conditions = [];
        foreach ($this->option('where') as $where) {
            if (strpos($where, '=') !== false) {
                [$key, $value] = explode('=', $where, 2);
                $conditions[$key] = $value;
            }
        }
        
        try {
            $mint = app(Mint::class);
            $mint->export($model, $file, $format, $conditions);
            
            $this->info("Data exported to {$file}");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Export failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}