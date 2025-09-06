<?php

namespace LaravelMint\Patterns;

class CompositePattern extends AbstractPattern
{
    protected array $patterns = [];
    protected string $mode = 'combine'; // combine, select, sequence

    public function __construct(array $patterns, array $config = [])
    {
        $this->patterns = $patterns;
        parent::__construct($config);
    }

    protected function initialize(): void
    {
        $this->name = 'Composite Pattern';
        $this->description = 'Combines multiple patterns';
        
        $this->mode = $this->getConfig('mode', 'combine');
        
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
     * Combine values from all patterns
     */
    protected function generateCombine(array $context): array
    {
        $result = [];
        
        foreach ($this->patterns as $name => $pattern) {
            $result[$name] = $pattern->generate($context);
        }
        
        return $result;
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