<?php

namespace LaravelMint\Patterns\Distributions;

use LaravelMint\Patterns\AbstractPattern;

class ExponentialDistribution extends AbstractPattern implements DistributionInterface
{
    protected float $lambda; // Rate parameter
    protected ?float $min;
    protected ?float $max;

    protected function initialize(): void
    {
        $this->name = 'Exponential Distribution';
        $this->description = 'Generates values following an exponential distribution (time between events)';
        
        $this->lambda = $this->getConfig('lambda', 1);
        $this->min = $this->getConfig('min', 0);
        $this->max = $this->getConfig('max');
        
        $this->parameters = [
            'lambda' => [
                'type' => 'float',
                'description' => 'Rate parameter (1/mean)',
                'default' => 1,
                'required' => false,
            ],
            'min' => [
                'type' => 'float',
                'description' => 'Minimum value',
                'default' => 0,
                'required' => false,
            ],
            'max' => [
                'type' => 'float',
                'description' => 'Maximum value (optional truncation)',
                'default' => null,
                'required' => false,
            ],
        ];
    }

    /**
     * Generate a value from the exponential distribution
     */
    public function generate(array $context = []): mixed
    {
        // Inverse transform sampling
        $u = $this->faker->randomFloat(6, 0.000001, 0.999999);
        $value = -log(1 - $u) / $this->lambda;
        
        // Apply bounds
        return $this->clamp($value, $this->min, $this->max);
    }

    /**
     * Generate multiple samples
     */
    public function sample(int $count): array
    {
        $samples = [];
        for ($i = 0; $i < $count; $i++) {
            $samples[] = $this->generate();
        }
        return $samples;
    }

    /**
     * Get the mean of the distribution
     */
    public function getMean(): float
    {
        return 1 / $this->lambda;
    }

    /**
     * Get the variance of the distribution
     */
    public function getVariance(): float
    {
        return 1 / pow($this->lambda, 2);
    }

    /**
     * Get the standard deviation
     */
    public function getStandardDeviation(): float
    {
        return 1 / $this->lambda;
    }

    /**
     * Probability density function
     */
    public function pdf(float $x): float
    {
        if ($x < 0) {
            return 0;
        }
        
        return $this->lambda * exp(-$this->lambda * $x);
    }

    /**
     * Cumulative distribution function
     */
    public function cdf(float $x): float
    {
        if ($x < 0) {
            return 0;
        }
        
        return 1 - exp(-$this->lambda * $x);
    }

    /**
     * Get the median (50th percentile)
     */
    public function getMedian(): float
    {
        return log(2) / $this->lambda;
    }

    /**
     * Get the mode
     */
    public function getMode(): float
    {
        return 0; // Mode is always 0 for exponential distribution
    }

    /**
     * Validate configuration
     */
    protected function validateSpecific(array $config): bool
    {
        if (isset($config['lambda']) && $config['lambda'] <= 0) {
            return false;
        }
        
        if (isset($config['min']) && $config['min'] < 0) {
            return false;
        }
        
        if (isset($config['min']) && isset($config['max']) && $config['min'] >= $config['max']) {
            return false;
        }
        
        return true;
    }
}