<?php

namespace LaravelMint\Scenarios;

interface ScenarioInterface
{
    /**
     * Get the scenario name
     */
    public function getName(): string;

    /**
     * Get the scenario description
     */
    public function getDescription(): string;

    /**
     * Get required models for this scenario
     */
    public function getRequiredModels(): array;

    /**
     * Get optional models for this scenario
     */
    public function getOptionalModels(): array;

    /**
     * Get scenario parameters
     */
    public function getParameters(): array;

    /**
     * Set scenario options
     */
    public function setOptions(array $options): void;

    /**
     * Validate the scenario can run
     */
    public function validate(): bool;

    /**
     * Get validation errors
     */
    public function getValidationErrors(): array;

    /**
     * Run the scenario
     */
    public function run(array $options = []): ScenarioResult;

    /**
     * Get default configuration
     */
    public function getDefaultConfig(): array;

    /**
     * Estimate time and resources
     */
    public function estimate(): array;
}