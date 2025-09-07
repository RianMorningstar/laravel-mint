<?php

namespace LaravelMint\Scenarios\Library;

use LaravelMint\Scenarios\BaseScenario;

class EcommerceScenario extends BaseScenario
{
    protected string $name = 'ecommerce';

    protected string $description = 'E-commerce scenario with users, products, orders, and payments';

    protected function defineSteps(): array
    {
        return [
            [
                'model' => 'User',
                'count' => 50,
                'attributes' => [],
            ],
            [
                'model' => 'Product',
                'count' => 100,
                'attributes' => [
                    'is_active' => true,
                ],
            ],
            [
                'model' => 'Category',
                'count' => 10,
                'attributes' => [],
            ],
            [
                'model' => 'Order',
                'count' => 200,
                'relationships' => ['user', 'products'],
            ],
        ];
    }

    protected function initialize(): void
    {
        // Initialize e-commerce specific settings
        $this->options['include_reviews'] = $this->options['include_reviews'] ?? true;
        $this->options['include_cart'] = $this->options['include_cart'] ?? true;
    }

    protected function execute(): void
    {
        foreach ($this->defineSteps() as $step) {
            $model = $step['model'];
            $count = $step['count'] * ($this->options['scale'] ?? 1);
            $attributes = $step['attributes'] ?? [];

            // Generate the data
            $generated = $this->mint->generate(
                'App\\Models\\'.$model,
                $count,
                $attributes
            );

            $this->generatedData[$model] = $generated;
        }
    }
}
