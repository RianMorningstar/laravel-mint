<?php

namespace LaravelMint\Patterns\Temporal;

use LaravelMint\Patterns\AbstractPattern;

class SeasonalPattern extends AbstractPattern implements TemporalPatternInterface
{
    protected float $baseValue;

    protected float $amplitude;

    protected string $period;

    protected array $peaks;

    protected ?float $min;

    protected ?float $max;

    protected ?\DateTimeInterface $baseTime = null;

    protected float $trendRate = 0;

    protected function initialize(): void
    {
        $this->name = 'Seasonal Pattern';
        $this->description = 'Generates values with seasonal variations';

        $this->baseValue = $this->getConfig('base_value', 100);
        $this->amplitude = $this->getConfig('amplitude', 20);
        $this->period = $this->getConfig('period', 'year');
        $this->peaks = $this->getConfig('peaks', ['december', 'july']); // Holiday seasons
        $this->trendRate = $this->getConfig('trend_rate', 0);
        $this->min = $this->getConfig('min');
        $this->max = $this->getConfig('max');

        $baseTimeStr = $this->getConfig('base_time');
        if ($baseTimeStr) {
            $this->baseTime = new \DateTime($baseTimeStr);
        } else {
            $this->baseTime = new \DateTime;
        }

        $this->parameters = [
            'base_value' => [
                'type' => 'float',
                'description' => 'Base value around which seasonal variation occurs',
                'default' => 100,
                'required' => false,
            ],
            'amplitude' => [
                'type' => 'float',
                'description' => 'Amplitude of seasonal variation',
                'default' => 20,
                'required' => false,
            ],
            'period' => [
                'type' => 'string',
                'description' => 'Period of seasonality (day, week, month, year)',
                'default' => 'year',
                'required' => false,
            ],
            'peaks' => [
                'type' => 'array',
                'description' => 'Peak periods (e.g., ["december", "july"] for year period)',
                'default' => ['december', 'july'],
                'required' => false,
            ],
            'trend_rate' => [
                'type' => 'float',
                'description' => 'Overall trend rate (growth/decline per period)',
                'default' => 0,
                'required' => false,
            ],
        ];
    }

    /**
     * Generate a value for the current time
     */
    public function generate(array $context = []): mixed
    {
        $timestamp = $context['timestamp'] ?? new \DateTime;

        return $this->generateAt($timestamp);
    }

    /**
     * Generate a value for a specific timestamp
     */
    public function generateAt(\DateTimeInterface $timestamp): mixed
    {
        if (! $this->baseTime) {
            $this->baseTime = new \DateTime;
        }

        // Calculate seasonal component
        $seasonalValue = $this->calculateSeasonalComponent($timestamp);

        // Calculate trend component
        $trendValue = $this->calculateTrendComponent($timestamp);

        // Combine components
        // If amplitude < 1, treat it as a percentage of base value
        $actualAmplitude = $this->amplitude < 1 ? $this->amplitude * $this->baseValue : $this->amplitude;
        $value = $this->baseValue + ($seasonalValue * $actualAmplitude / $this->amplitude) + $trendValue;

        // Add random noise (±2% instead of ±5% to make peaks more distinguishable)
        $noise = $this->faker->randomFloat(4, 0.98, 1.02);
        $value *= $noise;

        return $this->clamp($value, $this->min, $this->max);
    }

    /**
     * Alias for generateAt for backwards compatibility
     */
    public function generateForDate(\DateTimeInterface $date): mixed
    {
        return $this->generateAt($date);
    }

    /**
     * Calculate seasonal component based on timestamp
     */
    protected function calculateSeasonalComponent(\DateTimeInterface $timestamp): float
    {
        $position = $this->getPositionInPeriod($timestamp);

        // Calculate distance to nearest peak
        $minDistance = 1.0;
        foreach ($this->getPeakPositions() as $peakPosition) {
            $distance = min(
                abs($position - $peakPosition),
                1 - abs($position - $peakPosition) // Wrap-around distance
            );
            $minDistance = min($minDistance, $distance);
        }

        // Convert distance to seasonal value using cosine
        // 0 distance = peak (amplitude), 0.5 distance = trough (-amplitude)
        // We want: distance=0 => factor=1 (peak), distance=0.5 => factor=-1 (trough)
        $seasonalFactor = cos(2 * pi() * $minDistance);

        // Amplitude should be positive for peaks
        return $this->amplitude * $seasonalFactor;
    }

