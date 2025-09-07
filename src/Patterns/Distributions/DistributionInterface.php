<?php

namespace LaravelMint\Patterns\Distributions;

use LaravelMint\Patterns\PatternInterface;

interface DistributionInterface extends PatternInterface
{
    /**
     * Get the mean of the distribution
     */
    public function getMean(): float;

    /**
     * Get the variance of the distribution
     */
    public function getVariance(): float;

    /**
     * Get the standard deviation of the distribution
     */
    public function getStandardDeviation(): float;

    /**
     * Generate multiple samples from the distribution
     *
     * @param  int  $count  Number of samples to generate
     */
    public function sample(int $count): array;

    /**
     * Get the probability density function value at a given point
     */
    public function pdf(float $x): float;

    /**
     * Get the cumulative distribution function value at a given point
     */
    public function cdf(float $x): float;
}
