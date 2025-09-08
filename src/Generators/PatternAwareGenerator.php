<?php

namespace LaravelMint\Generators;

use LaravelMint\Mint;
use LaravelMint\Patterns\PatternInterface;
use LaravelMint\Patterns\PatternRegistry;

class PatternAwareGenerator extends SimpleGenerator
{
    protected PatternRegistry $patternRegistry;

    protected array $columnPatterns = [];

    protected array $modelPatterns = [];

    protected array $globalPatterns = [];

    public function __construct(Mint $mint, array $analysis, array $options = [])
    {
        parent::__construct($mint, $analysis, $options);
        $this->patternRegistry = $mint->getPatternRegistry();
        $this->loadPatterns();
    }

    /**
     * Load patterns from configuration
     */
    protected function loadPatterns(): void
    {
        // Check if single pattern is specified
        if (isset($this->options['pattern'])) {
            $patternName = $this->options['pattern'];

            // Check for pattern_config which comes from the command
            $patternConfig = $this->options['pattern_config'] ?? [];

            // Also check for direct options (for backward compatibility)
            $field = $this->options['field'] ?? $patternConfig['field'] ?? null;

            // Extract pattern configuration (mean, stddev, etc.)
            foreach ($this->options as $key => $value) {
                if (in_array($key, ['mean', 'stddev', 'min', 'max', 'lambda', 'scale'])) {
                    $patternConfig[$key] = $value;
                }
            }

            if ($field) {
                // Apply pattern to specific field
                // Remove 'field' from config before passing to pattern
                $cleanConfig = array_diff_key($patternConfig, ['field' => true]);
                $this->columnPatterns[$field] = $this->patternRegistry->create($patternName, $cleanConfig);
            } else {
                // Use as global pattern
                $this->globalPatterns['default'] = $this->patternRegistry->create($patternName, $patternConfig);
            }
        }

        // Load global patterns
        $globalPatterns = $this->options['patterns'] ?? [];
        foreach ($globalPatterns as $name => $config) {
            if (is_string($config)) {
                $this->globalPatterns[$name] = $this->patternRegistry->create($config);
            } elseif (is_array($config)) {
                $this->globalPatterns[$name] = $this->patternRegistry->load($config);
            }
        }

        // Load model-specific patterns
        $modelPatterns = $this->options['model_patterns'] ?? [];
        foreach ($modelPatterns as $model => $patterns) {
            $this->modelPatterns[$model] = [];
            foreach ($patterns as $name => $config) {
                if (is_string($config)) {
                    $this->modelPatterns[$model][$name] = $this->patternRegistry->create($config);
                } elseif (is_array($config)) {
                    $this->modelPatterns[$model][$name] = $this->patternRegistry->load($config);
                }
            }
        }

        // Load column-specific patterns
        $columnPatterns = $this->options['column_patterns'] ?? [];
        foreach ($columnPatterns as $column => $config) {
            if (is_string($config)) {
                $this->columnPatterns[$column] = $this->patternRegistry->create($config);
            } elseif (is_array($config)) {
                $this->columnPatterns[$column] = $this->patternRegistry->load($config);
            }
        }
    }

    /**
     * Generate a single record with pattern support
     */
    protected function generateRecord(string $modelClass, array $overrides = []): array
    {
        $record = parent::generateRecord($modelClass, $overrides);

        // Apply model patterns
        $modelName = class_basename($modelClass);
        if (isset($this->modelPatterns[$modelName])) {
            foreach ($this->modelPatterns[$modelName] as $column => $pattern) {
                if (! array_key_exists($column, $overrides)) {
                    $record[$column] = $this->generateWithPattern($pattern, [
                        'model' => $modelClass,
                        'column' => $column,
                        'record' => $record,
                    ]);
                }
            }
        }

        // Apply column patterns
        foreach ($this->columnPatterns as $column => $pattern) {
            if (isset($record[$column]) && ! array_key_exists($column, $overrides)) {
                $record[$column] = $this->generateWithPattern($pattern, [
                    'model' => $modelClass,
                    'column' => $column,
                    'record' => $record,
                ]);
            }
        }

        return $record;
    }

