<?php

namespace LaravelMint\Patterns;

interface PatternInterface
{
    /**
     * Generate a value based on the pattern
     *
     * @param  array  $context  Additional context for generation
     */
    public function generate(array $context = []): mixed;

    /**
     * Validate pattern configuration
     *
     * @param  array  $config  Pattern configuration
     */
    public function validate(array $config): bool;

    /**
     * Get pattern parameters and their descriptions
     */
    public function getParameters(): array;

    /**
     * Get pattern name
     */
    public function getName(): string;

    /**
     * Get pattern description
     */
    public function getDescription(): string;

    /**
     * Set pattern configuration
     */
    public function setConfig(array $config): void;

    /**
     * Reset pattern state (for patterns that maintain state)
     */
    public function reset(): void;
}
