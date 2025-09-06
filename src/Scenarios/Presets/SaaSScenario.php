<?php

namespace LaravelMint\Scenarios\Presets;

use LaravelMint\Scenarios\BaseScenario;

class SaaSScenario extends BaseScenario
{
    protected function initialize(): void
    {
        $this->name = 'SaaS Application';
        $this->description = 'Generates realistic SaaS data including organizations, teams, subscriptions, and usage metrics';
        
        $this->requiredModels = [
            'App\Models\User',
            'App\Models\Organization',
            'App\Models\Subscription',
        ];
        
        $this->optionalModels = [
            'App\Models\Team',
            'App\Models\Plan',
            'App\Models\Invoice',
            'App\Models\ApiKey',
            'App\Models\UsageMetric',
            'App\Models\AuditLog',
        ];
        
        $this->parameters = [
            'organization_count' => [
                'type' => 'integer',
                'default' => 100,
                'min' => 10,
                'max' => 10000,
                'description' => 'Number of organizations to generate',
            ],
            'users_per_org' => [
                'type' => 'array',
                'default' => ['min' => 1, 'max' => 50],
                'description' => 'Range of users per organization',
            ],
            'time_period' => [
                'type' => 'integer',
                'default' => 365,
                'min' => 30,
                'max' => 1095,
                'description' => 'Time period in days for data distribution',
            ],
            'churn_rate' => [
                'type' => 'float',
                'default' => 0.05,
                'min' => 0,
                'max' => 0.5,
                'description' => 'Monthly churn rate',
            ],
            'growth_rate' => [
                'type' => 'float',
                'default' => 0.1,
                'min' => -0.5,
                'max' => 1.0,
                'description' => 'Monthly growth rate',
            ],
            'trial_conversion_rate' => [
                'type' => 'float',
                'default' => 0.15,
                'min' => 0,
                'max' => 1,
                'description' => 'Trial to paid conversion rate',
            ],
            'api_usage_enabled' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Generate API usage data',
            ],
        ];
    }

    protected function execute(): void
    {
        $orgCount = $this->getConfig('organization_count', 100);
        $timePeriod = $this->getConfig('time_period', 365);
        
        // Step 1: Generate subscription plans
        if (class_exists('App\Models\Plan')) {
            $this->generatePlans();
        }
        
        // Step 2: Generate organizations with growth pattern
        $this->generateOrganizations($orgCount, $timePeriod);
        
        // Step 3: Generate users for each organization
        $this->generateUsersForOrganizations();
        
        // Step 4: Generate teams within organizations
        if (class_exists('App\Models\Team')) {
            $this->generateTeams();
        }
        
        // Step 5: Generate subscriptions with churn
        $this->generateSubscriptions();
        
        // Step 6: Generate invoices
        if (class_exists('App\Models\Invoice')) {
            $this->generateInvoices();
        }
        
        // Step 7: Generate API keys and usage
        if ($this->getConfig('api_usage_enabled', true)) {
            if (class_exists('App\Models\ApiKey')) {
                $this->generateApiKeys();
            }
            
            if (class_exists('App\Models\UsageMetric')) {
                $this->generateUsageMetrics();
            }
        }
        
        // Step 8: Generate audit logs
        if (class_exists('App\Models\AuditLog')) {
            $this->generateAuditLogs();
        }
    }

    protected function generatePlans(): void
    {
        $this->logProgress('Generating subscription plans...');
        
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'price' => 0,
                'interval' => 'month',
                'features' => json_encode([
                    'users' => 1,
                    'projects' => 1,
                    'storage' => '1GB',
                    'api_calls' => 1000,
                ]),
                'is_active' => true,
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'price' => 29,
                'interval' => 'month',
                'features' => json_encode([
                    'users' => 5,
                    'projects' => 10,
                    'storage' => '10GB',
                    'api_calls' => 10000,
                    'priority_support' => false,
                ]),
                'is_active' => true,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'price' => 99,
                'interval' => 'month',
                'features' => json_encode([
                    'users' => 20,
                    'projects' => 50,
                    'storage' => '100GB',
                    'api_calls' => 100000,
                    'priority_support' => true,
                    'custom_domain' => true,
                ]),
                'is_active' => true,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'price' => 499,
                'interval' => 'month',
                'features' => json_encode([
                    'users' => 'unlimited',
                    'projects' => 'unlimited',
                    'storage' => '1TB',
                    'api_calls' => 'unlimited',
                    'priority_support' => true,
                    'custom_domain' => true,
                    'sso' => true,
                    'dedicated_account_manager' => true,
                ]),
                'is_active' => true,
            ],
        ];
        
        foreach ($plans as $plan) {
            \App\Models\Plan::create($plan);
        }
        
        $this->result->addGenerated('App\Models\Plan', count($plans));
    }

    protected function generateOrganizations(int $count, int $timePeriod): void
    {
        $this->logProgress('Generating organizations with growth pattern...');
        
        $growthRate = $this->getConfig('growth_rate', 0.1);
        $startDate = now()->subDays($timePeriod);
        
        // Apply exponential growth pattern
        $options = [
            'column_patterns' => [
                'created_at' => [
                    'type' => 'temporal_growth',
                    'start' => $startDate,
                    'end' => now(),
                    'growth_rate' => $growthRate,
                ],
                'size' => [
                    'type' => 'pareto',
                    'xmin' => 1,
                    'alpha' => 1.5,
                ],
            ],
            'overrides' => [
                'name' => fn($i) => fake()->company() . ' ' . $i,
                'slug' => fn($i) => fake()->slug() . '-' . $i,
                'is_active' => fn() => rand(1, 100) <= 95, // 95% active
            ],
        ];
        
        // Generate organizations in segments
        // Enterprise (5%)
        $enterpriseCount = (int)($count * 0.05);
        $this->generateModel('App\Models\Organization', $enterpriseCount, array_merge($options, [
            'segment' => 'enterprise',
            'overrides' => array_merge($options['overrides'], [
                'plan' => 'enterprise',
                'employee_count' => fn() => rand(100, 5000),
            ]),
        ]));
        
        // Professional (15%)
        $professionalCount = (int)($count * 0.15);
        $this->generateModel('App\Models\Organization', $professionalCount, array_merge($options, [
            'segment' => 'professional',
            'overrides' => array_merge($options['overrides'], [
                'plan' => 'professional',
                'employee_count' => fn() => rand(20, 100),
            ]),
        ]));
        
        // Starter (30%)
        $starterCount = (int)($count * 0.30);
        $this->generateModel('App\Models\Organization', $starterCount, array_merge($options, [
            'segment' => 'starter',
            'overrides' => array_merge($options['overrides'], [
                'plan' => 'starter',
                'employee_count' => fn() => rand(5, 20),
            ]),
        ]));
        
        // Free (50%)
        $freeCount = $count - $enterpriseCount - $professionalCount - $starterCount;
        $this->generateModel('App\Models\Organization', $freeCount, array_merge($options, [
            'segment' => 'free',
            'overrides' => array_merge($options['overrides'], [
                'plan' => 'free',
                'employee_count' => fn() => rand(1, 5),
            ]),
        ]));
        
        $this->result->addStatistic('organization_segments', [
            'enterprise' => $enterpriseCount,
            'professional' => $professionalCount,
            'starter' => $starterCount,
            'free' => $freeCount,
        ]);
    }

    protected function generateUsersForOrganizations(): void
    {
        $this->logProgress('Generating users for organizations...');
        
        $organizations = \App\Models\Organization::all();
        $usersPerOrg = $this->getConfig('users_per_org', ['min' => 1, 'max' => 50]);
        $totalUsers = 0;
        
        foreach ($organizations as $org) {
            // User count based on plan
            $userCount = match($org->plan ?? 'free') {
                'enterprise' => rand(50, $usersPerOrg['max'] * 2),
                'professional' => rand(10, $usersPerOrg['max']),
                'starter' => rand(3, 10),
                'free' => rand($usersPerOrg['min'], 3),
                default => rand($usersPerOrg['min'], $usersPerOrg['max']),
            };
            
            for ($i = 0; $i < $userCount; $i++) {
                $role = $i === 0 ? 'owner' : ($i <= 2 ? 'admin' : 'member');
                
                \App\Models\User::create([
                    'name' => fake()->name(),
                    'email' => fake()->unique()->safeEmail(),
                    'password' => bcrypt('password'),
                    'organization_id' => $org->id,
                    'role' => $role,
                    'is_active' => rand(1, 100) <= 90, // 90% active users
                    'last_login_at' => $this->generateLastLogin($org->created_at),
                    'created_at' => $this->generateUserJoinDate($org->created_at),
                ]);
                
                $totalUsers++;
            }
        }
        
        $this->result->addGenerated('App\Models\User', $totalUsers);
    }

    protected function generateTeams(): void
    {
        $this->logProgress('Generating teams...');
        
        $organizations = \App\Models\Organization::whereIn('plan', ['professional', 'enterprise'])->get();
        $totalTeams = 0;
        
        foreach ($organizations as $org) {
            $teamCount = match($org->plan) {
                'enterprise' => rand(5, 20),
                'professional' => rand(2, 5),
                default => 1,
            };
            
            $users = \App\Models\User::where('organization_id', $org->id)->get();
            
            for ($i = 0; $i < $teamCount; $i++) {
                $team = \App\Models\Team::create([
                    'name' => fake()->randomElement(['Engineering', 'Marketing', 'Sales', 'Support', 'Product']) . ' Team ' . ($i + 1),
                    'organization_id' => $org->id,
                    'description' => fake()->sentence(),
                    'created_at' => $org->created_at->addDays(rand(1, 30)),
                ]);
                
                // Assign users to teams
                $teamSize = min(rand(2, 10), $users->count());
                $teamUsers = $users->random($teamSize);
                
                foreach ($teamUsers as $user) {
                    // Assuming pivot table exists
                    $team->users()->attach($user->id, [
                        'role' => fake()->randomElement(['lead', 'member']),
                        'joined_at' => $team->created_at->addDays(rand(0, 7)),
                    ]);
                }
                
                $totalTeams++;
            }
        }
        
        $this->result->addGenerated('App\Models\Team', $totalTeams);
    }

    protected function generateSubscriptions(): void
    {
        $this->logProgress('Generating subscriptions with churn...');
        
        $organizations = \App\Models\Organization::all();
        $churnRate = $this->getConfig('churn_rate', 0.05);
        $trialConversionRate = $this->getConfig('trial_conversion_rate', 0.15);
        
        $plans = class_exists('App\Models\Plan') 
            ? \App\Models\Plan::all()->keyBy('slug')
            : collect();
        
        foreach ($organizations as $org) {
            if ($org->plan === 'free') {
                continue; // Skip free plan organizations
            }
            
            $plan = $plans->get($org->plan);
            $planId = $plan ? $plan->id : null;
            
            // Determine subscription status
            $status = $this->determineSubscriptionStatus($org->created_at, $churnRate, $trialConversionRate);
            
            $subscription = \App\Models\Subscription::create([
                'organization_id' => $org->id,
                'plan_id' => $planId,
                'status' => $status,
                'trial_ends_at' => $status === 'trialing' ? now()->addDays(14) : null,
                'ends_at' => $status === 'cancelled' ? now()->subDays(rand(1, 30)) : null,
                'quantity' => \App\Models\User::where('organization_id', $org->id)->count(),
                'stripe_id' => 'sub_' . fake()->unique()->lexify('????????????????'),
                'created_at' => $org->created_at->addDays(rand(0, 7)),
            ]);
            
            // Add subscription events (upgrades, downgrades)
            if ($status === 'active' && rand(1, 100) <= 20) { // 20% have plan changes
                $this->generateSubscriptionEvents($subscription);
            }
        }
        
        $subscriptionCount = \App\Models\Subscription::count();
        $this->result->addGenerated('App\Models\Subscription', $subscriptionCount);
        
        // Calculate MRR
        $mrr = $this->calculateMRR();
        $this->result->addStatistic('monthly_recurring_revenue', $mrr);
    }

    protected function generateInvoices(): void
    {
        $this->logProgress('Generating invoices...');
        
        $subscriptions = \App\Models\Subscription::where('status', 'active')->get();
        $totalInvoices = 0;
        
        foreach ($subscriptions as $subscription) {
            $monthsActive = $subscription->created_at->diffInMonths(now());
            
            for ($i = 0; $i < $monthsActive; $i++) {
                $invoiceDate = $subscription->created_at->copy()->addMonths($i);
                
                \App\Models\Invoice::create([
                    'organization_id' => $subscription->organization_id,
                    'subscription_id' => $subscription->id,
                    'amount' => $this->calculateInvoiceAmount($subscription),
                    'status' => $this->generateInvoiceStatus(),
                    'due_date' => $invoiceDate->copy()->addDays(30),
                    'paid_at' => rand(1, 100) <= 95 ? $invoiceDate->copy()->addDays(rand(1, 5)) : null,
                    'stripe_id' => 'in_' . fake()->unique()->lexify('????????????????'),
                    'created_at' => $invoiceDate,
                ]);
                
                $totalInvoices++;
            }
        }
        
        $this->result->addGenerated('App\Models\Invoice', $totalInvoices);
    }

    protected function generateApiKeys(): void
    {
        $this->logProgress('Generating API keys...');
        
        $organizations = \App\Models\Organization::whereIn('plan', ['starter', 'professional', 'enterprise'])->get();
        $totalKeys = 0;
        
        foreach ($organizations as $org) {
            $keyCount = match($org->plan) {
                'enterprise' => rand(3, 10),
                'professional' => rand(1, 3),
                'starter' => rand(0, 1),
                default => 0,
            };
            
            for ($i = 0; $i < $keyCount; $i++) {
                \App\Models\ApiKey::create([
                    'organization_id' => $org->id,
                    'name' => fake()->randomElement(['Production', 'Development', 'Testing', 'Staging']) . ' Key ' . ($i + 1),
                    'key' => 'sk_' . fake()->unique()->lexify('????????????????????????????????????????'),
                    'last_used_at' => fake()->dateTimeBetween('-1 week', 'now'),
                    'expires_at' => rand(1, 100) <= 10 ? now()->addMonths(rand(1, 12)) : null,
                    'is_active' => rand(1, 100) <= 95,
                    'created_at' => $org->created_at->addDays(rand(1, 30)),
                ]);
                
                $totalKeys++;
            }
        }
        
        $this->result->addGenerated('App\Models\ApiKey', $totalKeys);
    }

    protected function generateUsageMetrics(): void
    {
        $this->logProgress('Generating usage metrics...');
        
        $organizations = \App\Models\Organization::where('plan', '!=', 'free')->get();
        $totalMetrics = 0;
        
        foreach ($organizations as $org) {
            $daysActive = $org->created_at->diffInDays(now());
            
            // Generate daily metrics
            for ($day = 0; $day < min($daysActive, 30); $day++) { // Last 30 days
                $date = now()->subDays($day);
                
                \App\Models\UsageMetric::create([
                    'organization_id' => $org->id,
                    'metric_type' => 'api_calls',
                    'value' => $this->generateApiCallCount($org->plan),
                    'date' => $date->format('Y-m-d'),
                    'created_at' => $date,
                ]);
                
                \App\Models\UsageMetric::create([
                    'organization_id' => $org->id,
                    'metric_type' => 'storage_gb',
                    'value' => $this->generateStorageUsage($org->plan),
                    'date' => $date->format('Y-m-d'),
                    'created_at' => $date,
                ]);
                
                $totalMetrics += 2;
            }
        }
        
        $this->result->addGenerated('App\Models\UsageMetric', $totalMetrics);
    }

    protected function generateAuditLogs(): void
    {
        $this->logProgress('Generating audit logs...');
        
        $users = \App\Models\User::limit(100)->get();
        $actions = [
            'user.login',
            'user.logout',
            'user.update',
            'team.create',
            'team.update',
            'team.delete',
            'api_key.create',
            'api_key.revoke',
            'subscription.update',
            'invoice.paid',
        ];
        
        $totalLogs = 0;
        
        foreach ($users as $user) {
            $logCount = rand(5, 50);
            
            for ($i = 0; $i < $logCount; $i++) {
                \App\Models\AuditLog::create([
                    'user_id' => $user->id,
                    'organization_id' => $user->organization_id,
                    'action' => fake()->randomElement($actions),
                    'model_type' => fake()->randomElement(['User', 'Team', 'ApiKey', 'Subscription']),
                    'model_id' => rand(1, 100),
                    'ip_address' => fake()->ipv4(),
                    'user_agent' => fake()->userAgent(),
                    'metadata' => json_encode([
                        'changes' => fake()->words(3),
                        'timestamp' => time(),
                    ]),
                    'created_at' => fake()->dateTimeBetween('-1 month', 'now'),
                ]);
                
                $totalLogs++;
            }
        }
        
        $this->result->addGenerated('App\Models\AuditLog', $totalLogs);
    }

    protected function generateSubscriptionEvents($subscription): void
    {
        // Generate plan change events
        $events = rand(1, 3);
        
        for ($i = 0; $i < $events; $i++) {
            // Implementation would create subscription event records
            // tracking upgrades, downgrades, quantity changes
        }
    }

    protected function determineSubscriptionStatus($createdAt, $churnRate, $trialConversionRate): string
    {
        $ageInDays = $createdAt->diffInDays(now());
        
        // New organizations in trial
        if ($ageInDays < 14) {
            return 'trialing';
        }
        
        // Trial conversion
        if ($ageInDays < 30) {
            return rand(1, 100) <= ($trialConversionRate * 100) ? 'active' : 'cancelled';
        }
        
        // Apply monthly churn
        $monthsActive = $ageInDays / 30;
        $churnProbability = 1 - pow(1 - $churnRate, $monthsActive);
        
        if (rand(1, 100) <= ($churnProbability * 100)) {
            return 'cancelled';
        }
        
        return 'active';
    }

    protected function calculateInvoiceAmount($subscription): float
    {
        $basePlans = [
            'starter' => 29,
            'professional' => 99,
            'enterprise' => 499,
        ];
        
        $org = \App\Models\Organization::find($subscription->organization_id);
        $baseAmount = $basePlans[$org->plan] ?? 0;
        
        // Add per-seat pricing for some plans
        if (in_array($org->plan, ['professional', 'enterprise'])) {
            $extraSeats = max(0, $subscription->quantity - 5);
            $baseAmount += $extraSeats * 10;
        }
        
        return $baseAmount;
    }

    protected function generateInvoiceStatus(): string
    {
        $rand = rand(1, 100);
        
        return match(true) {
            $rand <= 95 => 'paid',
            $rand <= 98 => 'pending',
            default => 'failed',
        };
    }

    protected function generateApiCallCount(string $plan): int
    {
        return match($plan) {
            'enterprise' => rand(50000, 500000),
            'professional' => rand(5000, 50000),
            'starter' => rand(100, 5000),
            default => rand(0, 100),
        };
    }

    protected function generateStorageUsage(string $plan): float
    {
        return match($plan) {
            'enterprise' => rand(100, 900) / 1.0, // 100-900 GB
            'professional' => rand(10, 80) / 1.0,  // 10-80 GB
            'starter' => rand(1, 8) / 1.0,         // 1-8 GB
            default => rand(0, 1000) / 1000.0,     // 0-1 GB
        };
    }

    protected function generateLastLogin($orgCreatedAt): ?\DateTime
    {
        if (rand(1, 100) <= 80) { // 80% have logged in recently
            return fake()->dateTimeBetween('-1 week', 'now');
        }
        
        return fake()->dateTimeBetween($orgCreatedAt, '-1 month');
    }

    protected function generateUserJoinDate($orgCreatedAt): \DateTime
    {
        return fake()->dateTimeBetween($orgCreatedAt, 'now');
    }

    protected function calculateMRR(): float
    {
        $mrr = 0;
        
        $activeSubscriptions = \App\Models\Subscription::where('status', 'active')->get();
        
        foreach ($activeSubscriptions as $subscription) {
            $mrr += $this->calculateInvoiceAmount($subscription);
        }
        
        return $mrr;
    }

    public function getDefaultConfig(): array
    {
        return [
            'organization_count' => 100,
            'users_per_org' => ['min' => 1, 'max' => 50],
            'time_period' => 365,
            'churn_rate' => 0.05,
            'growth_rate' => 0.1,
            'trial_conversion_rate' => 0.15,
            'api_usage_enabled' => true,
        ];
    }
}