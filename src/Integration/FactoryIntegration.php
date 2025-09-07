<?php

namespace LaravelMint\Integration;

use Illuminate\Database\Eloquent\Factories\Factory;
use LaravelMint\Mint;
use LaravelMint\Patterns\PatternRegistry;

class FactoryIntegration
{
    protected Mint $mint;

    protected PatternRegistry $patternRegistry;

    protected array $states = [];

    protected array $sequences = [];

    public function __construct(?Mint $mint = null)
    {
        $this->mint = $mint ?? app('mint');
        $this->patternRegistry = new PatternRegistry;
    }

    /**
     * Enhance existing factory with patterns
     */
    public function enhance(Factory $factory): EnhancedFactory
    {
        return new EnhancedFactory($factory, $this);
    }

    /**
     * Create pattern-based state
     */
    public function createState(string $name, array $patterns): callable
    {
        return function (array $attributes) use ($patterns) {
            foreach ($patterns as $field => $patternConfig) {
                if (is_string($patternConfig)) {
                    // Simple pattern name
                    $pattern = $this->patternRegistry->get($patternConfig);
                    $attributes[$field] = $pattern->generate();
                } elseif (is_array($patternConfig)) {
                    // Pattern with configuration
                    $patternName = $patternConfig['pattern'] ?? $patternConfig['type'] ?? null;
                    if ($patternName) {
                        $pattern = $this->patternRegistry->make($patternName, $patternConfig);
                        $attributes[$field] = $pattern->generate();
                    }
                } elseif (is_callable($patternConfig)) {
                    // Custom generator
                    $attributes[$field] = $patternConfig($attributes);
                }
            }

            return $attributes;
        };
    }

    /**
     * Create sequence with patterns
     */
    public function createSequence(array $values): callable
    {
        $index = 0;

        return function () use (&$index, $values) {
            $value = $values[$index % count($values)];
            $index++;

            if (is_array($value) && isset($value['pattern'])) {
                $pattern = $this->patternRegistry->make($value['pattern'], $value);

                return $pattern->generate();
            }

            return $value;
        };
    }

    /**
     * Generate factory with relationships
     */
    public function withRelationships(Factory $factory, array $relationships): Factory
    {
        foreach ($relationships as $relation => $config) {
            if (is_int($config)) {
                // Simple count
                $factory = $factory->has(
                    $this->getRelationFactory($relation, $factory)->count($config),
                    $relation
                );
            } elseif (is_array($config)) {
                // Complex configuration
                $count = $config['count'] ?? 1;
                $factoryMethod = $config['factory'] ?? null;
                $attributes = $config['attributes'] ?? [];

                $relationFactory = $this->getRelationFactory($relation, $factory);

                if ($factoryMethod) {
                    $relationFactory = $relationFactory->$factoryMethod();
                }

                if (! empty($attributes)) {
                    $relationFactory = $relationFactory->state($attributes);
                }

                $factory = $factory->has($relationFactory->count($count), $relation);
            }
        }

        return $factory;
    }

    /**
     * Apply temporal patterns to factory
     */
    public function withTemporalPattern(Factory $factory, string $field, array $config): Factory
    {
        $pattern = $this->patternRegistry->make('temporal', $config);

        return $factory->state(function (array $attributes) use ($field, $pattern) {
            $attributes[$field] = $pattern->generate();

            return $attributes;
        });
    }

    /**
     * Apply distribution pattern to factory
     */
    public function withDistribution(Factory $factory, string $field, string $distribution, array $params = []): Factory
    {
        $pattern = $this->patternRegistry->make($distribution, $params);

        return $factory->state(function (array $attributes) use ($field, $pattern) {
            $attributes[$field] = $pattern->generate();

            return $attributes;
        });
    }

    /**
     * Create realistic dataset using factory
     */
    public function createRealisticDataset(string $modelClass, int $count, array $config = []): array
    {
        $factory = $modelClass::factory();

        // Apply patterns
        if (isset($config['patterns'])) {
            foreach ($config['patterns'] as $field => $patternConfig) {
                $factory = $this->applyPattern($factory, $field, $patternConfig);
            }
        }

        // Apply states
        if (isset($config['states'])) {
            foreach ($config['states'] as $state => $percentage) {
                $stateCount = (int) ($count * $percentage);
                $factory = $factory->count($stateCount)->state($state);
            }
        }

        // Apply relationships
        if (isset($config['relationships'])) {
            $factory = $this->withRelationships($factory, $config['relationships']);
        }

        // Create records
        return $factory->count($count)->create()->toArray();
    }

