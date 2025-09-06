<?php

namespace LaravelMint\Scenarios;

class ScenarioValidator
{
    protected array $errors = [];
    protected array $warnings = [];
    
    /**
     * Validate a scenario
     */
    public function validate(ScenarioInterface $scenario, array $options = []): ValidationResult
    {
        $this->errors = [];
        $this->warnings = [];

        // Check scenario basics
        $this->validateBasics($scenario);
        
        // Check required models
        $this->validateRequiredModels($scenario);
        
        // Check parameters
        $this->validateParameters($scenario, $options);
        
        // Check dependencies
        $this->validateDependencies($scenario);
        
        // Check database state
        $this->validateDatabaseState($scenario);
        
        // Run scenario's own validation
        if (!$scenario->validate()) {
            foreach ($scenario->getValidationErrors() as $error) {
                $this->errors[] = $error;
            }
        }

        return new ValidationResult($this->errors, $this->warnings);
    }

    /**
     * Validate scenario basics
     */
    protected function validateBasics(ScenarioInterface $scenario): void
    {
        if (empty($scenario->getName())) {
            $this->errors[] = 'Scenario must have a name';
        }

        if (empty($scenario->getDescription())) {
            $this->warnings[] = 'Scenario has no description';
        }
    }

    /**
     * Validate required models exist
     */
    protected function validateRequiredModels(ScenarioInterface $scenario): void
    {
        foreach ($scenario->getRequiredModels() as $model) {
            if (!class_exists($model)) {
                $this->errors[] = "Required model does not exist: {$model}";
                continue;
            }

            // Check if model extends Eloquent
            if (!is_subclass_of($model, 'Illuminate\Database\Eloquent\Model')) {
                $this->errors[] = "Model is not an Eloquent model: {$model}";
            }

            // Check if table exists
            try {
                $instance = new $model();
                $table = $instance->getTable();
                
                if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    $this->errors[] = "Table does not exist for model {$model}: {$table}";
                }
            } catch (\Exception $e) {
                $this->errors[] = "Cannot instantiate model {$model}: " . $e->getMessage();
            }
        }
    }

    /**
     * Validate parameters
     */
    protected function validateParameters(ScenarioInterface $scenario, array $options): void
    {
        $parameters = $scenario->getParameters();
        
        foreach ($parameters as $param => $rules) {
            // Check required parameters
            if (isset($rules['required']) && $rules['required']) {
                if (!isset($options[$param])) {
                    $this->errors[] = "Required parameter missing: {$param}";
                    continue;
                }
            }

            // Skip if parameter not provided
            if (!isset($options[$param])) {
                continue;
            }

            $value = $options[$param];

            // Validate type
            if (isset($rules['type'])) {
                if (!$this->validateType($value, $rules['type'])) {
                    $this->errors[] = "Parameter {$param} must be of type {$rules['type']}";
                }
            }

            // Validate min/max
            if (isset($rules['min']) && $value < $rules['min']) {
                $this->errors[] = "Parameter {$param} must be at least {$rules['min']}";
            }

            if (isset($rules['max']) && $value > $rules['max']) {
                $this->errors[] = "Parameter {$param} must be at most {$rules['max']}";
            }

            // Validate enum
            if (isset($rules['enum']) && !in_array($value, $rules['enum'])) {
                $this->errors[] = "Parameter {$param} must be one of: " . implode(', ', $rules['enum']);
            }

            // Custom validation
            if (isset($rules['validate']) && is_callable($rules['validate'])) {
                $result = $rules['validate']($value);
                if ($result !== true) {
                    $this->errors[] = "Parameter {$param} validation failed: {$result}";
                }
            }
        }
    }

    /**
     * Validate type
     */
    protected function validateType($value, string $type): bool
    {
        return match($type) {
            'int', 'integer' => is_int($value),
            'float', 'double' => is_numeric($value),
            'string' => is_string($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'datetime' => $value instanceof \DateTimeInterface,
            default => true
        };
    }

    /**
     * Validate dependencies
     */
    protected function validateDependencies(ScenarioInterface $scenario): void
    {
        // Check for circular dependencies in relationships
        $models = $scenario->getRequiredModels();
        
        foreach ($models as $model) {
            if (!class_exists($model)) {
                continue;
            }

            try {
                $instance = new $model();
                $reflection = new \ReflectionClass($instance);
                
                // Check for required relationships
                foreach ($reflection->getMethods() as $method) {
                    if ($this->isRelationMethod($method)) {
                        $relationName = $method->getName();
                        
                        // Try to get related model
                        try {
                            $relation = $instance->$relationName();
                            $relatedModel = get_class($relation->getRelated());
                            
                            // Check if related model is in required models
                            if (!in_array($relatedModel, $models) && 
                                !in_array($relatedModel, $scenario->getOptionalModels())) {
                                $this->warnings[] = "Model {$model} has relation to {$relatedModel} which is not included in scenario";
                            }
                        } catch (\Exception $e) {
                            // Ignore relation inspection errors
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->warnings[] = "Cannot analyze model {$model}: " . $e->getMessage();
            }
        }
    }

    /**
     * Check if method is a relation
     */
    protected function isRelationMethod(\ReflectionMethod $method): bool
    {
        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
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
            'Illuminate\Database\Eloquent\Relations\HasOneThrough',
            'Illuminate\Database\Eloquent\Relations\HasManyThrough',
            'Illuminate\Database\Eloquent\Relations\MorphOne',
            'Illuminate\Database\Eloquent\Relations\MorphMany',
            'Illuminate\Database\Eloquent\Relations\MorphTo',
            'Illuminate\Database\Eloquent\Relations\MorphToMany',
        ];

        foreach ($relationTypes as $relationType) {
            if (is_a($typeName, $relationType, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate database state
     */
    protected function validateDatabaseState(ScenarioInterface $scenario): void
    {
        // Check if database is accessible
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
        } catch (\Exception $e) {
            $this->errors[] = 'Database connection failed: ' . $e->getMessage();
            return;
        }

        // Check for existing data that might conflict
        foreach ($scenario->getRequiredModels() as $model) {
            if (!class_exists($model)) {
                continue;
            }

            try {
                $count = $model::count();
                if ($count > 0) {
                    $this->warnings[] = "Model {$model} already has {$count} records";
                }
            } catch (\Exception $e) {
                $this->warnings[] = "Cannot count records for {$model}: " . $e->getMessage();
            }
        }

        // Check available disk space
        $freeSpace = disk_free_space(storage_path());
        $requiredSpace = 100 * 1024 * 1024; // 100MB minimum
        
        if ($freeSpace < $requiredSpace) {
            $this->warnings[] = 'Low disk space available: ' . $this->formatBytes($freeSpace);
        }

        // Check memory limit
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit !== '-1') {
            $bytes = $this->parseBytes($memoryLimit);
            if ($bytes < 256 * 1024 * 1024) { // Less than 256MB
                $this->warnings[] = "Low memory limit: {$memoryLimit}";
            }
        }
    }

    /**
     * Parse bytes from string
     */
    protected function parseBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int)$value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Format bytes
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
}

/**
 * Validation result
 */
class ValidationResult
{
    protected array $errors;
    protected array $warnings;

    public function __construct(array $errors = [], array $warnings = [])
    {
        $this->errors = $errors;
        $this->warnings = $warnings;
    }

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}