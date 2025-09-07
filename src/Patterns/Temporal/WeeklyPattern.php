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
}