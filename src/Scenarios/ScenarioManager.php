<?php

namespace LaravelMint\Scenarios;

use LaravelMint\Mint;

class ScenarioManager
{
    protected Mint $mint;

    protected array $scenarios = [];

    public function __construct(Mint $mint, bool $loadBuiltIn = true)
    {
        $this->mint = $mint;
        if ($loadBuiltIn) {
            $this->loadScenarios();
        }
    }

    /**
     * Load available scenarios
     */
    protected function loadScenarios(): void
    {
        // Load built-in scenarios
        $this->scenarios = [
            'simple' => [
                'name' => 'Simple',
                'description' => 'Basic data generation with minimal relationships',
                'class' => null, // Will use SimpleGenerator directly
            ],
            'ecommerce' => [
                'name' => 'E-commerce',
                'description' => 'E-commerce scenario with users, products, orders',
                'class' => 'LaravelMint\\Scenarios\\Library\\EcommerceScenario',
            ],
            'e-commerce' => [
                'name' => 'E-commerce',
                'description' => 'E-commerce scenario with users, products, orders',
                'class' => 'LaravelMint\\Scenarios\\Library\\EcommerceScenario',
            ],
            'saas' => [
                'name' => 'SaaS',
                'description' => 'SaaS scenario with subscriptions and usage patterns',
                'class' => 'LaravelMint\\Scenarios\\Library\\SaasScenario',
            ],
            'social' => [
                'name' => 'Social Media',
                'description' => 'Social media scenario with posts, comments, likes',
                'class' => 'LaravelMint\\Scenarios\\Library\\SocialScenario',
            ],
        ];

        // Load custom scenarios from configuration
        if ($this->mint->getConfig('scenarios.auto_discover')) {
            $this->discoverCustomScenarios();
        }
    }

    /**
     * Discover custom scenarios
     */
    protected function discoverCustomScenarios(): void
    {
        $scenarioPath = $this->mint->getConfig('scenarios.path');

        if (! is_dir($scenarioPath)) {
            return;
        }

        // TODO: Implement scenario discovery from files
    }

    /**
     * Run a scenario
     */
    public function run(string $scenario, array $options = []): ?ScenarioResult
    {
        if (! isset($this->scenarios[$scenario])) {
            throw new \InvalidArgumentException("Scenario '{$scenario}' not found");
        }

        $scenarioConfig = $this->scenarios[$scenario];

        // Handle scenario objects directly
        if (is_object($scenarioConfig)) {
            if ($scenarioConfig instanceof ScenarioInterface) {
                return $scenarioConfig->run($options);
            }
            throw new \InvalidArgumentException('Invalid scenario object');
        }

        // Handle scenario objects stored in 'instance' key
        if (isset($scenarioConfig['instance']) && $scenarioConfig['instance'] instanceof ScenarioInterface) {
            return $scenarioConfig['instance']->run($options);
        }

        // Handle scenario config arrays
        if (isset($scenarioConfig['class']) && class_exists($scenarioConfig['class'])) {
            $scenarioInstance = new $scenarioConfig['class']($this->mint);

            return $scenarioInstance->run($options);
        } else {
            // Default simple scenario
            return $this->runSimpleScenario($options);
        }
    }

    /**
     * Run simple scenario
     */
    protected function runSimpleScenario(array $options): ScenarioResult
    {
        // This is a placeholder - in a real implementation,
        // this would analyze all models and generate data in proper order
        $models = $options['models'] ?? [];
        $count = $options['count'] ?? 10;
        $recordsCreated = 0;
        $startTime = microtime(true);

        foreach ($models as $model) {
            $this->mint->generate($model, $count, $options);
            $recordsCreated += $count;
        }

        $duration = microtime(true) - $startTime;

        return new ScenarioResult(true, [
            'records_created' => $recordsCreated,
            'duration' => $duration,
        ]);
    }

    /**
     * Get available scenarios
     */
    public function getAvailableScenarios(): array
    {
        return $this->scenarios;
    }

    /**
     * Register a custom scenario
     */
    public function registerScenario(string $key, array $config): void
    {
        $this->scenarios[$key] = $config;
    }

    /**
     * Register a new scenario (alias for registerScenario for compatibility)
     */
    public function register(string $name, $scenario): void
    {
        if (is_object($scenario)) {
            $this->scenarios[$name] = [
                'name' => $name,
                'description' => method_exists($scenario, 'getDescription') ? $scenario->getDescription() : '',
                'instance' => $scenario,
            ];
        } else {
            $this->scenarios[$name] = $scenario;
        }
    }

    /**
     * Load scenarios from config
     */
    public function loadFromConfig(): void
    {
        $config = config('mint.scenarios', []);

        foreach ($config as $key => $scenarioConfig) {
            if (! isset($scenarioConfig['enabled']) || ! $scenarioConfig['enabled']) {
                continue;
            }

            if (isset($scenarioConfig['class'])) {
                $class = $scenarioConfig['class'];
                if (class_exists($class)) {
                    $instance = new $class;
                    $this->register($key, $instance);
                }
            } else {
                $this->register($key, $scenarioConfig);
            }
        }
    }

    /**
     * Check if a scenario exists
     */
    public function has(string $name): bool
    {
        return isset($this->scenarios[$name]);
    }

    /**
     * Get a scenario
     */
    public function get(string $name): ?ScenarioInterface
    {
        if (! $this->has($name)) {
            return null;
        }

        $scenario = $this->scenarios[$name];

        // If it's already a scenario object, return it
        if (is_object($scenario) && $scenario instanceof ScenarioInterface) {
            return $scenario;
        }

        if (isset($scenario['instance'])) {
            return $scenario['instance'];
        }

        if (isset($scenario['class']) && class_exists($scenario['class'])) {
            return new $scenario['class']($this->mint);
        }

        return null;
    }

    /**
     * List all scenarios
     */
    public function list(): array
    {
        $list = [];

        foreach ($this->scenarios as $name => $scenario) {
            if (is_object($scenario)) {
                // Handle scenario objects
                $list[$name] = [
                    'name' => method_exists($scenario, 'getName') ? $scenario->getName() : $name,
                    'description' => method_exists($scenario, 'getDescription') ? $scenario->getDescription() : '',
                ];
            } else {
                // Handle scenario config arrays
                $list[$name] = [
                    'name' => $scenario['name'] ?? $name,
                    'description' => $scenario['description'] ?? '',
                ];
            }
        }

        return $list;
    }
}
