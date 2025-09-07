<?php

namespace LaravelMint\Patterns\Temporal;

use LaravelMint\Patterns\AbstractPattern;

class WeeklyPattern extends AbstractPattern implements TemporalInterface
{
    protected array $weekdayWeights = [];
    protected array $hourlyWeights = [];

    protected function initialize(): void
    {
        $this->name = 'Weekly Pattern';
        $this->description = 'Generates values following weekly activity patterns';

        // Default weights for days of week (0 = Sunday, 6 = Saturday)
        $this->weekdayWeights = $this->getConfig('weekday_weights', [
            0 => 0.6,  // Sunday
            1 => 1.0,  // Monday
            2 => 1.0,  // Tuesday
            3 => 1.0,  // Wednesday
            4 => 1.0,  // Thursday
            5 => 0.9,  // Friday
            6 => 0.7,  // Saturday
        ]);

        // Default weights for hours of day (0-23)
        $this->hourlyWeights = $this->getConfig('hourly_weights', array_fill(0, 24, 1.0));

        $this->parameters = [
            'weekday_weights' => [
                'type' => 'array',
                'description' => 'Weights for each day of week (0=Sunday, 6=Saturday)',
                'default' => $this->weekdayWeights,
                'required' => false,
            ],
            'hourly_weights' => [
                'type' => 'array',
                'description' => 'Weights for each hour of day (0-23)',
                'default' => $this->hourlyWeights,
                'required' => false,
            ],
        ];
    }

    /**
     * Generate a timestamp following the weekly pattern
     */
    public function generateTimestamp(array $context = []): \DateTime
    {
        $startDate = $context['start_date'] ?? new \DateTime('-1 week');
        $endDate = $context['end_date'] ?? new \DateTime('now');

        // Generate a random base timestamp
        $timestamp = $this->faker->dateTimeBetween($startDate, $endDate);

        // Apply weekly pattern weight
        $dayOfWeek = (int) $timestamp->format('w');
        $weight = $this->weekdayWeights[$dayOfWeek] ?? 1.0;

        // Apply hourly pattern weight
        $hour = (int) $timestamp->format('H');
        $hourWeight = $this->hourlyWeights[$hour] ?? 1.0;

        // Combine weights
        $totalWeight = $weight * $hourWeight;

        // Randomly decide if this timestamp should be kept based on weight
        if ($this->faker->randomFloat(2, 0, 1) > $totalWeight) {
            // Regenerate if weight check fails
            return $this->generateTimestamp($context);
        }

        return $timestamp;
    }

    /**
     * Apply the pattern to data
     */
    public function apply(array $data, array $config = []): array
    {
        $field = $config['field'] ?? 'created_at';
        
        foreach ($data as &$item) {
            $item[$field] = $this->generateTimestamp($config);
        }

        return $data;
    }

    /**
     * Get peak days of the week
     */
    public function getPeakDays(): array
    {
        $maxWeight = max($this->weekdayWeights);
        $peakDays = [];
        
        foreach ($this->weekdayWeights as $day => $weight) {
            if ($weight === $maxWeight) {
                $peakDays[] = $day;
            }
        }
        
        return $peakDays;
    }

    /**
     * Get peak hours of the day
     */
    public function getPeakHours(): array
    {
        $maxWeight = max($this->hourlyWeights);
        $peakHours = [];
        
        foreach ($this->hourlyWeights as $hour => $weight) {
            if ($weight === $maxWeight) {
                $peakHours[] = $hour;
            }
        }
        
        return $peakHours;
    }

    /**
     * Get value for a specific time period
     */
    public function getValueForPeriod($period): mixed
    {
        if ($period instanceof \DateTime) {
            $dayOfWeek = (int) $period->format('w');
            $hour = (int) $period->format('H');
            
            $dayWeight = $this->weekdayWeights[$dayOfWeek] ?? 1.0;
            $hourWeight = $this->hourlyWeights[$hour] ?? 1.0;
            
            return $dayWeight * $hourWeight;
        }
        
        // If period is a day number
        if (is_int($period) && $period >= 0 && $period <= 6) {
            return $this->weekdayWeights[$period] ?? 1.0;
        }
        
        return 1.0;
    }

    /**
     * Set pattern configuration
     */
    public function setConfig(array $config): void
    {
        if (isset($config['weekday_weights'])) {
            $this->weekdayWeights = $config['weekday_weights'];
        }
        
        if (isset($config['hourly_weights'])) {
            $this->hourlyWeights = $config['hourly_weights'];
        }
        
        parent::setConfig($config);
    }

    /**
     * Generate values for multiple periods
     */
    public function generateSequence(int $periods): array
    {
        $sequence = [];
        $startDate = new \DateTime('-' . $periods . ' days');
        
        for ($i = 0; $i < $periods; $i++) {
            $date = clone $startDate;
            $date->modify('+' . $i . ' days');
            $sequence[] = $this->getValueForPeriod($date);
        }
        
        return $sequence;
    }
    
    /**
     * Generate a value using the pattern
     */
    public function generate(array $context = []): mixed
    {
        // Generate a timestamp following the weekly pattern
        $timestamp = $this->generateTimestamp($context);
        
        // If context requests a specific type, return that
        if (isset($context['type'])) {
            switch ($context['type']) {
                case 'timestamp':
                    return $timestamp;
                case 'weight':
                    return $this->getValueForPeriod($timestamp);
                case 'day':
                    return (int) $timestamp->format('w');
                case 'hour':
                    return (int) $timestamp->format('H');
            }
        }
        
        // Default to returning timestamp
        return $timestamp;
    }
    
    /**
     * Generate value for a specific date
     */
    public function generateForDate(\DateTime $date): mixed
    {
        return $this->getValueForPeriod($date);
    }
}