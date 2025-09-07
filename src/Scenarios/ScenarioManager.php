<?php

namespace LaravelMint\Scenarios;

use LaravelMint\Mint;

class ScenarioManager
{
    protected Mint $mint;

    protected array $scenarios = [];

    public function __construct(Mint $mint)
    {
        $this->mint = $mint;
        $this->loadScenarios();
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
    public function run(string $scenario, array $options = []): void
    {
        if (! isset($this->scenarios[$scenario])) {
            throw new \InvalidArgumentException("Scenario '{$scenario}' not found");
        }

        $scenarioConfig = $this->scenarios[$scenario];

        if ($scenarioConfig['class'] && class_exists($scenarioConfig['class'])) {
            $scenarioInstance = new $scenarioConfig['class']($this->mint);
            $scenarioInstance->run($options);
        } else {
            // Default simple scenario
            $this->runSimpleScenario($options);
        }
    }

    /**
     * Run simple scenario
     */
    protected function runSimpleScenario(array $options): void
    {
        // This is a placeholder - in a real implementation,
        // this would analyze all models and generate data in proper order
        $models = $options['models'] ?? [];
        $count = $options['count'] ?? 10;

        foreach ($models as $model) {
            $this->mint->generate($model, $count, $options);
        }
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
        if (!$this->has($name)) {
            return null;
        }

        $scenario = $this->scenarios[$name];
        
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
        return array_keys($this->scenarios);
    }

    /**
     * Load scenarios from configuration
     */
    public function loadFromConfig(): void
    {
        $configScenarios = $this->mint->getConfig('scenarios', []);
        
        foreach ($configScenarios as $name => $config) {
            if (isset($config['enabled']) && !$config['enabled']) {
                continue;
            }
            
            if (isset($config['class']) && class_exists($config['class'])) {
                $this->scenarios[$name] = $config;
            }
        }
    }
}
