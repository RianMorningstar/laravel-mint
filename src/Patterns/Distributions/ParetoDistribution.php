<?php

namespace LaravelMint\Patterns\Distributions;

use LaravelMint\Patterns\AbstractPattern;

class ParetoDistribution extends AbstractPattern implements DistributionInterface
{
    protected float $alpha; // Shape parameter
    protected float $xmin;   // Scale parameter (minimum value)
    protected ?float $max;

    protected function initialize(): void
    {
        $this->name = 'Pareto Distribution';
        $this->description = 'Generates values following a Pareto distribution (80/20 rule, power law)';
        
        $this->alpha = $this->getConfig('alpha', 1.16); // Default gives ~80/20 distribution
        $this->xmin = $this->getConfig('xmin', 1);
        $this->max = $this->getConfig('max');
        
        $this->parameters = [
            'alpha' => [
                'type' => 'float',
                'description' => 'Shape parameter (lower = more inequality, 1.16 ≈ 80/20)',
                'default' => 1.16,
                'required' => false,
            ],
            'xmin' => [
                'type' => 'float',
                'description' => 'Minimum value (scale parameter)',
                'default' => 1,
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
     * Generate a value from the Pareto distribution
     */
    public function generate(array $context = []): mixed
    {
        // Inverse transform sampling for Pareto distribution
        $u = $this->faker->randomFloat(6, 0.000001, 0.999999);
        $value = $this->xmin / pow($u, 1 / $this->alpha);
        
        // Apply truncation if specified
        return $this->clamp($value, $this->xmin, $this->max);
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
        if ($this->alpha <= 1) {
            return INF; // Mean is infinite for alpha <= 1
        }
        
        return ($this->alpha * $this->xmin) / ($this->alpha - 1);
    }

    /**
     * Get the variance of the distribution
     */
    public function getVariance(): float
    {
        if ($this->alpha <= 2) {
            return INF; // Variance is infinite for alpha <= 2
        }
        
        $mean = $this->getMean();
        return (pow($this->xmin, 2) * $this->alpha) / (pow($this->alpha - 1, 2) * ($this->alpha - 2));
    }

    /**
     * Get the standard deviation
     */
    public function getStandardDeviation(): float
    {
        $variance = $this->getVariance();
        return is_infinite($variance) ? INF : sqrt($variance);
    }

    /**
     * Probability density function
     */
    public function pdf(float $x): float
    {
        if ($x < $this->xmin) {
            return 0;
        }
        
        return ($this->alpha * pow($this->xmin, $this->alpha)) / pow($x, $this->alpha + 1);
    }

    /**
     * Cumulative distribution function
     */
    public function cdf(float $x): float
    {
        if ($x < $this->xmin) {
            return 0;
        }
        
        return 1 - pow($this->xmin / $x, $this->alpha);
    }

    /**
     * Get the percentile that owns a given percentage of the total
     * Useful for 80/20 analysis
     */
    public function getPercentileOwnership(float $percentile): float
    {
        // What percentage of total is owned by top X percentile
        if ($percentile <= 0 || $percentile >= 1) {
            throw new \InvalidArgumentException('Percentile must be between 0 and 1');
        }
        
        if ($this->alpha <= 1) {
            // For alpha <= 1, use approximation
            return 1 - pow($percentile, 1 - 1/$this->alpha);
        }
        
        return 1 - pow($percentile, ($this->alpha - 1) / $this->alpha);
    }

    /**
     * Validate configuration
     */
    protected function validateSpecific(array $config): bool
    {
        if (isset($config['alpha']) && $config['alpha'] <= 0) {
            return false;
        }
        
        if (isset($config['xmin']) && $config['xmin'] <= 0) {
            return false;
        }
        
        if (isset($config['max']) && isset($config['xmin']) && $config['max'] <= $config['xmin']) {
            return false;
        }
        
        return true;
    }
}