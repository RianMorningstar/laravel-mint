<?php

namespace LaravelMint\Commands;

use Illuminate\Console\Command;
use LaravelMint\Facades\Mint;
use Symfony\Component\Console\Helper\Table;

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
                            {--relationships : Include detailed relationship analysis}
                            {--schema : Include detailed schema analysis}
                            {--all : Include all analysis details}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze a Laravel model to understand its structure and relationships';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modelClass = $this->argument('model');

        // Prepend App\Models if not fully qualified
        if (! str_contains($modelClass, '\\')) {
            $modelClass = 'App\\Models\\'.$modelClass;
        }

        // Check if model exists
        if (! class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist");

            return 1;
        }

        $this->info("Analyzing model: {$modelClass}");
        $this->newLine();

        try {
            $analysis = Mint::analyze($modelClass);

            if ($this->option('json')) {
                $this->outputJson($analysis);
            } else {
                $this->outputFormatted($analysis);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error analyzing model: '.$e->getMessage());

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return 1;
        }
    }

    /**
     * Output analysis as JSON
     */
    protected function outputJson(array $analysis): void
    {
        $this->line(json_encode($analysis, JSON_PRETTY_PRINT));
    }

    /**
     * Output formatted analysis
     */
    protected function outputFormatted(array $analysis): void
    {
        $showAll = $this->option('all');

        // Model Information
        $this->outputModelInfo($analysis['model']);

        // Schema Information
        if ($showAll || $this->option('schema')) {
            $this->outputSchemaInfo($analysis['schema']);
        }

        // Relationships
        if ($showAll || $this->option('relationships')) {
            $this->outputRelationships($analysis['relationships']);
        }

        // Summary
        $this->outputSummary($analysis);
    }

    /**
     * Output model information
     */
    protected function outputModelInfo(array $modelData): void
    {
        $this->comment('Model Information:');

        $table = new Table($this->output);
        $table->setHeaders(['Property', 'Value']);

        $rows = [
            ['Table', $modelData['table'] ?? 'N/A'],
            ['Primary Key', $modelData['primary_key'] ?? 'id'],
            ['Key Type', $modelData['key_type'] ?? 'int'],
            ['Incrementing', $modelData['incrementing'] ? 'Yes' : 'No'],
            ['Timestamps', $modelData['timestamps'] ? 'Yes' : 'No'],
        ];

        $table->setRows($rows);
        $table->render();
        $this->newLine();

        // Fillable Fields
        if (! empty($modelData['fillable'])) {
            $this->comment('Fillable Fields:');
            foreach ($modelData['fillable'] as $field) {
                $this->line("  • {$field}");
            }
            $this->newLine();
        }

        // Guarded Fields
        if (! empty($modelData['guarded']) && $modelData['guarded'] !== ['*']) {
            $this->comment('Guarded Fields:');
            foreach ($modelData['guarded'] as $field) {
                $this->line("  • {$field}");
            }
            $this->newLine();
        }

        // Casts
        if (! empty($modelData['casts'])) {
            $this->comment('Attribute Casts:');
            foreach ($modelData['casts'] as $attribute => $cast) {
                $this->line("  • {$attribute}: {$cast}");
            }
            $this->newLine();
        }

        // Validation Rules
        if (! empty($modelData['validation_rules'])) {
            $this->comment('Validation Rules:');
            foreach ($modelData['validation_rules'] as $field => $rules) {
                $rulesString = is_array($rules) ? implode('|', $rules) : $rules;
                $this->line("  • {$field}: {$rulesString}");
            }
            $this->newLine();
        }
    }

    /**
     * Output schema information
     */
    protected function outputSchemaInfo(array $schemaData): void
    {
        $this->comment('Database Schema:');

        // Table Info
        $this->line("  Table: {$schemaData['table']}");
        $this->line("  Rows: {$schemaData['row_count']}");

        if ($schemaData['engine']) {
            $this->line("  Engine: {$schemaData['engine']}");
        }
        if ($schemaData['collation']) {
            $this->line("  Collation: {$schemaData['collation']}");
        }

        $this->newLine();

        // Columns
        $this->comment('Columns:');
        $table = new Table($this->output);
        $table->setHeaders(['Column', 'Type', 'Nullable', 'Default', 'Extra']);

        $rows = [];
        foreach ($schemaData['columns'] as $columnName => $columnData) {
            $rows[] = [
                $columnName,
                $columnData['type'] ?? 'unknown',
                $columnData['nullable'] ? 'Yes' : 'No',
                $columnData['default'] ?? 'NULL',
                $this->getColumnExtra($columnData),
            ];
        }

        $table->setRows($rows);
        $table->render();
        $this->newLine();

        // Indexes
        if (! empty($schemaData['indexes'])) {
            $this->comment('Indexes:');
            foreach ($schemaData['indexes'] as $index) {
                $type = $index['primary'] ? 'PRIMARY' : ($index['unique'] ? 'UNIQUE' : 'INDEX');
                $columns = implode(', ', $index['columns']);
                $this->line("  • {$index['name']} ({$type}): [{$columns}]");
            }
            $this->newLine();
        }

        // Foreign Keys
        if (! empty($schemaData['foreign_keys'])) {
            $this->comment('Foreign Keys:');
            foreach ($schemaData['foreign_keys'] as $fk) {
                $this->line("  • {$fk['column']} → {$fk['foreign_table']}.{$fk['foreign_column']}");
            }
            $this->newLine();
        }
    }

    /**
     * Get column extra information
     */
    protected function getColumnExtra(array $columnData): string
    {
        $extra = [];

        if ($columnData['auto_increment'] ?? false) {
            $extra[] = 'AUTO_INCREMENT';
        }

        if ($columnData['unsigned'] ?? false) {
            $extra[] = 'UNSIGNED';
        }

        if (isset($columnData['generation_hints']['faker'])) {
            $extra[] = 'Faker: '.$columnData['generation_hints']['faker'];
        }

        return implode(', ', $extra) ?: '-';
    }

    /**
     * Output relationships
     */
    protected function outputRelationships(array $relationshipData): void
    {
        $this->comment('Relationships:');

        $relationships = $relationshipData['relationships'] ?? [];

        if (empty($relationships)) {
            $this->line('  No relationships detected');
            $this->newLine();

            return;
        }

        foreach ($relationships as $name => $relation) {
            $type = $relation['type'] ?? 'unknown';
            $related = $relation['related_model'] ?? 'N/A';
            $this->line("  • {$name}() → {$type} → {$related}");

            if ($this->option('verbose')) {
                if (isset($relation['foreign_key'])) {
                    $this->line("      Foreign Key: {$relation['foreign_key']}");
                }
                if (isset($relation['local_key'])) {
                    $this->line("      Local Key: {$relation['local_key']}");
                }
                if (isset($relation['pivot_table'])) {
                    $this->line("      Pivot Table: {$relation['pivot_table']}");
                }
            }
        }
        $this->newLine();

        // Dependencies
        $dependencies = $relationshipData['dependencies'] ?? [];

        if (! empty($dependencies['required'])) {
            $this->comment('Required Dependencies:');
            foreach ($dependencies['required'] as $dep) {
                $this->line("  • {$dep['model']} (via {$dep['relation']})");
            }
            $this->newLine();
        }

        if (! empty($dependencies['dependent'])) {
            $this->comment('Dependent Models:');
            foreach ($dependencies['dependent'] as $dep) {
                $this->line("  • {$dep['model']} (via {$dep['relation']})");
            }
            $this->newLine();
        }

        // Generation Order
        $order = $relationshipData['generation_order'] ?? [];
        if (! empty($order)) {
            $this->comment('Generation Strategy:');
            $this->line("  Priority: {$order['priority']}");
            $this->line("  Strategy: {$order['strategy']}");
            $this->line('  Can Parallelize: '.($order['can_parallelize'] ? 'Yes' : 'No'));

            if (isset($order['warning'])) {
                $this->warn("  ⚠️  {$order['warning']}");
            }
            $this->newLine();
        }
    }

    /**
     * Output analysis summary
     */
    protected function outputSummary(array $analysis): void
    {
        $this->info('Analysis Summary:');

        $columnCount = count($analysis['schema']['columns'] ?? []);
        $relationshipCount = count($analysis['relationships']['relationships'] ?? []);
        $indexCount = count($analysis['schema']['indexes'] ?? []);
        $foreignKeyCount = count($analysis['schema']['foreign_keys'] ?? []);

        $this->line("  • {$columnCount} columns");
        $this->line("  • {$relationshipCount} relationships");
        $this->line("  • {$indexCount} indexes");
        $this->line("  • {$foreignKeyCount} foreign keys");

        $this->newLine();
        $this->info('✓ Analysis complete');
    }
}
