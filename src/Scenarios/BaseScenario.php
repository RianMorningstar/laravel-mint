<?php

namespace LaravelMint\Scenarios;

use Illuminate\Support\Facades\DB;
use LaravelMint\Mint;
use LaravelMint\Patterns\PatternRegistry;

abstract class BaseScenario implements ScenarioInterface
{
    protected Mint $mint;

    protected PatternRegistry $patternRegistry;

    protected array $options = [];

    protected array $config = [];

    protected array $validationErrors = [];

    protected array $generatedData = [];

    protected ScenarioResult $result;

    protected string $name;

    protected string $description;

    protected array $requiredModels = [];

    protected array $optionalModels = [];

    protected array $parameters = [];

    public function __construct(?Mint $mint = null)
    {
        $this->mint = $mint ?? app('mint');
        $this->patternRegistry = new PatternRegistry;
        $this->config = $this->getDefaultConfig();
        $this->initialize();
    }

    /**
     * Initialize scenario-specific properties
     */
    abstract protected function initialize(): void;

    /**
     * Execute the scenario logic
     */
    abstract protected function execute(): void;

    /**
     * Get scenario name
     */
    public function getName(): string
    {
        return $this->name ?? class_basename($this);
    }

    /**
     * Get scenario description
     */
    public function getDescription(): string
    {
        return $this->description ?? 'No description available';
    }

    /**
     * Get required models
     */
    public function getRequiredModels(): array
    {
        return $this->requiredModels;
    }

    /**
     * Get optional models
     */
    public function getOptionalModels(): array
    {
        return $this->optionalModels;
    }

    /**
     * Get parameters
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Set options
     */
    public function setOptions(array $options): void
    {
        $this->options = array_merge($this->options, $options);
        $this->config = array_merge($this->config, $options);
    }

    /**
     * Validate scenario can run
     */
    public function validate(): bool
    {
        $this->validationErrors = [];

        // Check required models exist
        foreach ($this->requiredModels as $model) {
            if (! class_exists($model)) {
                $this->validationErrors[] = "Required model {$model} does not exist";
            }
        }

        // Validate parameters
        foreach ($this->parameters as $param => $rules) {
            if (isset($rules['required']) && $rules['required'] && ! isset($this->config[$param])) {
                $this->validationErrors[] = "Required parameter '{$param}' is missing";
            }

            if (isset($this->config[$param]) && isset($rules['type'])) {
                if (! $this->validateType($this->config[$param], $rules['type'])) {
                    $this->validationErrors[] = "Parameter '{$param}' must be of type {$rules['type']}";
                }
            }
        }

        return empty($this->validationErrors);
    }

    /**
     * Validate parameter type
     */
    protected function validateType($value, string $type): bool
    {
        return match ($type) {
            'int', 'integer' => is_int($value),
            'float', 'double' => is_numeric($value),
            'string' => is_string($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            default => true
        };
    }

    /**
     * Get validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Run the scenario
     */
    public function run(array $options = []): ScenarioResult
    {
        $this->setOptions($options);

        if (! $this->validate()) {
            $result = new ScenarioResult($this->getName());
            foreach ($this->validationErrors as $error) {
                $result->addError($error);
            }

            return $result;
        }

        $this->result = new ScenarioResult($this->getName());
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            DB::beginTransaction();

            $this->beforeExecute();
            $this->execute();
            $this->afterExecute();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->result->addError($e->getMessage());
        }

        $this->result->setExecutionTime(microtime(true) - $startTime);
        $this->result->setMemoryUsage(memory_get_peak_usage(true) - $startMemory);

        return $this->result;
    }

    /**
     * Hook before execution
     */
    protected function beforeExecute(): void
    {
        // Override in child classes if needed
    }

    /**
     * Hook after execution
     */
    protected function afterExecute(): void
    {
        // Override in child classes if needed
    }

    /**
     * Generate model data
     */
    protected function generateModel(string $modelClass, int $count, array $options = []): void
    {
        $options = array_merge($this->getGenerationOptions(), $options);

        try {
            $this->mint->generate($modelClass, $count, $options);
            $this->result->addGenerated($modelClass, $count);
            $this->generatedData[$modelClass] = $modelClass::latest()->take($count)->get();
        } catch (\Exception $e) {
            $this->result->addError("Failed to generate {$modelClass}: ".$e->getMessage());
        }
    }

    /**
     * Get generation options with patterns
     */
    protected function getGenerationOptions(): array
    {
        $options = [
            'use_patterns' => true,
            'silent' => true,
        ];

        if (isset($this->config['seed'])) {
            $options['seed'] = $this->config['seed'];
        }

        return $options;
    }

    /**
     * Generate with specific pattern
     */
    protected function generateWithPattern(string $modelClass, int $count, string $column, array $patternConfig): void
    {
        $options = $this->getGenerationOptions();
        $options['column_patterns'] = [
            $column => $patternConfig,
        ];

        $this->generateModel($modelClass, $count, $options);
    }

    /**
     * Get default configuration
     */
    public function getDefaultConfig(): array
    {
        return [];
    }

    /**
     * Estimate time and resources
     */
    public function estimate(): array
    {
        $totalRecords = 0;
        $estimatedTime = 0;
        $estimatedMemory = 0;

        foreach ($this->requiredModels as $model) {
            $count = $this->config[class_basename($model).'_count'] ?? 100;
            $totalRecords += $count;

            // Rough estimates
            $estimatedTime += $count * 0.001; // 1ms per record
            $estimatedMemory += $count * 1024; // 1KB per record
        }

        return [
            'total_records' => $totalRecords,
            'estimated_time' => round($estimatedTime, 2).'s',
            'estimated_memory' => $this->formatBytes($estimatedMemory),
        ];
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

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Get configuration value
     */
    protected function getConfig(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Log progress
     */
    protected function logProgress(string $message): void
    {
        if (! ($this->config['silent'] ?? false)) {
            echo $message."\n";
        }
    }
}
