<?php

namespace LaravelMint\Performance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class QueryOptimizer
{
    protected array $eagerLoads = [];
    protected array $indexes = [];
    protected array $queryLog = [];
    protected bool $analyzeMode = false;
    protected array $suggestions = [];
    
    /**
     * Optimize a query builder
     */
    public function optimize(Builder $query): Builder
    {
        // Analyze relationships for eager loading
        $this->analyzeRelationships($query);
        
        // Apply eager loading
        if (!empty($this->eagerLoads)) {
            $query->with($this->eagerLoads);
        }
        
        // Optimize select statements
        $this->optimizeSelect($query);
        
        // Add index hints if available
        $this->addIndexHints($query);
        
        return $query;
    }
    
    /**
     * Analyze relationships to prevent N+1 queries
     */
    protected function analyzeRelationships(Builder $query): void
    {
        $model = $query->getModel();
        $relations = $this->getModelRelations($model);
        
        // Check which relations are likely to be accessed
        foreach ($relations as $relation) {
            if ($this->shouldEagerLoad($relation, $model)) {
                $this->eagerLoads[] = $relation;
            }
        }
    }
    
    /**
     * Get model relations
     */
    protected function getModelRelations(Model $model): array
    {
        $relations = [];
        $reflection = new \ReflectionClass($model);
        
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== get_class($model)) {
                continue;
            }
            
