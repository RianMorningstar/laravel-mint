<?php

namespace LaravelMint\Patterns;

use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;

abstract class AbstractPattern implements PatternInterface
{
    protected array $config = [];

    protected FakerGenerator $faker;

    protected string $name;

    protected string $description;

    protected array $parameters = [];

    protected ?int $seed = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->seed = $config['seed'] ?? null;

        $this->faker = FakerFactory::create();
        if ($this->seed !== null) {
            $this->faker->seed($this->seed);
        }

        $this->initialize();
    }

    /**
     * Initialize pattern-specific properties
     */
    abstract protected function initialize(): void;

    /**
     * Validate pattern configuration
     */
    public function validate(array $config): bool
    {
        foreach ($this->getRequiredParameters() as $param) {
            if (! isset($config[$param])) {
                return false;
            }
        }

        return $this->validateSpecific($config);
    }

    /**
     * Pattern-specific validation
     */
    protected function validateSpecific(array $config): bool
    {
        return true;
    }

    /**
     * Get required parameters
     */
    protected function getRequiredParameters(): array
    {
        return [];
    }

    /**
     * Get pattern name
     */
    public function getName(): string
    {
        return $this->name ?? class_basename($this);
    }

    /**
     * Get pattern description
     */
    public function getDescription(): string
    {
        return $this->description ?? 'No description available';
    }

    /**
     * Get pattern parameters
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Set pattern configuration
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);

        if (isset($config['seed'])) {
            $this->seed = $config['seed'];
            $this->faker->seed($this->seed);
        }
    }

    /**
     * Get configuration value
     */
    protected function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Reset pattern state
     */
    public function reset(): void
    {
        // Override in patterns that maintain state
    }

    /**
     * Clamp value between min and max
     */
    protected function clamp($value, $min = null, $max = null)
    {
        if ($min !== null && $value < $min) {
            return $min;
        }

        if ($max !== null && $value > $max) {
            return $max;
        }

        return $value;
    }
}
