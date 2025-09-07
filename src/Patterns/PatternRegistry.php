<?php

namespace LaravelMint\Patterns;

use LaravelMint\Patterns\Distributions\ExponentialDistribution;
use LaravelMint\Patterns\Distributions\NormalDistribution;
use LaravelMint\Patterns\Distributions\ParetoDistribution;
use LaravelMint\Patterns\Distributions\PoissonDistribution;
use LaravelMint\Patterns\Temporal\BusinessHours;
use LaravelMint\Patterns\Temporal\LinearGrowth;
use LaravelMint\Patterns\Temporal\SeasonalPattern;

class PatternRegistry
{
    protected array $patterns = [];

    protected array $aliases = [];

    protected array $builtInPatterns = [];

    public function __construct()
    {
        $this->registerBuiltInPatterns();
    }

    /**
     * Register built-in patterns
     */
    protected function registerBuiltInPatterns(): void
    {
        // Statistical Distributions
        $this->register('distribution.normal', NormalDistribution::class);
        $this->register('distribution.pareto', ParetoDistribution::class);
        $this->register('distribution.poisson', PoissonDistribution::class);
        $this->register('distribution.exponential', ExponentialDistribution::class);

        // Temporal Patterns
        $this->register('temporal.linear', LinearGrowth::class);
        $this->register('temporal.seasonal', SeasonalPattern::class);
        $this->register('temporal.business_hours', BusinessHours::class);

        // Aliases for convenience
        $this->alias('normal', 'distribution.normal');
        $this->alias('bell_curve', 'distribution.normal');
        $this->alias('gaussian', 'distribution.normal');
        $this->alias('pareto', 'distribution.pareto');
        $this->alias('80-20', 'distribution.pareto');
        $this->alias('poisson', 'distribution.poisson');
        $this->alias('exponential', 'distribution.exponential');
        $this->alias('linear', 'temporal.linear');
        $this->alias('growth', 'temporal.linear');
        $this->alias('seasonal', 'temporal.seasonal');
        $this->alias('business_hours', 'temporal.business_hours');
        $this->alias('working_hours', 'temporal.business_hours');

        // Store list of built-in patterns
        $this->builtInPatterns = array_keys($this->patterns);
    }

    /**
     * Register a pattern
     */
    public function register(string $name, string $className): void
    {
        if (! class_exists($className)) {
            throw new \InvalidArgumentException("Pattern class {$className} does not exist");
        }

        if (! is_subclass_of($className, PatternInterface::class)) {
            throw new \InvalidArgumentException("Pattern class {$className} must implement PatternInterface");
        }

        $this->patterns[$name] = $className;
    }

    /**
     * Register an alias for a pattern
     */
    public function alias(string $alias, string $pattern): void
    {
        if (! isset($this->patterns[$pattern])) {
            throw new \InvalidArgumentException("Pattern {$pattern} is not registered");
        }

        $this->aliases[$alias] = $pattern;
    }

    /**
     * Create a pattern instance
     */
    public function create(string $name, array $config = []): PatternInterface
    {
        $patternName = $this->resolvePattern($name);

        if (! $patternName) {
            throw new \InvalidArgumentException("Pattern {$name} is not registered");
        }

        $className = $this->patterns[$patternName];

        return new $className($config);
    }

    /**
     * Check if a pattern exists
     */
    public function has(string $name): bool
    {
        return $this->resolvePattern($name) !== null;
    }

    /**
     * Resolve pattern name (handle aliases)
     */
    protected function resolvePattern(string $name): ?string
    {
        if (isset($this->patterns[$name])) {
            return $name;
        }

        if (isset($this->aliases[$name])) {
            return $this->aliases[$name];
        }

        return null;
    }

    /**
     * Get all registered patterns
     */
    public function all(): array
    {
        return $this->patterns;
    }

    /**
     * Get all aliases
     */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /**
     * Get pattern info
     */
    public function info(string $name): array
    {
        $patternName = $this->resolvePattern($name);

        if (! $patternName) {
            throw new \InvalidArgumentException("Pattern {$name} is not registered");
        }

        $pattern = $this->create($patternName);

        return [
            'name' => $pattern->getName(),
            'description' => $pattern->getDescription(),
            'parameters' => $pattern->getParameters(),
            'class' => $this->patterns[$patternName],
            'aliases' => array_keys(array_filter($this->aliases, fn ($p) => $p === $patternName)),
            'built_in' => in_array($patternName, $this->builtInPatterns),
        ];
    }

    /**
     * Load pattern from configuration array
     */
    public function load(array $config): PatternInterface
    {
        if (! isset($config['type'])) {
            throw new \InvalidArgumentException('Pattern configuration must include a type');
        }

        $type = $config['type'];
        unset($config['type']);

        return $this->create($type, $config);
    }

    /**
     * Create composite pattern from multiple patterns
     */
    public function composite(array $patterns): CompositePattern
    {
        $instances = [];

        foreach ($patterns as $name => $config) {
            if (is_string($config)) {
                // Simple pattern reference
                $instances[$name] = $this->create($config);
            } elseif (is_array($config)) {
                // Pattern with configuration
                $instances[$name] = $this->load($config);
            } else {
                throw new \InvalidArgumentException("Invalid pattern configuration for {$name}");
            }
        }

        return new CompositePattern($instances);
    }

    /**
     * Get patterns by category
     */
    public function getByCategory(string $category): array
    {
        $filtered = [];
        $prefix = $category.'.';

        foreach ($this->patterns as $name => $class) {
            if (str_starts_with($name, $prefix)) {
                $filtered[$name] = $class;
            }
        }

        return $filtered;
    }

    /**
     * Get all categories
     */
    public function getCategories(): array
    {
        $categories = [];

        foreach ($this->patterns as $name => $class) {
            $parts = explode('.', $name);
            if (count($parts) > 1) {
                $categories[] = $parts[0];
            }
        }

        return array_unique($categories);
    }
}