            if ($this->isRelationMethod($method)) {
                $relations[] = $method->getName();
            }
        }
        
        return $relations;
    }
    
    /**
     * Check if method is a relation
     */
    protected function isRelationMethod(\ReflectionMethod $method): bool
    {
        if ($method->getNumberOfRequiredParameters() > 0) {
            return false;
        }
        
        $returnType = $method->getReturnType();
        
        if (!$returnType) {
            return false;
        }
        
        $typeName = $returnType->getName();
        
        $relationTypes = [
            'Illuminate\Database\Eloquent\Relations\HasOne',
            'Illuminate\Database\Eloquent\Relations\HasMany',
            'Illuminate\Database\Eloquent\Relations\BelongsTo',
            'Illuminate\Database\Eloquent\Relations\BelongsToMany',
        ];
        
        foreach ($relationTypes as $relationType) {
            if (is_a($typeName, $relationType, true)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Determine if relation should be eager loaded
     */
    protected function shouldEagerLoad(string $relation, Model $model): bool
    {
        // Check if relation is commonly accessed based on patterns
        $commonRelations = ['user', 'posts', 'comments', 'tags', 'categories'];
        
        if (in_array($relation, $commonRelations)) {
            return true;
        }
        
        // Check query history for this relation
        if ($this->analyzeMode) {
            return $this->checkQueryHistory($model, $relation);
        }
        
        return false;
    }
    
    /**
     * Optimize select statements
     */
    protected function optimizeSelect(Builder $query): void
    {
        $model = $query->getModel();
        $table = $model->getTable();
        
        // If no specific columns selected, optimize based on table size
        if (empty($query->getQuery()->columns)) {
            $columnCount = $this->getTableColumnCount($table);
            
            // For tables with many columns, select only essential fields
            if ($columnCount > 20) {
                $essentialColumns = $this->getEssentialColumns($model);
                if (!empty($essentialColumns)) {
                    $query->select($essentialColumns);
                }
            }
        }
    }
    
    /**
     * Add index hints
     */
    protected function addIndexHints(Builder $query): void
    {
        $table = $query->getModel()->getTable();
        $wheres = $query->getQuery()->wheres ?? [];
        
        foreach ($wheres as $where) {
            if (isset($where['column'])) {
                $index = $this->findIndexForColumn($table, $where['column']);
                
                if ($index) {
                    // Note: Laravel doesn't directly support index hints,
                    // but we can add them via raw expressions if needed
                    $this->suggestions[] = "Consider using index '{$index}' for column '{$where['column']}'";
                }
            }
        }
    }
    
    /**
     * Batch process queries for efficiency
     */
    public function batch(array $queries, int $batchSize = 100): array
    {
        $results = [];
        $batches = array_chunk($queries, $batchSize);
        
        foreach ($batches as $batch) {
            DB::beginTransaction();
            
            try {
                foreach ($batch as $key => $query) {
                    $results[$key] = $query();
                }
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        }
        
        return $results;
    }
    
    /**
     * Cache query results
     */
    public function cached(Builder $query, string $key = null, int $ttl = 3600)
    {
        $key = $key ?? $this->generateCacheKey($query);
        
        return Cache::remember($key, $ttl, function () use ($query) {
            return $query->get();
        });
    }
    
    /**
     * Generate cache key for query
     */
    protected function generateCacheKey(Builder $query): string
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        
        return 'mint_query_' . md5($sql . serialize($bindings));
    }
    
    /**
     * Profile query execution
     */
    public function profile(callable $callback): QueryProfile
    {
        $profile = new QueryProfile();
        
        // Enable query log
        DB::enableQueryLog();
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            $result = $callback();
            $profile->setResult($result);
        } catch (\Exception $e) {
            $profile->setError($e->getMessage());
        }
        
        $profile->setExecutionTime(microtime(true) - $startTime);
        $profile->setMemoryUsage(memory_get_usage(true) - $startMemory);
        
        // Get query log
        $queries = DB::getQueryLog();
        $profile->setQueries($queries);
        
        // Analyze queries
        $this->analyzeQueries($queries, $profile);
        
        DB::disableQueryLog();
        
        return $profile;
    }
    
    /**
     * Analyze queries for optimization opportunities
     */
    protected function analyzeQueries(array $queries, QueryProfile $profile): void
    {
        $totalTime = 0;
        $slowQueries = [];
        $duplicates = [];
        $seen = [];
        
        foreach ($queries as $query) {
            $sql = $query['query'];
            $time = $query['time'];
            $totalTime += $time;
            
            // Check for slow queries (> 100ms)
            if ($time > 100) {
                $slowQueries[] = [
                    'sql' => $sql,
                    'time' => $time,
                    'bindings' => $query['bindings'],
                ];
            }
            
            // Check for duplicate queries
            $key = md5($sql . serialize($query['bindings']));
            if (isset($seen[$key])) {
                $duplicates[] = $sql;
            }
            $seen[$key] = true;
            
            // Check for N+1 queries
            if ($this->isNPlusOnePattern($sql, $queries)) {
                $profile->addSuggestion('Detected N+1 query pattern. Consider eager loading.');
            }
        }
        
        $profile->setTotalQueryTime($totalTime);
        $profile->setSlowQueries($slowQueries);
        
        if (!empty($duplicates)) {
            $profile->addSuggestion('Found duplicate queries. Consider caching results.');
        }
        
        if (count($queries) > 50) {
            $profile->addSuggestion('High number of queries (' . count($queries) . '). Consider optimizing.');
        }
    }
    
    /**
     * Check for N+1 query pattern
     */
    protected function isNPlusOnePattern(string $sql, array $queries): bool
    {
        // Simple pattern matching for common N+1 scenarios
        if (strpos($sql, 'where') !== false && strpos($sql, 'in (') === false) {
            $pattern = preg_replace('/\d+/', '%d', $sql);
            $similar = 0;
            
            foreach ($queries as $query) {
                $queryPattern = preg_replace('/\d+/', '%d', $query['query']);
                if ($queryPattern === $pattern) {
                    $similar++;
                }
            }
            
            return $similar > 5; // More than 5 similar queries likely indicates N+1
        }
        
        return false;
    }
    
    /**
     * Get table column count
     */
    protected function getTableColumnCount(string $table): int
    {
        try {
            $columns = DB::getSchemaBuilder()->getColumnListing($table);
            return count($columns);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get essential columns for a model
     */
    protected function getEssentialColumns(Model $model): array
    {
        $essential = ['id'];
        
        // Add foreign keys
        $table = $model->getTable();
        $columns = DB::getSchemaBuilder()->getColumnListing($table);
        
        foreach ($columns as $column) {
            if (str_ends_with($column, '_id')) {
                $essential[] = $column;
            }
        }
        
        // Add important fields
        $important = ['name', 'title', 'email', 'status', 'created_at', 'updated_at'];
        
        foreach ($important as $field) {
            if (in_array($field, $columns)) {
                $essential[] = $field;
            }
        }
        
        return array_unique($essential);
    }
    
    /**
     * Find index for column
     */
    protected function findIndexForColumn(string $table, string $column): ?string
    {
        try {
            $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Column_name = ?", [$column]);
            
            if (!empty($indexes)) {
                return $indexes[0]->Key_name;
            }
        } catch (\Exception $e) {
            // Index lookup failed
        }
        
        return null;
    }
    
    /**
     * Check query history
     */
    protected function checkQueryHistory(Model $model, string $relation): bool
    {
        // This would check historical query patterns
        // For now, return false
        return false;
    }
    
    /**
     * Enable analyze mode
     */
    public function enableAnalyzeMode(): void
    {
        $this->analyzeMode = true;
        DB::enableQueryLog();
    }
    
    /**
     * Get optimization suggestions
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }
}

class QueryProfile
{
    protected $result;
    protected ?string $error = null;
    protected float $executionTime = 0;
    protected int $memoryUsage = 0;
    protected array $queries = [];
    protected float $totalQueryTime = 0;
    protected array $slowQueries = [];
    protected array $suggestions = [];
    
    public function setResult($result): void
    {
        $this->result = $result;
    }
    
    public function getResult()
    {
        return $this->result;
    }
    
    public function setError(string $error): void
    {
        $this->error = $error;
    }
    
    public function getError(): ?string
    {
        return $this->error;
    }
    
    public function setExecutionTime(float $time): void
    {
        $this->executionTime = $time;
    }
    
    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }
    
    public function setMemoryUsage(int $bytes): void
    {
        $this->memoryUsage = $bytes;
    }
    
    public function getMemoryUsage(): int
    {
        return $this->memoryUsage;
    }
    
    public function setQueries(array $queries): void
    {
        $this->queries = $queries;
    }
    
    public function getQueries(): array
    {
        return $this->queries;
    }
    
    public function setTotalQueryTime(float $time): void
    {
        $this->totalQueryTime = $time;
    }
    
    public function getTotalQueryTime(): float
    {
        return $this->totalQueryTime;
    }
    
    public function setSlowQueries(array $queries): void
    {
        $this->slowQueries = $queries;
    }
    
    public function getSlowQueries(): array
    {
        return $this->slowQueries;
    }
    
    public function addSuggestion(string $suggestion): void
    {
        $this->suggestions[] = $suggestion;
    }
    
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }
    
    public function getQueryCount(): int
    {
        return count($this->queries);
    }
    
    public function toArray(): array
    {
        return [
            'success' => $this->error === null,
            'execution_time' => round($this->executionTime, 4) . 's',
            'memory_usage' => $this->formatBytes($this->memoryUsage),
            'query_count' => $this->getQueryCount(),
            'total_query_time' => round($this->totalQueryTime, 2) . 'ms',
            'slow_queries' => count($this->slowQueries),
            'suggestions' => $this->suggestions,
            'error' => $this->error,
        ];
    }
    
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
}