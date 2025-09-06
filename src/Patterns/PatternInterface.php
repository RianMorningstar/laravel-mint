<?php

namespace LaravelMint\Patterns;

interface PatternInterface
{
    /**
     * Generate a value based on the pattern
     *
     * @param array $context Additional context for generation
     * @return mixed
     */
    public function generate(array $context = []): mixed;

    /**
     * Validate pattern configuration
     *
     * @param array $config Pattern configuration
     * @return bool
     */
    public function validate(array $config): bool;

    /**
     * Get pattern parameters and their descriptions
     *
     * @return array
     */
    public function getParameters(): array;

    /**
     * Get pattern name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get pattern description
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Set pattern configuration
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void;

    /**
     * Reset pattern state (for patterns that maintain state)
     *
     * @return void
     */
    public function reset(): void;
}