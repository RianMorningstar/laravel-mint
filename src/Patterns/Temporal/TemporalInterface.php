<?php

namespace LaravelMint\Patterns\Temporal;

interface TemporalInterface
{
    /**
     * Get value for a specific time period
     */
    public function getValueForPeriod($period): mixed;
    
    /**
     * Set pattern configuration
     */
    public function setConfig(array $config): void;
    
    /**
     * Generate values for multiple periods
     */
    public function generateSequence(int $periods): array;
}