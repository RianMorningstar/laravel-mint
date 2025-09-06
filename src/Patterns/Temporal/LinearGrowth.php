<?php

namespace LaravelMint\Patterns\Temporal;

use LaravelMint\Patterns\AbstractPattern;

class LinearGrowth extends AbstractPattern implements TemporalPatternInterface
{
    protected float $initialValue;
    protected float $growthRate;
    protected ?float $min;
    protected ?float $max;
    protected ?\DateTimeInterface $baseTime = null;
    protected string $timeUnit = 'day';

    protected function initialize(): void
    {
        $this->name = 'Linear Growth';
        $this->description = 'Generates values with linear growth over time';
        
        $this->initialValue = $this->getConfig('initial_value', 100);
        $this->growthRate = $this->getConfig('growth_rate', 1);
        $this->timeUnit = $this->getConfig('time_unit', 'day');
        $this->min = $this->getConfig('min');
        $this->max = $this->getConfig('max');
        
        $baseTimeStr = $this->getConfig('base_time');
        if ($baseTimeStr) {
            $this->baseTime = new \DateTime($baseTimeStr);
        } else {
            $this->baseTime = new \DateTime();
        }
        
        $this->parameters = [
            'initial_value' => [
                'type' => 'float',
                'description' => 'Starting value',
                'default' => 100,
                'required' => false,
            ],
            'growth_rate' => [
                'type' => 'float',
                'description' => 'Growth per time unit',
                'default' => 1,
                'required' => false,
            ],
            'time_unit' => [
                'type' => 'string',
                'description' => 'Time unit (second, minute, hour, day, week, month, year)',
                'default' => 'day',
                'required' => false,
            ],
            'base_time' => [
                'type' => 'datetime',
                'description' => 'Base time for calculations',
                'default' => 'now',
                'required' => false,
            ],
        ];
    }

    /**
     * Generate a value for the current time
     */
    public function generate(array $context = []): mixed
    {
        $timestamp = $context['timestamp'] ?? new \DateTime();
        return $this->generateAt($timestamp);
    }

    /**
     * Generate a value for a specific timestamp
     */
    public function generateAt(\DateTimeInterface $timestamp): mixed
    {
        if (!$this->baseTime) {
            $this->baseTime = new \DateTime();
        }
        
        $timeDiff = $this->getTimeDifference($this->baseTime, $timestamp);
        $value = $this->initialValue + ($this->growthRate * $timeDiff);
        
        // Add some random variation (±5%)
        $variation = $this->faker->randomFloat(2, 0.95, 1.05);
        $value *= $variation;
        
        return $this->clamp($value, $this->min, $this->max);
    }

    /**
     * Generate a time series
     */
    public function generateSeries(\DateTimeInterface $start, \DateTimeInterface $end, string $interval): array
    {
        $series = [];
        $current = clone $start;
        $intervalObj = \DateInterval::createFromDateString($interval);
        
        while ($current <= $end) {
            $series[] = [
                'timestamp' => clone $current,
                'value' => $this->generateAt($current),
            ];
            $current->add($intervalObj);
        }
        
        return $series;
    }

    /**
     * Get time difference in the specified unit
     */
    protected function getTimeDifference(\DateTimeInterface $start, \DateTimeInterface $end): float
    {
        $diff = $end->getTimestamp() - $start->getTimestamp();
        
        switch ($this->timeUnit) {
            case 'second':
                return $diff;
            case 'minute':
                return $diff / 60;
            case 'hour':
                return $diff / 3600;
            case 'day':
                return $diff / 86400;
            case 'week':
                return $diff / 604800;
            case 'month':
                return $diff / 2592000; // Approximate (30 days)
            case 'year':
                return $diff / 31536000; // Approximate (365 days)
            default:
                return $diff / 86400; // Default to days
        }
    }

    /**
     * Get the pattern period
     */
    public function getPeriod(): ?string
    {
        return null; // Linear growth has no period
    }

    /**
     * Set the base time
     */
    public function setBaseTime(\DateTimeInterface $baseTime): void
    {
        $this->baseTime = $baseTime;
    }

    /**
     * Validate configuration
     */
    protected function validateSpecific(array $config): bool
    {
        $validTimeUnits = ['second', 'minute', 'hour', 'day', 'week', 'month', 'year'];
        
        if (isset($config['time_unit']) && !in_array($config['time_unit'], $validTimeUnits)) {
            return false;
        }
        
        if (isset($config['min']) && isset($config['max']) && $config['min'] >= $config['max']) {
            return false;
        }
        
        return true;
    }
}