<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Export\ExportManager;

class ExportCommand extends Command
{
    protected $signature = 'mint:export 
                            {format : Export format (csv, json, excel, sql)}
                            {--model=* : Model classes to export}
                            {--output= : Output file path}
                            {--fields=* : Fields to export in format Model:field1,field2}
                            {--where=* : Conditions in format Model:column:operator:value}
                            {--compress : Compress the output file}
                            {--chunk-size=1000 : Number of records to process at once}
                            {--template= : Use a predefined export template}';

    protected $description = 'Export data to various file formats';

    public function handle()
    {
        $format = $this->argument('format');
        $models = $this->option('model');
        
        if (empty($models) && !$this->option('template')) {
            $this->error('Please specify at least one model to export or use a template');
            return 1;
        }
        
        $this->info("Exporting to {$format} format...");
        
        $manager = new ExportManager();
        
        // Load template if specified
        if ($template = $this->option('template')) {
            try {
                $manager = ExportManager::fromTemplate($template);
                $this->info("Using template: {$template}");
            } catch (\Exception $e) {
                $this->error("Failed to load template: {$e->getMessage()}");
                return 1;
            }
        } else {
            // Configure models
            foreach ($models as $model) {
                if (!class_exists($model)) {
                    $this->error("Model not found: {$model}");
                    return 1;
                }
                
                // Parse fields
                $fields = null;
                foreach ($this->option('fields') as $fieldSpec) {
                    if (strpos($fieldSpec, ':') !== false) {
                        [$modelName, $fieldList] = explode(':', $fieldSpec, 2);
                        if ($modelName === class_basename($model)) {
                            $fields = explode(',', $fieldList);
                            break;
                        }
                    }
                }
                
                $manager->model($model, $fields);
                
                // Parse conditions
                foreach ($this->option('where') as $condition) {
                    $parts = explode(':', $condition);
                    if (count($parts) >= 3 && $parts[0] === class_basename($model)) {
                        $column = $parts[1];
                        $operator = $parts[2];
                        $value = $parts[3] ?? null;
                        
                        if ($value === null) {
                            $value = $operator;
                            $operator = '=';
                        }
                        
                        $manager->where($model, $column, $operator, $value);
                    }
                }
            }
        }
        
        // Configure options
        $manager->chunkSize((int)$this->option('chunk-size'));
        
        if ($this->option('compress')) {
            $manager->compress();
        }
        
        // Show progress
        $this->info('Starting export...');
        $bar = $this->output->createProgressBar();
        
        try {
            $outputPath = $this->option('output');
            $result = $manager->export($format, $outputPath);
            
            $bar->finish();
            $this->newLine(2);
            
            // Display results
            if ($result->isSuccess()) {
                $this->info('✓ Export completed successfully');
                
                $data = $result->toArray();
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Format', $data['format']],
                        ['Output File', basename($data['output_path'])],
                        ['File Size', $data['file_size']],
                        ['Total Exported', $data['total_exported']],
                        ['Execution Time', $data['execution_time']],
                    ]
                );
                
                if (!empty($data['exported'])) {
                    $this->newLine();
                    $this->info('Exported by model:');
                    foreach ($data['exported'] as $model => $count) {
                        $modelName = class_basename($model);
                        $this->line("  • {$modelName}: {$count}");
                    }
                }
                
                $this->newLine();
                $this->info('File saved to: ' . $data['output_path']);
            } else {
                $this->error('✗ Export failed');
                
                $data = $result->toArray();
                if (!empty($data['errors'])) {
                    foreach ($data['errors'] as $error) {
                        $this->error($error);
                    }
                }
            }
            
            return $result->isSuccess() ? 0 : 1;
        } catch (\Exception $e) {
            $bar->finish();
            $this->newLine(2);
            $this->error('Export failed: ' . $e->getMessage());
            
            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }
            
            return 1;
        }
    }
}