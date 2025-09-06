<?php

namespace LaravelMint;

use Illuminate\Contracts\Foundation\Application;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Analyzers\SchemaInspector;
use LaravelMint\Analyzers\RelationshipMapper;
use LaravelMint\Generators\DataGenerator;
use LaravelMint\Generators\SimpleGenerator;
use LaravelMint\Generators\PatternAwareGenerator;
use LaravelMint\Scenarios\ScenarioManager;
use LaravelMint\Patterns\PatternRegistry;

class Mint
{
    protected Application $app;
    protected ModelAnalyzer $modelAnalyzer;
    protected SchemaInspector $schemaInspector;
    protected RelationshipMapper $relationshipMapper;
    protected ?DataGenerator $generator = null;
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
        
        return [
            'model' => $modelAnalysis,
            'schema' => $schemaAnalysis,
            'relationships' => $relationships,
        ];
    }

    public function generate(string $modelClass, int $count = 1, array $options = []): void
    {
        $analysis = $this->analyze($modelClass);
        
        // Use PatternAwareGenerator if patterns are specified
        if ($this->hasPatterns($options)) {
            $this->generator = new PatternAwareGenerator($this, $analysis, $options);
        } else {
            $this->generator = new SimpleGenerator($this, $analysis);
        }
        
        $this->generator->generate($modelClass, $count, $options);
    }

    public function generateWithScenario(string $scenario, array $options = []): void
    {
        $scenarioManager = new ScenarioManager($this);
        $scenarioManager->run($scenario, $options);
    }

    public function clear(string $modelClass = null): int
    {
        if ($modelClass) {
            return $modelClass::query()->delete();
        }
        
        // Clear all generated data (would need tracking mechanism)
        return 0;
    }

    public function getConfig(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->config;
        }
        
        return data_get($this->config, $key, $default);
    }

    public function getConnection()
    {
        $connectionName = $this->getConfig('database.connection');
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
        return isset($options['patterns']) || 
               isset($options['column_patterns']) || 
               isset($options['model_patterns']) ||
               isset($options['use_patterns']);
    }

    /**
     * Get pattern registry
     */
    public function getPatternRegistry(): PatternRegistry
    {
        return new PatternRegistry();
    }
}