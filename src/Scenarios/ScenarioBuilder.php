<?php

namespace LaravelMint\Scenarios;

use LaravelMint\Mint;
use LaravelMint\Patterns\PatternRegistry;

class ScenarioBuilder
{
    protected Mint $mint;
    protected string $name;
    protected array $config = [];
    protected array $models = [];
    protected array $segments = [];
    protected array $timeline = [];
    protected array $behaviors = [];
    protected array $patterns = [];
    protected array $relationships = [];

    public function __construct(Mint $mint = null)
    {
        $this->mint = $mint ?? app('mint');
    }

    /**
     * Create a new scenario builder
     */
    public static function create(string $name): self
    {
        $builder = new self();
        $builder->name = $name;
        return $builder;
    }

    /**
     * Add users to the scenario
     */
    public function withUsers(int $count): self
    {
        $this->models['users'] = [
            'model' => 'App\\Models\\User',
            'count' => $count,
            'segments' => [],
        ];
        return $this;
    }

    /**
     * Add a user segment
     */
    public function segment(string $name, float $percentage, array $attributes = []): self
    {
        if (!isset($this->models['users'])) {
            throw new \RuntimeException('Must call withUsers() before adding segments');
        }

        $this->models['users']['segments'][$name] = [
            'percentage' => $percentage,
            'attributes' => $attributes,
            'patterns' => $attributes['patterns'] ?? [],
        ];

        return $this;
    }

    /**
     * Add products to the scenario
     */
    public function withProducts(int $count): self
    {
        $this->models['products'] = [
            'model' => 'App\\Models\\Product',
            'count' => $count,
            'categories' => [],
        ];
        return $this;
    }

    /**
     * Add a product category
     */
    public function category(string $name, float $percentage, array $attributes = []): self
    {
        if (!isset($this->models['products'])) {
            throw new \RuntimeException('Must call withProducts() before adding categories');
        }

        $this->models['products']['categories'][$name] = [
            'percentage' => $percentage,
            'attributes' => $attributes,
        ];

        return $this;
    }

    /**
     * Add orders to the scenario
     */
    public function withOrders(int $count): self
    {
        $this->models['orders'] = [
            'model' => 'App\\Models\\Order',
            'count' => $count,
        ];
        return $this;
    }

    /**
     * Set timeline for the scenario
     */
    public function withTimeline($start, $end): self
    {
        $this->timeline = [
            'start' => $start instanceof \DateTimeInterface ? $start : new \DateTime($start),
            'end' => $end instanceof \DateTimeInterface ? $end : new \DateTime($end),
            'pattern' => 'linear',
        ];
        return $this;
    }

    /**
     * Set traffic pattern for timeline
     */
    public function trafficPattern(string $pattern, array $config = []): self
    {
        if (empty($this->timeline)) {
            throw new \RuntimeException('Must call withTimeline() before setting traffic pattern');
        }

        $this->timeline['pattern'] = $pattern;
        $this->timeline['pattern_config'] = $config;
        return $this;
    }

    /**
     * Add a peak day
     */
    public function peakDay($date, float $multiplier = 2.0): self
    {
        if (empty($this->timeline)) {
            throw new \RuntimeException('Must call withTimeline() before adding peak days');
        }

        $this->timeline['peaks'][] = [
            'date' => $date instanceof \DateTimeInterface ? $date : new \DateTime($date),
            'multiplier' => $multiplier,
        ];
        return $this;
    }

    /**
     * Add a behavior pattern
     */
    public function withBehavior(string $behavior, float $rate, array $config = []): self
    {
        $this->behaviors[$behavior] = [
            'rate' => $rate,
            'config' => $config,
        ];
        return $this;
    }

    /**
     * Add a pattern for a specific field
     */
    public function withPattern(string $model, string $field, $pattern): self
    {
        if (!isset($this->patterns[$model])) {
            $this->patterns[$model] = [];
        }

        if (is_string($pattern)) {
            $this->patterns[$model][$field] = ['type' => $pattern];
        } elseif (is_array($pattern)) {
            $this->patterns[$model][$field] = $pattern;
        } else {
            throw new \InvalidArgumentException('Pattern must be string or array');
        }

        return $this;
    }

    /**
     * Add relationship configuration
     */
    public function withRelationship(string $from, string $to, string $type, array $config = []): self
    {
        $this->relationships[] = [
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'config' => $config,
        ];
        return $this;
    }

    /**
     * Set configuration value
     */
    public function set(string $key, $value): self
    {
        data_set($this->config, $key, $value);
        return $this;
    }

    /**
     * Build and return the scenario configuration
     */
    public function build(): array
    {
        return [
            'name' => $this->name,
            'models' => $this->models,
            'segments' => $this->segments,
            'timeline' => $this->timeline,
            'behaviors' => $this->behaviors,
            'patterns' => $this->patterns,
            'relationships' => $this->relationships,
            'config' => $this->config,
        ];
    }

    /**
     * Generate the scenario
     */
    public function generate(): ScenarioResult
    {
        $config = $this->build();
        $scenario = new CustomScenario($this->mint, $config);
        return $scenario->run();
    }