    /**
     * Apply pattern to factory
     */
    protected function applyPattern(Factory $factory, string $field, $patternConfig): Factory
    {
        if (is_string($patternConfig)) {
            // Simple pattern name
            return $this->withDistribution($factory, $field, $patternConfig);
        }

        if (is_array($patternConfig)) {
            $type = $patternConfig['type'] ?? 'normal';

            return match ($type) {
                'temporal' => $this->withTemporalPattern($factory, $field, $patternConfig),
                'sequence' => $factory->sequence($this->createSequence($patternConfig['values'] ?? [])),
                default => $this->withDistribution($factory, $field, $type, $patternConfig),
            };
        }

        return $factory;
    }

    /**
     * Get relation factory
     */
    protected function getRelationFactory(string $relation, Factory $factory)
    {
        $model = $factory->modelName();
        $instance = new $model;

        if (method_exists($instance, $relation)) {
            $relationInstance = $instance->$relation();
            $relatedModel = get_class($relationInstance->getRelated());

            if (method_exists($relatedModel, 'factory')) {
                return $relatedModel::factory();
            }
        }

        throw new \RuntimeException("Cannot find factory for relation: {$relation}");
    }

    /**
     * Register custom state
     */
    public function registerState(string $name, callable $state): void
    {
        $this->states[$name] = $state;
    }

    /**
     * Get registered state
     */
    public function getState(string $name): ?callable
    {
        return $this->states[$name] ?? null;
    }
}

/**
 * Enhanced factory with pattern support
 */
class EnhancedFactory
{
    protected Factory $factory;

    protected FactoryIntegration $integration;

    protected array $patterns = [];

    protected array $distributions = [];

    public function __construct(Factory $factory, FactoryIntegration $integration)
    {
        $this->factory = $factory;
        $this->integration = $integration;
    }

    /**
     * Add pattern to field
     */
    public function pattern(string $field, string $pattern, array $config = []): self
    {
        $this->patterns[$field] = ['pattern' => $pattern, 'config' => $config];
        $this->applyPatterns();

        return $this;
    }

    /**
     * Add distribution to field
     */
    public function distribution(string $field, string $type, array $params = []): self
    {
        $this->distributions[$field] = ['type' => $type, 'params' => $params];
        $this->applyDistributions();

        return $this;
    }

    /**
     * Add normal distribution
     */
    public function normalDistribution(string $field, float $mean, float $stddev): self
    {
        return $this->distribution($field, 'normal', [
            'mean' => $mean,
            'stddev' => $stddev,
        ]);
    }

    /**
     * Add Pareto distribution
     */
    public function paretoDistribution(string $field, float $xmin, float $alpha): self
    {
        return $this->distribution($field, 'pareto', [
            'xmin' => $xmin,
            'alpha' => $alpha,
        ]);
    }

    /**
     * Add temporal pattern
     */
    public function temporalPattern(string $field, string $start, string $end): self
    {
        return $this->pattern($field, 'temporal', [
            'start' => $start,
            'end' => $end,
        ]);
    }

    /**
     * Add seasonal pattern
     */
    public function seasonalPattern(string $field, array $peaks, float $amplitude = 0.3): self
    {
        return $this->pattern($field, 'seasonal', [
            'peaks' => $peaks,
            'amplitude' => $amplitude,
        ]);
    }

    /**
     * With realistic relationships
     */
    public function withRealisticRelationships(array $config): self
    {
        $this->factory = $this->integration->withRelationships($this->factory, $config);

        return $this;
    }

    /**
     * Apply patterns to factory
     */
    protected function applyPatterns(): void
    {
        foreach ($this->patterns as $field => $config) {
            $this->factory = $this->factory->state(
                $this->integration->createState($field, [$field => $config])
            );
        }
    }

    /**
     * Apply distributions to factory
     */
    protected function applyDistributions(): void
    {
        foreach ($this->distributions as $field => $config) {
            $this->factory = $this->integration->withDistribution(
                $this->factory,
                $field,
                $config['type'],
                $config['params']
            );
        }
    }

    /**
     * Create records
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return $this->factory->create($attributes, $parent);
    }

    /**
     * Make records
     */
    public function make($attributes = [], ?Model $parent = null)
    {
        return $this->factory->make($attributes, $parent);
    }

    /**
     * Count records
     */
    public function count(?int $count = null)
    {
        $this->factory = $this->factory->count($count);

        return $this;
    }

    /**
     * Forward other methods to factory
     */
    public function __call($method, $parameters)
    {
        $result = $this->factory->$method(...$parameters);

        if ($result instanceof Factory) {
            $this->factory = $result;

            return $this;
        }

        return $result;
    }
}
