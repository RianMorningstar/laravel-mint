<?php

namespace LaravelMint\Generators;

use LaravelMint\Mint;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SimpleGenerator extends DataGenerator
{
    protected array $generatedModels = [];
    protected array $relationshipCache = [];

    /**
     * Generate data for a model
     */
    public function generate(string $modelClass, int $count, array $options = []): Collection
    {
        $this->options = array_merge($this->options, $options);
        $generated = collect();
        
        // Show progress if in CLI
        if (php_sapi_name() === 'cli' && !($options['silent'] ?? false)) {
            $this->showProgress("Generating {$count} {$modelClass} records");
        }
        
        // Generate in chunks
        $this->generateInChunks($modelClass, $count, function ($chunk, $current, $total) use (&$generated, $modelClass) {
            // Insert chunk into database
            $this->insertRecords($modelClass, $chunk);
            
            // Fetch the created models from database
            // Get the IDs of the last inserted records
            $insertedCount = count($chunk);
            $models = $modelClass::orderBy('id', 'desc')->take($insertedCount)->get()->reverse()->values();
            
            $generated = $generated->merge($models);
            
            if (php_sapi_name() === 'cli') {
                $this->updateProgress($current, $total);
            }
        });
        
        // Handle relationships after all base records are created
        if (!empty($this->analysis['relationships'])) {
            $this->processRelationships($modelClass, $generated);
        }
        
        if (php_sapi_name() === 'cli') {
            $this->completeProgress();
        }
        
        return $generated;
    }

    /**
     * Generate a single record
     */
    protected function generateRecord(string $modelClass, array $overrides = []): array
    {
        $record = [];
        $modelAnalysis = $this->analysis['model'] ?? [];
        $schemaAnalysis = $this->analysis['schema'] ?? [];
        $columns = $schemaAnalysis['columns'] ?? [];
        
        // If no columns found in analysis, try to get them from the model
        if (empty($columns)) {
            try {
                $instance = new $modelClass();
                $fillable = $instance->getFillable();
                
                // If fillable is defined, use those columns
                if (!empty($fillable)) {
                    foreach ($fillable as $field) {
                        $columns[$field] = ['type' => 'string', 'nullable' => false];
                    }
                } else {
                    // Use some default columns
                    $columns = [
                        'name' => ['type' => 'string', 'nullable' => false],
                        'value' => ['type' => 'integer', 'nullable' => true],
                    ];
                }
            } catch (\Exception $e) {
                // Default fallback columns
                $columns = [
                    'name' => ['type' => 'string', 'nullable' => false],
                ];
            }
        }
        
        // Get fillable fields
        $fillable = $modelAnalysis['fillable'] ?? [];
        $guarded = $modelAnalysis['guarded'] ?? [];
        
        // If fillable is empty and guarded only has default Laravel guards, get all columns
        if (empty($fillable) && (empty($guarded) || $guarded === ['*'])) {
            $fillable = array_keys($columns);
        } elseif (empty($fillable) && !empty($guarded)) {
            // Get all columns except guarded
            $fillable = array_diff(array_keys($columns), $guarded);
        }
        
        // Generate values for each fillable column
        foreach ($fillable as $column) {
            // Skip if in overrides
            if (array_key_exists($column, $overrides)) {
                $record[$column] = $overrides[$column];
                continue;
            }
            
            // Skip primary key if auto-incrementing
            if ($column === $modelAnalysis['primary_key'] && $modelAnalysis['incrementing']) {
                continue;
            }
            
            // Skip timestamps - Laravel will handle these
            if ($modelAnalysis['timestamps'] && in_array($column, ['created_at', 'updated_at'])) {
                continue;
            }
            
            // Skip if column doesn't exist in schema
            if (!isset($columns[$column])) {
                continue;
            }
            
            // Check if this is a foreign key or foreign key pattern
            $foreignKey = $this->findForeignKey($column, $schemaAnalysis['foreign_keys'] ?? []);
            if ($foreignKey) {
                $record[$column] = $this->generateForeignKeyValue($foreignKey);
            } elseif ($this->looksLikeForeignKey($column)) {
                // Handle common foreign key patterns even if not detected in schema
                $record[$column] = $this->generateForeignKeyByPattern($column);
            } elseif ($this->isSpecialField($column)) {
                // Handle special fields like status, order_number, etc.
                $record[$column] = $this->generateSpecialField($column, $columns[$column] ?? ['type' => 'string']);
            } else {
                // Generate normal column value
                $columnDetails = $columns[$column] ?? ['type' => 'string', 'nullable' => false];
                $record[$column] = $this->generateColumnValue($column, $columnDetails);
            }
        }
        
        // Apply any casts
        $record = $this->applyCasts($record, $modelAnalysis['casts'] ?? []);
        
        // Add timestamps if needed
        if ($modelAnalysis['timestamps']) {
            $now = now();
            $record['created_at'] = $now;
            $record['updated_at'] = $now;
        }
        
        return array_merge($record, $overrides);
    }

    /**
     * Find foreign key information for a column
     */
    protected function findForeignKey(string $column, array $foreignKeys): ?array
    {
        foreach ($foreignKeys as $fk) {
            if ($fk['column'] === $column) {
                return $fk;
            }
        }
        return null;
    }

    /**
     * Generate a foreign key value
     */
    protected function generateForeignKeyValue(array $foreignKey): ?int
    {
        $foreignTable = $foreignKey['foreign_table'];
        $foreignColumn = $foreignKey['foreign_column'] ?? 'id';
        
        // Try to get an existing ID from the foreign table
        $connection = $this->mint->getConnection();
        
        // Cache foreign key values for performance
        $cacheKey = "{$foreignTable}.{$foreignColumn}";
        if (!isset($this->relationshipCache[$cacheKey])) {
            $this->relationshipCache[$cacheKey] = $connection->table($foreignTable)
                ->pluck($foreignColumn)
                ->toArray();
        }
        
        if (empty($this->relationshipCache[$cacheKey])) {
            // No records in foreign table, return null if nullable
            // This should be handled by proper generation order
            return null;
        }
        
        // Return a random foreign key value
        return $this->faker->randomElement($this->relationshipCache[$cacheKey]);
    }

    /**
     * Apply model casts to generated data
     */
    protected function applyCasts(array $record, array $casts): array
    {
        foreach ($casts as $column => $cast) {
            if (!isset($record[$column])) {
                continue;
            }
            
            $value = $record[$column];
            
            switch ($cast) {
                case 'boolean':
                case 'bool':
                    $record[$column] = (bool) $value;
                    break;
                    
                case 'integer':
                case 'int':
                    $record[$column] = (int) $value;
                    break;
                    
                case 'float':
                case 'double':
                case 'real':
                    $record[$column] = (float) $value;
                    break;
                    
                case 'string':
                    $record[$column] = (string) $value;
                    break;
                    
                case 'array':
                case 'json':
                    // For JSON columns, we need to keep the JSON string
                    // Arrays are for PHP manipulation, not database storage
                    if (is_string($value)) {
                        $record[$column] = $value; // Already JSON string
                    } elseif (is_array($value)) {
                        $record[$column] = json_encode($value);
                    } else {
                        $record[$column] = json_encode([$value]);
                    }
                    break;
                    
                case 'object':
                    if (is_string($value)) {
                        $record[$column] = json_decode($value) ?? new \stdClass();
                    } elseif (is_array($value)) {
                        $record[$column] = (object) $value;
                    }
                    break;
                    
                case 'collection':
                    $record[$column] = collect($value);
                    break;
                    
                case 'date':
                case 'datetime':
                case 'custom_datetime':
                case 'immutable_date':
                case 'immutable_datetime':
                case 'timestamp':
                    // These will be handled by Eloquent
                    break;
                    
                default:
                    // Custom cast classes - leave as is
                    break;
            }
        }
        
        return $record;
    }

    /**
     * Process relationships for generated models
     */
    protected function processRelationships(string $modelClass, Collection $generated): void
    {
        $relationships = $this->analysis['relationships'] ?? [];
        
        if (empty($relationships)) {
            return;
        }
        
        // Get actual model instances
        $primaryKey = $this->analysis['model']['primary_key'] ?? 'id';
        $ids = $generated->pluck($primaryKey)->toArray();
        
        if (empty($ids)) {
            return;
        }
        
        $models = $modelClass::whereIn($primaryKey, $ids)->get();
        
        foreach ($models as $model) {
            $this->handleRelationships($model, $relationships);
        }
    }

    /**
     * Handle relationships for a model instance
     */
    protected function handleRelationships(Model $model, array $relationships): void
    {
        foreach ($relationships as $relationName => $relationData) {
            $relationType = $relationData['type'] ?? '';
            $relatedModel = $relationData['related_model'] ?? null;
            
            if (!$relatedModel || !class_exists($relatedModel)) {
                continue;
            }
            
            switch ($relationType) {
                case 'hasOne':
                    $this->handleHasOne($model, $relationName, $relatedModel, $relationData);
                    break;
                    
                case 'hasMany':
                    $this->handleHasMany($model, $relationName, $relatedModel, $relationData);
                    break;
                    
                case 'belongsToMany':
                    $this->handleBelongsToMany($model, $relationName, $relatedModel, $relationData);
                    break;
                    
                // BelongsTo is handled during record generation via foreign keys
                // MorphTo, MorphOne, MorphMany etc. can be added later
            }
        }
    }

    /**
     * Handle hasOne relationship
     */
    protected function handleHasOne(Model $model, string $relationName, string $relatedModel, array $relationData): void
    {
        // Randomly decide if this relationship should exist
        if (!$this->faker->boolean(70)) { // 70% chance of having the relationship
            return;
        }
        
        $foreignKey = $relationData['foreign_key'] ?? Str::snake(class_basename($model)) . '_id';
        
        // Check if related record already exists
        $existing = $relatedModel::where($foreignKey, $model->getKey())->exists();
        if ($existing) {
            return;
        }
        
        // Create related record
        $relatedInstance = new $relatedModel();
        $relatedAnalysis = $this->mint->analyze($relatedModel);
        
        // Generate data for related model
        $relatedGenerator = new self($this->mint, $relatedAnalysis, $this->options);
        $relatedData = $relatedGenerator->generateRecord($relatedModel, [
            $foreignKey => $model->getKey()
        ]);
        
        $relatedModel::create($relatedData);
    }

    /**
     * Handle hasMany relationship
     */
    protected function handleHasMany(Model $model, string $relationName, string $relatedModel, array $relationData): void
    {
        // Generate random number of related records
        $count = $this->faker->numberBetween(0, 5); // Adjust as needed
        
        if ($count === 0) {
            return;
        }
        
        $foreignKey = $relationData['foreign_key'] ?? Str::snake(class_basename($model)) . '_id';
        
        // Create related records
        $relatedInstance = new $relatedModel();
        $relatedAnalysis = $this->mint->analyze($relatedModel);
        
        $relatedGenerator = new self($this->mint, $relatedAnalysis, $this->options);
        
        for ($i = 0; $i < $count; $i++) {
            $relatedData = $relatedGenerator->generateRecord($relatedModel, [
                $foreignKey => $model->getKey()
            ]);
            
            $relatedModel::create($relatedData);
        }
    }

    /**
     * Handle belongsToMany relationship
     */
    protected function handleBelongsToMany(Model $model, string $relationName, string $relatedModel, array $relationData): void
    {
        // Get some existing related models
        $relatedCount = $relatedModel::count();
        
        if ($relatedCount === 0) {
            return;
        }
        
        // Attach random number of related models
        $attachCount = min($this->faker->numberBetween(0, 3), $relatedCount);
        
        if ($attachCount === 0) {
            return;
        }
        
        $relatedIds = $relatedModel::inRandomOrder()
            ->limit($attachCount)
            ->pluck('id')
            ->toArray();
        
        // Attach with potential pivot data
        $pivotData = [];
        foreach ($relatedIds as $relatedId) {
            $pivotData[$relatedId] = [
                'created_at' => now(),
                'updated_at' => now(),
            ];
            
            // Add any additional pivot columns here if needed
        }
        
        try {
            $model->$relationName()->syncWithoutDetaching($pivotData);
        } catch (\Exception $e) {
            // Silently fail if relationship doesn't exist or has issues
        }
    }

    /**
     * Show progress indicator
     */
    protected function showProgress(string $message): void
    {
        echo "\n{$message}\n";
    }

    /**
     * Update progress indicator
     */
    protected function updateProgress(int $current, int $total): void
    {
        $percentage = round(($current / $total) * 100);
        $bar = str_repeat('=', (int)($percentage / 2));
        $spaces = str_repeat(' ', 50 - strlen($bar));
        
        echo "\r[{$bar}{$spaces}] {$percentage}% ({$current}/{$total} chunks)";
    }

    /**
     * Complete progress indicator
     */
    protected function completeProgress(): void
    {
        echo "\n✓ Generation complete\n";
        
        $stats = $this->getStatistics();
        echo "  Generated: {$stats['generated_count']} records\n";
        echo "  Memory: {$stats['memory_usage']} (peak: {$stats['peak_memory']})\n";
    }

    /**
     * Check if a column looks like a foreign key
     */
    protected function looksLikeForeignKey(string $column): bool
    {
        return str_ends_with($column, '_id') && $column !== 'id';
    }

    /**
     * Generate foreign key value by pattern
     */
    protected function generateForeignKeyByPattern(string $column): ?int
    {
        // Extract table name from column (e.g., user_id -> users)
        $tableName = Str::plural(str_replace('_id', '', $column));
        
        // Try to get existing IDs from the table
        $connection = $this->mint->getConnection();
        
        try {
            $ids = $connection->table($tableName)->pluck('id')->toArray();
            
            if (empty($ids)) {
                // No records in foreign table
                return null;
            }
            
            return $this->faker->randomElement($ids);
        } catch (\Exception $e) {
            // Table doesn't exist or other error
            return null;
        }
    }

    /**
     * Check if field needs special handling
     */
    protected function isSpecialField(string $column): bool
    {
        $specialFields = [
            'status',
            'state',
            'type',
            'role',
            'order_number',
            'invoice_number',
            'reference',
            'code',
            'slug',
            'uuid',
            'total',
            'subtotal',
            'tax',
            'discount',
            'amount',
        ];
        
        return in_array($column, $specialFields) || 
               str_ends_with($column, '_status') ||
               str_ends_with($column, '_state') ||
               str_ends_with($column, '_number') ||
               str_ends_with($column, '_code') ||
               str_ends_with($column, '_total') ||
               str_ends_with($column, '_amount');
    }

    /**
     * Generate special field value
     */
    protected function generateSpecialField(string $column, array $columnDetails): mixed
    {
        $columnLower = strtolower($column);
        
        // Status fields - check table context first
        if (str_contains($columnLower, 'status') || $column === 'status') {
            // Get table name from analysis to determine context
            $table = $this->analysis['model']['table'] ?? '';
            
            if (str_contains($table, 'order')) {
                return $this->faker->randomElement(['pending', 'processing', 'completed', 'cancelled']);
            } elseif (str_contains($table, 'payment')) {
                return $this->faker->randomElement(['pending', 'paid', 'failed', 'refunded']);
            } else {
                return $this->faker->randomElement(['active', 'inactive', 'pending', 'completed']);
            }
        }
        
        // Number fields (order_number, invoice_number, etc.)
        if (str_contains($columnLower, '_number') || $columnLower === 'reference' || $columnLower === 'code') {
            $prefix = strtoupper(substr($column, 0, 3));
            return $prefix . '-' . $this->faker->unique()->numberBetween(100000, 999999);
        }
        
        // UUID fields
        if (str_contains($columnLower, 'uuid')) {
            return $this->faker->uuid();
        }
        
        // Slug fields
        if (str_contains($columnLower, 'slug')) {
            return $this->faker->slug();
        }
        
        // State fields
        if (str_contains($columnLower, 'state')) {
            return $this->faker->randomElement(['draft', 'published', 'archived']);
        }
        
        // Type fields
        if (str_contains($columnLower, 'type')) {
            return $this->faker->randomElement(['standard', 'premium', 'basic', 'advanced']);
        }
        
        // Role fields
        if (str_contains($columnLower, 'role')) {
            return $this->faker->randomElement(['admin', 'user', 'moderator', 'guest']);
        }
        
        // Amount/Total fields - generate decimal values
        if (in_array($columnLower, ['total', 'subtotal', 'tax', 'discount', 'amount']) ||
            str_contains($columnLower, '_total') || 
            str_contains($columnLower, '_amount')) {
            return $this->faker->randomFloat(2, 10, 10000);
        }
        
        // Default to normal generation
        return $this->generateColumnValue($column, $columnDetails);
    }
}