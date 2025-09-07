<?php

namespace LaravelMint;

use Illuminate\Contracts\Foundation\Application;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Analyzers\RelationshipMapper;
use LaravelMint\Analyzers\SchemaInspector;
use LaravelMint\Generators\DataGenerator;
use LaravelMint\Generators\PatternAwareGenerator;
use LaravelMint\Generators\SimpleGenerator;
use LaravelMint\Patterns\PatternRegistry;
use LaravelMint\Scenarios\ScenarioManager;

class Mint
{
    protected Application $app;

    protected ModelAnalyzer $modelAnalyzer;

    protected SchemaInspector $schemaInspector;

    protected RelationshipMapper $relationshipMapper;

    protected ?DataGenerator $generator = null;
    
    protected ?ScenarioManager $scenarioManager = null;

    protected array $config;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->config = $app['config']['mint'] ?? [];

        $this->modelAnalyzer = new ModelAnalyzer($this);
        $this->schemaInspector = new SchemaInspector($this);
        $this->relationshipMapper = new RelationshipMapper($this);
    }

    public function analyze(string $modelClass): array
    {
        $modelAnalysis = $this->modelAnalyzer->analyze($modelClass);
        $schemaAnalysis = $this->schemaInspector->inspect($modelClass);
        $relationships = $this->relationshipMapper->map($modelClass);

        // Get the table name
        $table = '';
        try {
            $instance = new $modelClass;
            $table = $instance->getTable();
        } catch (\Exception $e) {
            // Default to pluralized lowercase model name
            $table = strtolower(class_basename($modelClass)).'s';
        }

        return [
            'model' => $modelClass,
            'model_analysis' => $modelAnalysis,
            'schema' => $schemaAnalysis,
            'relationships' => $relationships,
            'table' => $table,
            'attributes' => $schemaAnalysis['columns'] ?? [],
        ];
    }

    public function generate(string $modelClass, int $count = 1, array $options = []): \Illuminate\Support\Collection
    {
        $analysis = $this->analyze($modelClass);

        // Use PatternAwareGenerator if patterns are specified
        if ($this->hasPatterns($options)) {
            $this->generator = new PatternAwareGenerator($this, $analysis, $options);
        } else {
            $this->generator = new SimpleGenerator($this, $analysis);
        }

        return $this->generator->generate($modelClass, $count, $options);
    }

    public function generateWithScenario(string $scenario, array $options = []): void
    {
        // Check if ScenarioManager is registered in the container (for testing)
        if ($this->app && $this->app->has(ScenarioManager::class)) {
            $scenarioManager = $this->app->make(ScenarioManager::class);
        } else {
            $scenarioManager = new ScenarioManager($this);
        }
        $scenarioManager->run($scenario, $options);
    }

    public function generateBatch(array $batch): array
    {
        $results = [];

        foreach ($batch as $modelClass => $count) {
            $results[$modelClass] = $this->generate($modelClass, $count);
        }

        return $results;
    }

    public function clear(?string $modelClass = null, array $conditions = []): int
    {
        if ($modelClass) {
            $query = $modelClass::query();
            
            if (!empty($conditions)) {
                foreach ($conditions as $key => $value) {
                    $query->where($key, $value);
                }
            }
            
            return $query->delete();
        }

        // Clear all generated data (would need tracking mechanism)
        return 0;
    }

    public function getConfig(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->config;
        }

        return data_get($this->config, $key, $default);
    }

    public function getConnection()
    {
        $connectionName = $this->getConfig('database.connection');
        
        // If no connection specified in config, use the default
        if (!$connectionName) {
            return $this->app['db']->connection();
        }

        return $this->app['db']->connection($connectionName);
    }

    public function getModelAnalyzer(): ModelAnalyzer
    {
        return $this->modelAnalyzer;
    }

    public function getSchemaInspector(): SchemaInspector
    {
        return $this->schemaInspector;
    }

    public function getRelationshipMapper(): RelationshipMapper
    {
        return $this->relationshipMapper;
    }

    /**
     * Check if options contain patterns
     */
    protected function hasPatterns(array $options): bool
    {
        return isset($options['pattern']) ||
               isset($options['patterns']) ||
               isset($options['column_patterns']) ||
               isset($options['model_patterns']) ||
               isset($options['use_patterns']);
    }

    /**
     * Get pattern registry
     */
    public function getPatternRegistry(): PatternRegistry
    {
        // Return shared instance from container if available
        if ($this->app->bound(PatternRegistry::class)) {
            return $this->app->make(PatternRegistry::class);
        }

        $registry = new PatternRegistry;
        $registry->initializeBuiltInPatterns();
        return $registry;
    }

    public function generateWithPattern(string $modelClass, int $count, string $pattern, array $config = []): \Illuminate\Support\Collection
    {
        $options = array_merge($config, ['pattern' => $pattern]);

        return $this->generate($modelClass, $count, $options);
    }

    public function seed(string $seederClass): void
    {
        $seeder = new $seederClass;
        $seeder->run();
    }

    public function getStatistics(string $modelClass): array
    {
        $count = $modelClass::count();
        $today = $modelClass::whereDate('created_at', today())->count();

        return [
            'total_records' => $count,
            'created_today' => $today,
            'field_statistics' => [],
        ];
    }

    public function export(string $modelClass, string $path, string $format = 'json', array $conditions = []): void
    {
        $query = $modelClass::query();
        
        if (!empty($conditions)) {
            foreach ($conditions as $key => $value) {
                $query->where($key, $value);
            }
        }
        
        $data = $query->get()->toArray();

        if ($format === 'json') {
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
        } elseif ($format === 'csv') {
            $fp = fopen($path, 'w');
            if (! empty($data)) {
                fputcsv($fp, array_keys($data[0]));
                foreach ($data as $row) {
                    fputcsv($fp, $row);
                }
            }
            fclose($fp);
        }
    }

    public function import(string $modelClass, string $path, string $format = 'json', array $options = []): array
    {
        if ($format === 'json') {
            $data = json_decode(file_get_contents($path), true);
            foreach ($data as $row) {
                $modelClass::create($row);
            }

            return ['imported' => count($data)];
        }

        return ['imported' => 0];
    }

    public function runScenario(string $scenario, array $options = []): array
    {
        $scenarioManager = new ScenarioManager($this);
        $result = $scenarioManager->run($scenario, $options);

        return $result->toArray();
    }

    /**
     * Get the scenario manager instance
     */
    public function getScenarioManager(): ScenarioManager
    {
        if (!isset($this->scenarioManager)) {
            $this->scenarioManager = new ScenarioManager($this);
        }
        return $this->scenarioManager;
    }
}
