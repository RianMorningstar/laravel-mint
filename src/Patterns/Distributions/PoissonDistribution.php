<?php

namespace LaravelMint\Patterns\Distributions;

use LaravelMint\Patterns\AbstractPattern;

class PoissonDistribution extends AbstractPattern implements DistributionInterface
{
    protected float $lambda; // Rate parameter (average number of events)
    protected ?int $max;

    protected function initialize(): void
    {
        $this->name = 'Poisson Distribution';
        $this->description = 'Generates values following a Poisson distribution (event frequency)';
        
        $this->lambda = $this->getConfig('lambda', 1);
        $this->max = $this->getConfig('max');
        
        $this->parameters = [
            'lambda' => [
                'type' => 'float',
                'description' => 'Average rate of events (λ)',
                'default' => 1,
                'required' => false,
            ],
            'max' => [
                'type' => 'int',
                'description' => 'Maximum value (optional truncation)',
                'default' => null,
                'required' => false,
            ],
        ];
    }

    /**
     * Generate a value from the Poisson distribution using Knuth's algorithm
     */
    public function generate(array $context = []): mixed
    {
        if ($this->lambda < 30) {
            // Knuth's algorithm for small lambda
            $L = exp(-$this->lambda);
            $k = 0;
            $p = 1.0;
            
            do {
                $k++;
                $p *= $this->faker->randomFloat(6, 0.000001, 0.999999);
            } while ($p > $L && $k < 1000); // Safety limit
            
            $value = $k - 1;
        } else {
            // For large lambda, use normal approximation
            $normal = new NormalDistribution([
                'mean' => $this->lambda,
                'stddev' => sqrt($this->lambda),
                'min' => 0
            ]);
            $value = round($normal->generate());
        }
        
        // Apply truncation if specified
        if ($this->max !== null) {
            $value = min($value, $this->max);
        }
        
        return (int) $value;
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
        return $this->lambda;
    }

    /**
     * Get the variance of the distribution
     */
    public function getVariance(): float
    {
        return $this->lambda;
    }

    /**
     * Get the standard deviation
     */
    public function getStandardDeviation(): float
    {
        return sqrt($this->lambda);
    }

    /**
     * Probability mass function (PMF) for discrete distribution
     */
    public function pdf(float $x): float
    {
        if ($x < 0 || floor($x) != $x) {
            return 0;
        }
        
        $k = (int) $x;
        return (pow($this->lambda, $k) * exp(-$this->lambda)) / $this->factorial($k);
    }

    /**
     * Cumulative distribution function
     */
    public function cdf(float $x): float
    {
        if ($x < 0) {
            return 0;
        }
        
        $sum = 0;
        $k = floor($x);
        
        for ($i = 0; $i <= $k; $i++) {
            $sum += $this->pdf($i);
        }
        
        return $sum;
    }

    /**
     * Calculate factorial (with caching for performance)
     */
    protected function factorial(int $n): float
    {
        static $cache = [0 => 1, 1 => 1];
        
        if (isset($cache[$n])) {
            return $cache[$n];
        }
        
        if ($n > 170) {
            // Factorial becomes too large, use Stirling's approximation
            return sqrt(2 * pi() * $n) * pow($n / exp(1), $n);
        }
        
        $result = 1;
        for ($i = 2; $i <= $n; $i++) {
            $result *= $i;
        }
        
        $cache[$n] = $result;
        return $result;
    }

    /**
     * Validate configuration
     */
    protected function validateSpecific(array $config): bool
    {
        if (isset($config['lambda']) && $config['lambda'] <= 0) {
            return false;
        }
        
        if (isset($config['max']) && $config['max'] < 0) {
            return false;
        }
        
        return true;
    }
}