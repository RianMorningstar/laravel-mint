<?php

namespace LaravelMint\Patterns\Distributions;

use LaravelMint\Patterns\AbstractPattern;

class NormalDistribution extends AbstractPattern implements DistributionInterface
{
    protected float $mean;
    protected float $stddev;
    protected ?float $min;
    protected ?float $max;

    protected function initialize(): void
    {
        $this->name = 'Normal Distribution';
        $this->description = 'Generates values following a normal (Gaussian) distribution';
        
        $this->mean = $this->getConfig('mean', 0);
        $this->stddev = $this->getConfig('stddev', 1);
        $this->min = $this->getConfig('min');
        $this->max = $this->getConfig('max');
        
        $this->parameters = [
            'mean' => [
                'type' => 'float',
                'description' => 'Mean (center) of the distribution',
                'default' => 0,
                'required' => false,
            ],
            'stddev' => [
                'type' => 'float',
                'description' => 'Standard deviation (spread) of the distribution',
                'default' => 1,
                'required' => false,
            ],
            'min' => [
                'type' => 'float',
                'description' => 'Minimum value (optional truncation)',
                'default' => null,
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
     * Generate a value from the normal distribution using Box-Muller transform
     */
    public function generate(array $context = []): mixed
    {
        // Box-Muller transform for generating normal distribution
        $u1 = $this->faker->randomFloat(6, 0.000001, 0.999999);
        $u2 = $this->faker->randomFloat(6, 0, 1);
        
        $z0 = sqrt(-2 * log($u1)) * cos(2 * pi() * $u2);
        $value = $this->mean + $z0 * $this->stddev;
        
        // Apply truncation if specified
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
        return $this->mean;
    }

    /**
     * Get the variance of the distribution
     */
    public function getVariance(): float
    {
        return pow($this->stddev, 2);
    }

    /**
     * Get the standard deviation
     */
    public function getStandardDeviation(): float
    {
        return $this->stddev;
    }

    /**
     * Probability density function
     */
    public function pdf(float $x): float
    {
        $coefficient = 1 / ($this->stddev * sqrt(2 * pi()));
        $exponent = -pow($x - $this->mean, 2) / (2 * pow($this->stddev, 2));
        
        return $coefficient * exp($exponent);
    }

    /**
     * Cumulative distribution function (approximation)
     */
    public function cdf(float $x): float
    {
        $z = ($x - $this->mean) / $this->stddev;
        return 0.5 * (1 + $this->erf($z / sqrt(2)));
    }

    /**
     * Error function approximation
     */
    protected function erf(float $x): float
    {
        // Approximation of error function
        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;
        
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);
        
        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);
        
        return $sign * $y;
    }

    /**
     * Validate configuration
     */
    protected function validateSpecific(array $config): bool
    {
        if (isset($config['stddev']) && $config['stddev'] <= 0) {
            return false;
        }
        
        if (isset($config['min']) && isset($config['max']) && $config['min'] >= $config['max']) {
            return false;
        }
        
        return true;
    }
}