    /**
     * Generate column value with pattern support
     */
    protected function generateColumnValue(string $column, array $columnDetails): mixed
    {
        // Check for column-specific pattern
        if (isset($this->columnPatterns[$column])) {
            return $this->generateWithPattern($this->columnPatterns[$column], [
                'column' => $column,
                'details' => $columnDetails,
            ]);
        }

        // Check for pattern hint in column details
        if (isset($columnDetails['pattern'])) {
            $pattern = $this->patternRegistry->create($columnDetails['pattern'], $columnDetails['pattern_config'] ?? []);

            return $this->generateWithPattern($pattern, [
                'column' => $column,
                'details' => $columnDetails,
            ]);
        }

        // Check for inferred patterns based on column name
        $inferredPattern = $this->inferPatternForColumn($column, $columnDetails);
        if ($inferredPattern) {
            return $this->generateWithPattern($inferredPattern, [
                'column' => $column,
                'details' => $columnDetails,
            ]);
        }

        // Fall back to parent implementation
        return parent::generateColumnValue($column, $columnDetails);
    }

    /**
     * Generate value using pattern
     */
    protected function generateWithPattern(PatternInterface $pattern, array $context = []): mixed
    {
        // Add timestamp context if dealing with temporal patterns
        if (! isset($context['timestamp'])) {
            $context['timestamp'] = new \DateTime;
        }

        // Add record context
        $context['generated_count'] = $this->generatedCount;

        return $pattern->generate($context);
    }

    /**
     * Infer pattern based on column characteristics
     */
    protected function inferPatternForColumn(string $column, array $columnDetails): ?PatternInterface
    {
        $columnLower = strtolower($column);
        $type = $columnDetails['type'] ?? '';

        // Price/amount columns often follow normal distribution
        if (str_contains($columnLower, 'price') || str_contains($columnLower, 'amount') || str_contains($columnLower, 'cost')) {
            return $this->patternRegistry->create('normal', [
                'mean' => 100,
                'stddev' => 30,
                'min' => 0.01,
                'max' => 10000,
            ]);
        }

        // Age typically follows a normal distribution
        if (str_contains($columnLower, 'age')) {
            return $this->patternRegistry->create('normal', [
                'mean' => 35,
                'stddev' => 15,
                'min' => 18,
                'max' => 90,
            ]);
        }

        // View/visit counts often follow Pareto distribution
        if (str_contains($columnLower, 'view') || str_contains($columnLower, 'visit') || str_contains($columnLower, 'click')) {
            return $this->patternRegistry->create('pareto', [
                'alpha' => 1.16,
                'xmin' => 1,
                'max' => 100000,
            ]);
        }

        // Count fields might follow Poisson distribution
        if (str_contains($columnLower, 'count') && str_contains($type, 'int')) {
            return $this->patternRegistry->create('poisson', [
                'lambda' => 5,
                'max' => 50,
            ]);
        }

        // Time between events (intervals) follow exponential
        if (str_contains($columnLower, 'interval') || str_contains($columnLower, 'duration')) {
            return $this->patternRegistry->create('exponential', [
                'lambda' => 0.1,
                'min' => 0,
                'max' => 3600,
            ]);
        }

        return null;
    }

    /**
     * Set pattern for a column
     */
    public function setColumnPattern(string $column, $pattern): void
    {
        if (is_string($pattern)) {
            $this->columnPatterns[$column] = $this->patternRegistry->create($pattern);
        } elseif (is_array($pattern)) {
            $this->columnPatterns[$column] = $this->patternRegistry->load($pattern);
        } elseif ($pattern instanceof PatternInterface) {
            $this->columnPatterns[$column] = $pattern;
        } else {
            throw new \InvalidArgumentException('Invalid pattern type');
        }
    }

    /**
     * Set pattern for a model
     */
    public function setModelPattern(string $model, string $column, $pattern): void
    {
        if (! isset($this->modelPatterns[$model])) {
            $this->modelPatterns[$model] = [];
        }

        if (is_string($pattern)) {
            $this->modelPatterns[$model][$column] = $this->patternRegistry->create($pattern);
        } elseif (is_array($pattern)) {
            $this->modelPatterns[$model][$column] = $this->patternRegistry->load($pattern);
        } elseif ($pattern instanceof PatternInterface) {
            $this->modelPatterns[$model][$column] = $pattern;
        } else {
            throw new \InvalidArgumentException('Invalid pattern type');
        }
    }

    /**
     * Get pattern registry
     */
    public function getPatternRegistry(): PatternRegistry
    {
        return $this->patternRegistry;
    }

    /**
     * Get all active patterns
     */
    public function getActivePatterns(): array
    {
        return [
            'global' => $this->globalPatterns,
            'models' => $this->modelPatterns,
            'columns' => $this->columnPatterns,
        ];
    }
}
