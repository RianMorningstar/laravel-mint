<?php

namespace LaravelMint\Patterns;

class CompositePattern extends AbstractPattern
{
    protected array $patterns = [];

    protected array $weights = [];

    protected string $mode = 'combine'; // combine, select, sequence

    protected string $combination = 'additive'; // additive, multiplicative

    public function __construct($config = [], array $patterns = [])
    {
        // Handle both old and new constructor signatures
        if (is_array($config)) {
            if (isset($config['patterns'])) {
                // New style: config array with patterns inside
                $this->patterns = $config['patterns'];
                $this->weights = $config['weights'] ?? [];
                $this->combination = $config['combination'] ?? 'additive';
                parent::__construct($config);
            } else {
                // Old style: patterns as first arg, config as second
                $this->patterns = $config;
                parent::__construct($patterns);
            }
        }
    }

    protected function initialize(): void
    {
        $this->name = 'Composite Pattern';
        $this->description = 'Combines multiple patterns';

        $this->mode = $this->getConfig('mode', 'combine');
        $this->combination = $this->getConfig('combination', $this->combination);
        $this->weights = $this->getConfig('weights', $this->weights);

        $this->parameters = [
            'mode' => [
                'type' => 'string',
                'description' => 'Combination mode (combine, select, sequence)',
                'default' => 'combine',
                'required' => false,
            ],
            'weights' => [
                'type' => 'array',
                'description' => 'Weights for pattern selection (select mode)',
                'default' => [],
                'required' => false,
            ],
            'combination' => [
                'type' => 'string',
                'description' => 'How to combine patterns (additive, multiplicative)',
                'default' => 'additive',
                'required' => false,
            ],
        ];
    }

    /**
     * Generate value based on mode
     */
    public function generate(array $context = []): mixed
    {
        switch ($this->mode) {
            case 'select':
                return $this->generateSelect($context);

            case 'sequence':
                return $this->generateSequence($context);

            case 'combine':
            default:
                return $this->generateCombine($context);
        }
    }

    /**
     * Generate for a specific date using temporal patterns
     */
    public function generateForDate(\DateTimeInterface $date): mixed
    {
        $context = ['timestamp' => $date];

        if ($this->combination === 'multiplicative') {
            $result = 1.0;
            foreach ($this->patterns as $i => $pattern) {
                $weight = $this->weights[$i] ?? 1.0;
                if ($pattern instanceof Temporal\TemporalPatternInterface) {
                    $value = $pattern->generateAt($date);
                } else {
                    $value = $pattern->generate($context);
                }
                $result *= pow($value, $weight);
            }

            return $result;
        } else {
            // Additive combination
            $result = 0;
            foreach ($this->patterns as $i => $pattern) {
                $weight = $this->weights[$i] ?? 1.0;
                if ($pattern instanceof Temporal\TemporalPatternInterface) {
                    $value = $pattern->generateAt($date);
                } else {
                    $value = $pattern->generate($context);
                }
                $result += $value * $weight;
            }

            return $result;
        }
    }

    /**
     * Combine values from all patterns
     */
    protected function generateCombine(array $context): mixed
    {
        if ($this->combination === 'multiplicative') {
            $result = 1.0;
            foreach ($this->patterns as $i => $pattern) {
                $weight = $this->weights[$i] ?? 1.0;
                $value = $pattern->generate($context);
                $result *= pow($value, $weight);
            }

            return $result;
        } else {
            // Additive combination
            $result = 0;
            foreach ($this->patterns as $i => $pattern) {
                $weight = $this->weights[$i] ?? 1.0;
                $value = $pattern->generate($context);
                $result += $value * $weight;
            }

            return $result;
        }
    }

    /**
     * Select one pattern based on weights
     */
    protected function generateSelect(array $context): mixed
    {
        $weights = $this->getConfig('weights', []);

        if (empty($weights)) {
            // Equal weights
            $selected = $this->faker->randomElement($this->patterns);
        } else {
            // Weighted selection
            $selected = $this->weightedSelect($this->patterns, $weights);
        }

        return $selected->generate($context);
    }

    /**
     * Generate values in sequence
     */
    protected function generateSequence(array $context): array
    {
        static $index = 0;

        $patternArray = array_values($this->patterns);
        $pattern = $patternArray[$index % count($patternArray)];
        $index++;

        return $pattern->generate($context);
    }

    /**
     * Weighted random selection
     */
    protected function weightedSelect(array $patterns, array $weights): PatternInterface
    {
        $totalWeight = array_sum($weights);
        $random = $this->faker->randomFloat(6, 0, $totalWeight);

        $cumulative = 0;
        foreach ($patterns as $name => $pattern) {
            $weight = $weights[$name] ?? 1;
            $cumulative += $weight;

            if ($random <= $cumulative) {
                return $pattern;
            }
        }

        // Fallback to last pattern
        return end($patterns);
    }

    /**
     * Get all patterns
     */
    public function getPatterns(): array
    {
        return $this->patterns;
    }

    /**
     * Add a pattern
     */
    public function addPattern(string $name, PatternInterface $pattern): void
    {
        $this->patterns[$name] = $pattern;
    }

    /**
     * Remove a pattern
     */
    public function removePattern(string $name): void
    {
        unset($this->patterns[$name]);
    }

    /**
     * Reset all patterns
     */
    public function reset(): void
    {
        foreach ($this->patterns as $pattern) {
            $pattern->reset();
        }
    }
}
