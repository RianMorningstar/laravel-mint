<?php

namespace LaravelMint\Patterns\Temporal;

use LaravelMint\Patterns\PatternInterface;

interface TemporalPatternInterface extends PatternInterface
{
    /**
     * Generate a value for a specific timestamp
     *
     * @param \DateTimeInterface $timestamp
     * @return mixed
     */
    public function generateAt(\DateTimeInterface $timestamp): mixed;

    /**
     * Generate a time series of values
     *
     * @param \DateTimeInterface $start
     * @param \DateTimeInterface $end
     * @param string $interval Interval string (e.g., '1 hour', '1 day')
     * @return array
     */
    public function generateSeries(\DateTimeInterface $start, \DateTimeInterface $end, string $interval): array;

    /**
     * Get the pattern period (if applicable)
     *
     * @return string|null
     */
    public function getPeriod(): ?string;

    /**
     * Set the base timestamp for relative calculations
     *
     * @param \DateTimeInterface $baseTime
     * @return void
     */
    public function setBaseTime(\DateTimeInterface $baseTime): void;
}