    /**
     * Save scenario configuration to file
     */
    public function save(string $path): bool
    {
        $config = $this->build();
        $json = json_encode($config, JSON_PRETTY_PRINT);
        return file_put_contents($path, $json) !== false;
    }

    /**
     * Load scenario configuration from file
     */
    public static function load(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Scenario file not found: {$path}");
        }

        $config = json_decode(file_get_contents($path), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON in scenario file: ' . json_last_error_msg());
        }

        $builder = new self();
        $builder->name = $config['name'] ?? 'unnamed';
        $builder->models = $config['models'] ?? [];
        $builder->segments = $config['segments'] ?? [];
        $builder->timeline = $config['timeline'] ?? [];
        $builder->behaviors = $config['behaviors'] ?? [];
        $builder->patterns = $config['patterns'] ?? [];
        $builder->relationships = $config['relationships'] ?? [];
        $builder->config = $config['config'] ?? [];

        return $builder;
    }
}

/**
 * Custom scenario created by builder
 */
class CustomScenario extends BaseScenario
{
    protected array $builderConfig;

    public function __construct(Mint $mint, array $config)
    {
        $this->builderConfig = $config;
        parent::__construct($mint);
    }

    protected function initialize(): void
    {
        $this->name = $this->builderConfig['name'] ?? 'Custom Scenario';
        $this->description = 'Scenario created with ScenarioBuilder';
        
        // Extract required models
        foreach ($this->builderConfig['models'] as $key => $modelConfig) {
            if (isset($modelConfig['model'])) {
                $this->requiredModels[] = $modelConfig['model'];
            }
        }
    }

    protected function execute(): void
    {
        // Generate each model type
        foreach ($this->builderConfig['models'] as $key => $modelConfig) {
            $this->generateModelWithConfig($modelConfig);
        }

        // Apply behaviors
        foreach ($this->builderConfig['behaviors'] as $behavior => $config) {
            $this->applyBehavior($behavior, $config);
        }
    }

    protected function generateModelWithConfig(array $modelConfig): void
    {
        $modelClass = $modelConfig['model'];
        $count = $modelConfig['count'];
        
        // Handle segments
        if (!empty($modelConfig['segments'])) {
            $this->generateWithSegments($modelClass, $count, $modelConfig['segments']);
        }
        // Handle categories
        elseif (!empty($modelConfig['categories'])) {
            $this->generateWithCategories($modelClass, $count, $modelConfig['categories']);
        }
        // Simple generation
        else {
            $options = $this->getGenerationOptions();
            
            // Apply patterns if configured
            if (isset($this->builderConfig['patterns'][class_basename($modelClass)])) {
                $options['column_patterns'] = $this->builderConfig['patterns'][class_basename($modelClass)];
            }
            
            $this->generateModel($modelClass, $count, $options);
        }
    }

    protected function generateWithSegments(string $modelClass, int $totalCount, array $segments): void
    {
        foreach ($segments as $name => $segment) {
            $segmentCount = (int)($totalCount * $segment['percentage']);
            
            $options = $this->getGenerationOptions();
            $options['segment'] = $name;
            
            // Apply segment-specific patterns
            if (!empty($segment['patterns'])) {
                $options['column_patterns'] = $segment['patterns'];
            }
            
            // Apply segment attributes as overrides
            if (!empty($segment['attributes'])) {
                $options['overrides'] = $segment['attributes'];
            }
            
            $this->generateModel($modelClass, $segmentCount, $options);
        }
    }

    protected function generateWithCategories(string $modelClass, int $totalCount, array $categories): void
    {
        foreach ($categories as $name => $category) {
            $categoryCount = (int)($totalCount * $category['percentage']);
            
            $options = $this->getGenerationOptions();
            $options['category'] = $name;
            
            // Apply category attributes
            if (!empty($category['attributes'])) {
                $options['overrides'] = $category['attributes'];
            }
            
            $this->generateModel($modelClass, $categoryCount, $options);
        }
    }

    protected function applyBehavior(string $behavior, array $config): void
    {
        $rate = $config['rate'];
        
        switch ($behavior) {
            case 'cart_abandonment':
                $this->applyCartAbandonment($rate);
                break;
            case 'repeat_purchase':
                $this->applyRepeatPurchase($rate);
                break;
            case 'review_generation':
                $this->applyReviewGeneration($rate);
                break;
            default:
                // Custom behavior
                $this->result->addStatistic($behavior, $rate);
        }
    }

    protected function applyCartAbandonment(float $rate): void
    {
        // Implementation would mark some orders as abandoned
        $this->result->addStatistic('cart_abandonment_rate', $rate);
    }

    protected function applyRepeatPurchase(float $rate): void
    {
        // Implementation would create repeat orders for some users
        $this->result->addStatistic('repeat_purchase_rate', $rate);
    }

    protected function applyReviewGeneration(float $rate): void
    {
        // Implementation would generate reviews for some orders
        $this->result->addStatistic('review_generation_rate', $rate);
    }
}