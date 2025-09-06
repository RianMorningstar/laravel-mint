<?php

namespace LaravelMint\Patterns\Temporal;

use LaravelMint\Patterns\AbstractPattern;

class BusinessHours extends AbstractPattern implements TemporalPatternInterface
{
    protected float $peakValue;
    protected float $offPeakValue;
    protected array $businessHours;
    protected array $businessDays;
    protected string $timezone;
    protected array $peakHours;
    protected ?\DateTimeInterface $baseTime = null;

    protected function initialize(): void
    {
        $this->name = 'Business Hours Pattern';
        $this->description = 'Generates values based on business hours and peak times';
        
        $this->peakValue = $this->getConfig('peak_value', 100);
        $this->offPeakValue = $this->getConfig('off_peak_value', 10);
        $this->businessHours = $this->getConfig('business_hours', ['start' => 9, 'end' => 17]);
        $this->businessDays = $this->getConfig('business_days', [1, 2, 3, 4, 5]); // Mon-Fri
        $this->peakHours = $this->getConfig('peak_hours', [12, 14, 16]); // Lunch and afternoon
        $this->timezone = $this->getConfig('timezone', 'UTC');
        
        $this->parameters = [
            'peak_value' => [
                'type' => 'float',
                'description' => 'Value during peak hours',
                'default' => 100,
                'required' => false,
            ],
            'off_peak_value' => [
                'type' => 'float',
                'description' => 'Value during off-peak hours',
                'default' => 10,
                'required' => false,
            ],
            'business_hours' => [
                'type' => 'array',
                'description' => 'Business hours (start and end)',
                'default' => ['start' => 9, 'end' => 17],
                'required' => false,
            ],
            'business_days' => [
                'type' => 'array',
                'description' => 'Business days (1=Monday, 7=Sunday)',
                'default' => [1, 2, 3, 4, 5],
                'required' => false,
            ],
            'peak_hours' => [
                'type' => 'array',
                'description' => 'Peak hours within business hours',
                'default' => [12, 14, 16],
                'required' => false,
            ],
            'timezone' => [
                'type' => 'string',
                'description' => 'Timezone for business hours',
                'default' => 'UTC',
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
        // Convert to business timezone
        $localTime = clone $timestamp;
        $localTime->setTimezone(new \DateTimeZone($this->timezone));
        
        $hour = (int) $localTime->format('H');
        $minute = (int) $localTime->format('i');
        $dayOfWeek = (int) $localTime->format('N'); // 1 (Monday) to 7 (Sunday)
        
        // Check if it's a business day
        if (!in_array($dayOfWeek, $this->businessDays)) {
            // Weekend or non-business day
            return $this->generateOffPeakValue();
        }
        
        // Check if within business hours
        $currentTime = $hour + ($minute / 60);
        if ($currentTime < $this->businessHours['start'] || $currentTime >= $this->businessHours['end']) {
            // Outside business hours
            return $this->generateOffPeakValue();
        }
        
        // Within business hours - check for peak times
        $isPeakHour = false;
        foreach ($this->peakHours as $peakHour) {
            if (abs($currentTime - $peakHour) < 1) {
                $isPeakHour = true;
                break;
            }
        }
        
        if ($isPeakHour) {
            return $this->generatePeakValue();
        }
        
        // Regular business hours
        return $this->generateBusinessValue();
    }

    /**
     * Generate peak hour value
     */
    protected function generatePeakValue(): float
    {
        // Add some variation (±10%)
        $variation = $this->faker->randomFloat(2, 0.9, 1.1);
        return $this->peakValue * $variation;
    }

    /**
     * Generate regular business hour value
     */
    protected function generateBusinessValue(): float
    {
        // Value between off-peak and peak
        $businessValue = ($this->peakValue + $this->offPeakValue) / 2;
        
        // Add variation (±15%)
        $variation = $this->faker->randomFloat(2, 0.85, 1.15);
        return $businessValue * $variation;
    }

    /**
     * Generate off-peak value
     */
    protected function generateOffPeakValue(): float
    {
        // Add minimal variation (±20%)
        $variation = $this->faker->randomFloat(2, 0.8, 1.2);
        return $this->offPeakValue * $variation;
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
     * Get the pattern period
     */
    public function getPeriod(): ?string
    {
        return 'week'; // Business hours repeat weekly
    }

    /**
     * Set the base time
     */
    public function setBaseTime(\DateTimeInterface $baseTime): void
    {
        $this->baseTime = $baseTime;
    }

    /**
     * Get activity level for a timestamp (0-1)
     */
    public function getActivityLevel(\DateTimeInterface $timestamp): float
    {
        $value = $this->generateAt($timestamp);
        $range = $this->peakValue - $this->offPeakValue;
        
        if ($range == 0) {
            return 0.5;
        }
        
        return ($value - $this->offPeakValue) / $range;
    }
}