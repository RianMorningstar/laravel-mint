<?php

namespace LaravelMint\Patterns\Temporal;

use LaravelMint\Patterns\PatternInterface;

interface TemporalPatternInterface extends PatternInterface
{
    /**
     * Generate a value for a specific timestamp
     */
    public function generateAt(\DateTimeInterface $timestamp): mixed;

    /**
     * Generate a time series of values
     *
     * @param  string  $interval  Interval string (e.g., '1 hour', '1 day')
     */
    public function generateSeries(\DateTimeInterface $start, \DateTimeInterface $end, string $interval): array;

    /**
     * Get the pattern period (if applicable)
     */
    public function getPeriod(): ?string;

    /**
     * Set the base timestamp for relative calculations
     */
    public function setBaseTime(\DateTimeInterface $baseTime): void;
}
