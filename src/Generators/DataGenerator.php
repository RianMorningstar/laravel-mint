<?php

namespace LaravelMint\Generators;

use LaravelMint\Mint;
use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class DataGenerator
{
    protected Mint $mint;
    protected array $analysis;
    protected FakerGenerator $faker;
    protected array $options = [];
    protected int $generatedCount = 0;
    protected array $cache = [];
    protected array $statistics = [];

    public function __construct(Mint $mint, array $analysis, array $options = [])
    {
        $this->mint = $mint;
        $this->analysis = $analysis;
        $this->options = $options;
        
        // Initialize Faker with seed if provided
        $seed = $this->mint->getConfig('development.seed');
        $this->faker = FakerFactory::create();
        
        if ($seed !== null) {
            $this->faker->seed($seed);
        }
    }

    /**
     * Generate data for a model
     */
    abstract public function generate(string $modelClass, int $count, array $options = []): Collection;

    /**
     * Generate a single record
     */
    abstract protected function generateRecord(string $modelClass, array $overrides = []): array;

    /**
     * Handle relationships for generated records
     */
    abstract protected function handleRelationships(Model $model, array $relationships): void;

    /**
     * Generate data in chunks for memory efficiency
     */
    protected function generateInChunks(string $modelClass, int $count, callable $callback): void
    {
        $chunkSize = $this->mint->getConfig('generation.chunk_size', 1000);
        $chunks = ceil($count / $chunkSize);

        for ($i = 0; $i < $chunks; $i++) {
            $currentChunkSize = min($chunkSize, $count - ($i * $chunkSize));
            $chunk = $this->generateChunk($modelClass, $currentChunkSize);
            
            $callback($chunk, $i + 1, $chunks);
            
            // Free memory
            unset($chunk);
            
            // Check memory usage
            if ($this->isMemoryLimitApproaching()) {
                $this->handleMemoryLimit();
            }
        }
    }

    /**
     * Generate a chunk of records
     */
    protected function generateChunk(string $modelClass, int $size): Collection
    {
        $records = collect();
        
        for ($i = 0; $i < $size; $i++) {
            $records->push($this->generateRecord($modelClass));
            $this->generatedCount++;
        }
        
        return $records;
    }

    /**
     * Insert records into database
     */
    protected function insertRecords(string $modelClass, Collection $records): void
    {
        $useTransactions = $this->mint->getConfig('generation.use_transactions', true);
        $connection = $this->mint->getConnection();
        
        if ($useTransactions) {
            $connection->beginTransaction();
        }
        
        try {
            // Disable foreign key checks if needed
            if (!$this->mint->getConfig('database.foreign_key_checks', true)) {
                $this->disableForeignKeyChecks($connection);
            }
            
            // Insert records
            $modelClass::insert($records->toArray());
            
            // Re-enable foreign key checks
            if (!$this->mint->getConfig('database.foreign_key_checks', true)) {
                $this->enableForeignKeyChecks($connection);
            }
            
            if ($useTransactions) {
                $connection->commit();
            }
        } catch (\Exception $e) {
            if ($useTransactions) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Generate value for a specific column
     */
    protected function generateColumnValue(string $column, array $columnDetails): mixed
    {
        // Check for generation hints
        $hints = $columnDetails['generation_hints'] ?? [];
        
        // Use Faker if hint is provided
        if (isset($hints['faker'])) {
            $fakerMethod = $hints['faker'];
            $params = $hints['params'] ?? [];
            
            if (method_exists($this->faker, $fakerMethod)) {
                return $this->faker->$fakerMethod(...$params);
            }
        }
        
        // Generate based on column type
        $type = $columnDetails['type'] ?? 'string';
        
        return $this->generateByType($type, $columnDetails);
    }

    /**
     * Generate value based on column type
     */
    protected function generateByType(string $type, array $columnDetails): mixed
    {
        $nullable = $columnDetails['nullable'] ?? false;
        
        // Random chance of null for nullable columns
        if ($nullable && $this->faker->boolean(10)) { // 10% chance of null
            return null;
        }
        
        switch ($type) {
            case 'integer':
            case 'bigint':
            case 'smallint':
            case 'tinyint':
                return $this->generateInteger($columnDetails);
                
            case 'decimal':
            case 'float':
            case 'double':
            case 'real':
                return $this->generateFloat($columnDetails);
                
            case 'boolean':
            case 'bool':
                return $this->faker->boolean();
                
            case 'date':
                return $this->faker->date();
                
            case 'datetime':
            case 'timestamp':
                return $this->faker->dateTime();
                
            case 'time':
                return $this->faker->time();
                
            case 'string':
            case 'varchar':
            case 'char':
                return $this->generateString($columnDetails);
                
            case 'text':
            case 'mediumtext':
            case 'longtext':
                return $this->faker->paragraph();
                
            case 'json':
            case 'jsonb':
                return json_encode($this->generateJsonData());
                
            case 'uuid':
                return $this->faker->uuid();
                
            case 'enum':
                return $this->generateEnum($columnDetails);
                
            default:
                return $this->generateString($columnDetails);
        }
    }

    /**
     * Generate integer value
     */
    protected function generateInteger(array $columnDetails): int
    {
        $unsigned = $columnDetails['unsigned'] ?? false;
        $autoIncrement = $columnDetails['auto_increment'] ?? false;
        
        if ($autoIncrement) {
            return 0; // Let database handle auto-increment
        }
        
        $min = $unsigned ? 0 : -2147483648;
        $max = 2147483647;
        
        // Adjust for specific integer types
        if (str_contains($columnDetails['type'] ?? '', 'tiny')) {
            $min = $unsigned ? 0 : -128;
            $max = $unsigned ? 255 : 127;
        } elseif (str_contains($columnDetails['type'] ?? '', 'small')) {
            $min = $unsigned ? 0 : -32768;
            $max = $unsigned ? 65535 : 32767;
        } elseif (str_contains($columnDetails['type'] ?? '', 'big')) {
            $min = $unsigned ? 0 : PHP_INT_MIN;
            $max = PHP_INT_MAX;
        }
        
        return $this->faker->numberBetween($min, $max);
    }

    /**
     * Generate float value
     */
    protected function generateFloat(array $columnDetails): float
    {
        $precision = $columnDetails['precision'] ?? 10;
        $scale = $columnDetails['scale'] ?? 2;
        
        $max = pow(10, $precision - $scale) - 1;
        
        return $this->faker->randomFloat($scale, 0, $max);
    }

    /**
     * Generate string value
     */
    protected function generateString(array $columnDetails): string
    {
        $maxLength = $columnDetails['max_length'] ?? $columnDetails['length'] ?? 255;
        
        // Generate appropriate length string
        if ($maxLength <= 10) {
            return $this->faker->lexify(str_repeat('?', $maxLength));
        } elseif ($maxLength <= 50) {
            return substr($this->faker->words(3, true), 0, $maxLength);
        } else {
            return substr($this->faker->sentence(), 0, $maxLength);
        }
    }

    /**
     * Generate JSON data
     */
    protected function generateJsonData(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'name' => $this->faker->name(),
            'email' => $this->faker->email(),
            'metadata' => [
                'created' => $this->faker->dateTime()->format('Y-m-d H:i:s'),
                'tags' => $this->faker->words(3),
                'active' => $this->faker->boolean(),
            ],
        ];
    }

    /**
     * Generate enum value
     */
    protected function generateEnum(array $columnDetails): string
    {
        $values = $columnDetails['enum_values'] ?? ['option1', 'option2', 'option3'];
        return $this->faker->randomElement($values);
    }

    /**
     * Check if memory limit is approaching
     */
    protected function isMemoryLimitApproaching(): bool
    {
        $memoryLimit = $this->parseMemoryLimit($this->mint->getConfig('generation.memory_limit', '512M'));
        $currentUsage = memory_get_usage(true);
        
        // Consider approaching if we're at 80% of limit
        return $currentUsage > ($memoryLimit * 0.8);
    }

    /**
     * Handle memory limit approaching
     */
    protected function handleMemoryLimit(): void
    {
        // Clear caches
        $this->cache = [];
        
        // Force garbage collection
        gc_collect_cycles();
        
        // Log warning if monitoring is enabled
        if ($this->mint->getConfig('monitoring.enabled')) {
            $this->logMemoryWarning();
        }
    }

    /**
     * Parse memory limit string to bytes
     */
    protected function parseMemoryLimit(string $limit): int
    {
        $unit = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);
        
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $limit;
        }
    }

    /**
     * Disable foreign key checks
     */
    protected function disableForeignKeyChecks($connection): void
    {
        $driver = $connection->getDriverName();
        
        switch ($driver) {
            case 'mysql':
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');
                break;
            case 'pgsql':
                $connection->statement('SET CONSTRAINTS ALL DEFERRED');
                break;
            case 'sqlite':
                $connection->statement('PRAGMA foreign_keys = OFF');
                break;
        }
    }

    /**
     * Enable foreign key checks
     */
    protected function enableForeignKeyChecks($connection): void
    {
        $driver = $connection->getDriverName();
        
        switch ($driver) {
            case 'mysql':
                $connection->statement('SET FOREIGN_KEY_CHECKS=1');
                break;
            case 'pgsql':
                $connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
                break;
            case 'sqlite':
                $connection->statement('PRAGMA foreign_keys = ON');
                break;
        }
    }

    /**
     * Log memory warning
     */
    protected function logMemoryWarning(): void
    {
        $currentUsage = memory_get_usage(true);
        $peakUsage = memory_get_peak_usage(true);
        
        \Log::channel($this->mint->getConfig('monitoring.log_channel', 'daily'))
            ->warning('Laravel Mint: Memory limit approaching', [
                'current_usage' => $this->formatBytes($currentUsage),
                'peak_usage' => $this->formatBytes($peakUsage),
                'generated_count' => $this->generatedCount,
            ]);
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get generation statistics
     */
    public function getStatistics(): array
    {
        return [
            'generated_count' => $this->generatedCount,
            'memory_usage' => $this->formatBytes(memory_get_usage(true)),
            'peak_memory' => $this->formatBytes(memory_get_peak_usage(true)),
            'statistics' => $this->statistics,
        ];
    }
}