    /**
     * Calculate trend component
     */
    protected function calculateTrendComponent(\DateTimeInterface $timestamp): float
    {
        if ($this->trendRate == 0) {
            return 0;
        }

        $periods = $this->getPeriodsElapsed($this->baseTime, $timestamp);

        return $this->trendRate * $periods;
    }

    /**
     * Get position within period (0 to 1)
     */
    protected function getPositionInPeriod(\DateTimeInterface $timestamp): float
    {
        switch ($this->period) {
            case 'day':
                // Position within 24 hours
                return ($timestamp->format('H') * 3600 + $timestamp->format('i') * 60 + $timestamp->format('s')) / 86400;

            case 'week':
                // Position within 7 days (0 = Monday)
                $dayOfWeek = ($timestamp->format('N') - 1) / 7;
                $timeOfDay = ($timestamp->format('H') * 3600 + $timestamp->format('i') * 60) / 86400;

                return $dayOfWeek + ($timeOfDay / 7);

            case 'month':
                // Position within month
                $dayOfMonth = $timestamp->format('j');
                $daysInMonth = $timestamp->format('t');

                return ($dayOfMonth - 1) / $daysInMonth;

            case 'year':
            default:
                // Position within year
                $dayOfYear = $timestamp->format('z');
                $daysInYear = $timestamp->format('L') ? 366 : 365;

                return $dayOfYear / $daysInYear;
        }
    }

    /**
     * Get peak positions within period (0 to 1)
     */
    protected function getPeakPositions(): array
    {
        $positions = [];

        foreach ($this->peaks as $peak) {
            switch ($this->period) {
                case 'day':
                    // Peak as hour (0-23)
                    if (is_numeric($peak)) {
                        $positions[] = $peak / 24;
                    }
                    break;

                case 'week':
                    // Peak as day name or number
                    $days = ['monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3,
                        'friday' => 4, 'saturday' => 5, 'sunday' => 6];
                    if (isset($days[strtolower($peak)])) {
                        $positions[] = $days[strtolower($peak)] / 7;
                    } elseif (is_numeric($peak)) {
                        $positions[] = ($peak - 1) / 7;
                    }
                    break;

                case 'month':
                    // Peak as day of month
                    if (is_numeric($peak)) {
                        $positions[] = ($peak - 1) / 30; // Approximate
                    }
                    break;

                case 'year':
                    // Peak as month name or number
                    $months = ['january' => 0, 'february' => 1, 'march' => 2, 'april' => 3,
                        'may' => 4, 'june' => 5, 'july' => 6, 'august' => 7,
                        'september' => 8, 'october' => 9, 'november' => 10, 'december' => 11];
                    if (isset($months[strtolower($peak)])) {
                        $positions[] = $months[strtolower($peak)] / 12;
                    } elseif (is_numeric($peak)) {
                        $positions[] = ($peak - 1) / 12;
                    }
                    break;
            }
        }

        return $positions ?: [0.5]; // Default to middle if no valid peaks
    }

    /**
     * Get number of periods elapsed
     */
    protected function getPeriodsElapsed(\DateTimeInterface $start, \DateTimeInterface $end): float
    {
        $diff = $end->getTimestamp() - $start->getTimestamp();

        switch ($this->period) {
            case 'day':
                return $diff / 86400;
            case 'week':
                return $diff / 604800;
            case 'month':
                return $diff / 2592000; // Approximate
            case 'year':
            default:
                return $diff / 31536000; // Approximate
        }
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
        return $this->period;
    }

    /**
     * Set the base time
     */
    public function setBaseTime(\DateTimeInterface $baseTime): void
    {
        $this->baseTime = $baseTime;
    }
}
