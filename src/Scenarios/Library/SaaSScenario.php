<?php

namespace LaravelMint\Scenarios\Library;

use LaravelMint\Scenarios\BaseScenario;

class SaaSScenario extends BaseScenario
{
    protected string $name = 'saas';
    
    protected string $description = 'SaaS Application scenario with users, subscriptions, plans, and billing';
    
    protected function defineSteps(): array
    {
        return [
            [
                'model' => 'User',
                'count' => 100,
                'attributes' => [],
            ],
            [
                'model' => 'Plan',
                'count' => 5,
                'attributes' => [
                    'is_active' => true,
                ],
            ],
            [
                'model' => 'Subscription',
                'count' => 80,
                'relationships' => ['user', 'plan'],
            ],
            [
                'model' => 'Team',
                'count' => 30,
                'relationships' => ['owner'],
            ],
            [
                'model' => 'Invoice',
                'count' => 240,
                'relationships' => ['subscription', 'user'],
            ],
            [
                'model' => 'Feature',
                'count' => 20,
                'relationships' => ['plans'],
            ],
        ];
    }
    
    protected function initialize(): void
    {
        // Initialize SaaS specific settings
        $this->options['include_trials'] = $this->options['include_trials'] ?? true;
        $this->options['include_teams'] = $this->options['include_teams'] ?? true;
    }
    
    protected function execute(): void
    {
        foreach ($this->defineSteps() as $step) {
            $model = $step['model'];
            $count = $step['count'] * ($this->options['scale'] ?? 1);
            $attributes = $step['attributes'] ?? [];
            
            // Generate the data
            $generated = $this->mint->generate(
                'App\\Models\\' . $model,
                $count,
                $attributes
            );
            
            $this->generatedData[$model] = $generated;
        }
    }
}