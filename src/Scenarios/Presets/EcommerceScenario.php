<?php

namespace LaravelMint\Scenarios\Presets;

use LaravelMint\Scenarios\BaseScenario;
use LaravelMint\Patterns\PatternRegistry;

class EcommerceScenario extends BaseScenario
{
    protected function initialize(): void
    {
        $this->name = 'E-commerce Store';
        $this->description = 'Generates realistic e-commerce data including users, products, orders, and reviews';
        
        $this->requiredModels = [
            'App\Models\User',
            'App\Models\Product',
            'App\Models\Order',
        ];
        
        $this->optionalModels = [
            'App\Models\Category',
            'App\Models\Cart',
            'App\Models\Review',
            'App\Models\Wishlist',
            'App\Models\Coupon',
        ];
        
        $this->parameters = [
            'user_count' => [
                'type' => 'integer',
                'default' => 1000,
                'min' => 10,
                'max' => 100000,
                'description' => 'Number of users to generate',
            ],
            'product_count' => [
                'type' => 'integer',
                'default' => 500,
                'min' => 10,
                'max' => 10000,
                'description' => 'Number of products to generate',
            ],
            'order_count' => [
                'type' => 'integer',
                'default' => 5000,
                'min' => 10,
                'max' => 100000,
                'description' => 'Number of orders to generate',
            ],
            'time_period' => [
                'type' => 'integer',
                'default' => 365,
                'min' => 7,
                'max' => 1095,
                'description' => 'Time period in days for order distribution',
            ],
            'cart_abandonment_rate' => [
                'type' => 'float',
                'default' => 0.3,
                'min' => 0,
                'max' => 1,
                'description' => 'Rate of cart abandonment',
            ],
            'review_rate' => [
                'type' => 'float',
                'default' => 0.15,
                'min' => 0,
                'max' => 1,
                'description' => 'Percentage of orders that get reviews',
            ],
            'repeat_customer_rate' => [
                'type' => 'float',
                'default' => 0.4,
                'min' => 0,
                'max' => 1,
                'description' => 'Percentage of repeat customers',
            ],
            'seasonal_pattern' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Apply seasonal patterns to orders',
            ],
            'black_friday' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Include Black Friday sales spike',
            ],
        ];
    }

    protected function execute(): void
    {
        $userCount = $this->getConfig('user_count', 1000);
        $productCount = $this->getConfig('product_count', 500);
        $orderCount = $this->getConfig('order_count', 5000);
        $timePeriod = $this->getConfig('time_period', 365);

        // Step 1: Generate Users with segments
        $this->generateUsers($userCount);
        
        // Step 2: Generate Categories (if model exists)
        if (class_exists('App\Models\Category')) {
            $this->generateCategories();
        }
        
        // Step 3: Generate Products with patterns
        $this->generateProducts($productCount);
        
        // Step 4: Generate Orders with temporal patterns
        $this->generateOrders($orderCount, $timePeriod);
        
        // Step 5: Generate Carts (including abandoned)
        if (class_exists('App\Models\Cart')) {
            $this->generateCarts();
        }
        
        // Step 6: Generate Reviews
        if (class_exists('App\Models\Review')) {
            $this->generateReviews();
        }
        
        // Step 7: Generate Wishlists
        if (class_exists('App\Models\Wishlist')) {
            $this->generateWishlists();
        }
        
        // Step 8: Generate Coupons
        if (class_exists('App\Models\Coupon')) {
            $this->generateCoupons();
        }
    }

    protected function generateUsers(int $count): void
    {
        $this->logProgress('Generating users with customer segments...');
        
        // Power buyers (20% - Pareto principle)
        $powerBuyerCount = (int)($count * 0.2);
        $this->generateModel('App\Models\User', $powerBuyerCount, [
            'segment' => 'power_buyer',
            'column_patterns' => [
                'created_at' => [
                    'type' => 'temporal_linear',
                    'start' => '-2 years',
                    'end' => '-6 months',
                ],
            ],
            'overrides' => [
                'email_verified_at' => fn() => now()->subDays(rand(30, 730)),
            ],
        ]);
        
        // Regular customers (50%)
        $regularCount = (int)($count * 0.5);
        $this->generateModel('App\Models\User', $regularCount, [
            'segment' => 'regular',
            'column_patterns' => [
                'created_at' => [
                    'type' => 'temporal_linear',
                    'start' => '-1 year',
                    'end' => 'now',
                ],
            ],
        ]);
        
        // Occasional buyers (30%)
        $occasionalCount = $count - $powerBuyerCount - $regularCount;
        $this->generateModel('App\Models\User', $occasionalCount, [
            'segment' => 'occasional',
            'column_patterns' => [
                'created_at' => [
                    'type' => 'temporal_linear',
                    'start' => '-6 months',
                    'end' => 'now',
                ],
            ],
        ]);
        
        $this->result->addStatistic('user_segments', [
            'power_buyers' => $powerBuyerCount,
            'regular' => $regularCount,
            'occasional' => $occasionalCount,
        ]);
    }

    protected function generateCategories(): void
    {
        $this->logProgress('Generating product categories...');
        
        $categories = [
            'Electronics' => ['parent' => null, 'slug' => 'electronics'],
            'Clothing' => ['parent' => null, 'slug' => 'clothing'],
            'Home & Garden' => ['parent' => null, 'slug' => 'home-garden'],
            'Sports & Outdoors' => ['parent' => null, 'slug' => 'sports-outdoors'],
            'Books' => ['parent' => null, 'slug' => 'books'],
            'Toys & Games' => ['parent' => null, 'slug' => 'toys-games'],
            'Health & Beauty' => ['parent' => null, 'slug' => 'health-beauty'],
            'Food & Grocery' => ['parent' => null, 'slug' => 'food-grocery'],
        ];
        
        foreach ($categories as $name => $data) {
            $model = 'App\Models\Category';
            $model::create([
                'name' => $name,
                'slug' => $data['slug'],
                'parent_id' => $data['parent'],
                'description' => fake()->sentence(),
                'is_active' => true,
            ]);
        }
        
        $this->result->addGenerated('App\Models\Category', count($categories));
    }

    protected function generateProducts(int $count): void
    {
        $this->logProgress('Generating products with price patterns...');
        
        // Premium products (20%)
        $premiumCount = (int)($count * 0.2);
        $this->generateModel('App\Models\Product', $premiumCount, [
            'segment' => 'premium',
            'column_patterns' => [
                'price' => [
                    'type' => 'pareto',
                    'xmin' => 100,
                    'alpha' => 1.5,
                ],
                'stock' => [
                    'type' => 'normal',
                    'mean' => 50,
                    'stddev' => 10,
                ],
            ],
            'overrides' => [
                'sku' => fn($i) => 'PRE-' . str_pad($i, 6, '0', STR_PAD_LEFT),
                'is_featured' => fn() => rand(1, 100) <= 30,
            ],
        ]);
        
        // Regular products (60%)
        $regularCount = (int)($count * 0.6);
        $this->generateModel('App\Models\Product', $regularCount, [
            'segment' => 'regular',
            'column_patterns' => [
                'price' => [
                    'type' => 'normal',
                    'mean' => 50,
                    'stddev' => 20,
                    'min' => 10,
                    'max' => 100,
                ],
                'stock' => [
                    'type' => 'normal',
                    'mean' => 100,
                    'stddev' => 30,
                ],
            ],
            'overrides' => [
                'sku' => fn($i) => 'REG-' . str_pad($i, 6, '0', STR_PAD_LEFT),
            ],
        ]);
        
        // Budget products (20%)
        $budgetCount = $count - $premiumCount - $regularCount;
        $this->generateModel('App\Models\Product', $budgetCount, [
            'segment' => 'budget',
            'column_patterns' => [
                'price' => [
                    'type' => 'exponential',
                    'lambda' => 0.05,
                    'min' => 5,
                    'max' => 30,
                ],
                'stock' => [
                    'type' => 'normal',
                    'mean' => 200,
                    'stddev' => 50,
                ],
            ],
            'overrides' => [
                'sku' => fn($i) => 'BUD-' . str_pad($i, 6, '0', STR_PAD_LEFT),
            ],
        ]);
    }

    protected function generateOrders(int $count, int $timePeriod): void
    {
        $this->logProgress('Generating orders with temporal patterns...');
        
        $users = \App\Models\User::all();
        $products = \App\Models\Product::all();
        
        if ($users->isEmpty() || $products->isEmpty()) {
            $this->result->addError('No users or products available for orders');
            return;
        }
        
        $startDate = now()->subDays($timePeriod);
        $endDate = now();
        
        // Apply seasonal pattern if enabled
        if ($this->getConfig('seasonal_pattern', true)) {
            $options = [
                'column_patterns' => [
                    'created_at' => [
                        'type' => 'temporal_seasonal',
                        'base_value' => $count / $timePeriod,
                        'amplitude' => 0.3,
                        'period' => 'year',
                        'peaks' => ['november', 'december'],
                    ],
                    'total' => [
                        'type' => 'pareto',
                        'xmin' => 20,
                        'alpha' => 1.2,
                    ],
                ],
            ];
        } else {
            $options = [
                'column_patterns' => [
                    'created_at' => [
                        'type' => 'temporal_linear',
                        'start' => $startDate,
                        'end' => $endDate,
                    ],
                    'total' => [
                        'type' => 'pareto',
                        'xmin' => 20,
                        'alpha' => 1.2,
                    ],
                ],
            ];
        }
        
        // Add Black Friday spike if enabled
        if ($this->getConfig('black_friday', true)) {
            $blackFridayCount = (int)($count * 0.1); // 10% of orders on Black Friday week
            $blackFridayDate = $this->getBlackFridayDate($startDate, $endDate);
            
            if ($blackFridayDate) {
                $this->generateModel('App\Models\Order', $blackFridayCount, [
                    'overrides' => [
                        'user_id' => fn() => $users->random()->id,
                        'status' => fn() => fake()->randomElement(['pending', 'processing', 'completed', 'shipped']),
                        'created_at' => fn() => $blackFridayDate->copy()->addDays(rand(-3, 3)),
                        'total' => fn() => fake()->randomFloat(2, 50, 500),
                    ],
                ]);
                
                $count -= $blackFridayCount;
            }
        }
        
        // Generate regular orders
        $options['overrides'] = [
            'user_id' => fn() => $this->selectUserBySegment($users),
            'status' => fn() => $this->generateOrderStatus(),
        ];
        
        $this->generateModel('App\Models\Order', $count, $options);
        
        // Add order statistics
        $this->result->addStatistic('orders_generated', $count);
        $this->result->addStatistic('average_order_value', \App\Models\Order::avg('total'));
    }

    protected function generateCarts(): void
    {
        $abandonmentRate = $this->getConfig('cart_abandonment_rate', 0.3);
        $users = \App\Models\User::all();
        $products = \App\Models\Product::all();
        
        if ($users->isEmpty() || $products->isEmpty()) {
            return;
        }
        
        $cartCount = (int)($users->count() * 0.5); // 50% of users have carts
        $abandonedCount = (int)($cartCount * $abandonmentRate);
        
        $this->logProgress("Generating {$cartCount} carts ({$abandonedCount} abandoned)...");
        
        // Generate abandoned carts
        $this->generateModel('App\Models\Cart', $abandonedCount, [
            'overrides' => [
                'user_id' => fn() => $users->random()->id,
                'product_id' => fn() => $products->random()->id,
                'quantity' => fn() => rand(1, 3),
                'is_abandoned' => true,
                'created_at' => fn() => now()->subDays(rand(1, 30)),
            ],
        ]);
        
        // Generate active carts
        $activeCount = $cartCount - $abandonedCount;
        $this->generateModel('App\Models\Cart', $activeCount, [
            'overrides' => [
                'user_id' => fn() => $users->random()->id,
                'product_id' => fn() => $products->random()->id,
                'quantity' => fn() => rand(1, 5),
                'is_abandoned' => false,
                'created_at' => fn() => now()->subDays(rand(0, 7)),
            ],
        ]);
    }

    protected function generateReviews(): void
    {
        $reviewRate = $this->getConfig('review_rate', 0.15);
        $orders = \App\Models\Order::where('status', 'completed')->get();
        
        if ($orders->isEmpty()) {
            return;
        }
        
        $reviewCount = (int)($orders->count() * $reviewRate);
        $this->logProgress("Generating {$reviewCount} reviews...");
        
        $reviewedOrders = $orders->random(min($reviewCount, $orders->count()));
        
        foreach ($reviewedOrders as $order) {
            \App\Models\Review::create([
                'user_id' => $order->user_id,
                'product_id' => $order->items->first()->product_id ?? \App\Models\Product::inRandomOrder()->first()->id,
                'order_id' => $order->id,
                'rating' => $this->generateRating(),
                'title' => fake()->sentence(4),
                'comment' => fake()->paragraph(),
                'is_verified' => true,
                'created_at' => $order->created_at->addDays(rand(3, 14)),
            ]);
        }
        
        $this->result->addGenerated('App\Models\Review', $reviewCount);
    }

    protected function generateWishlists(): void
    {
        $users = \App\Models\User::limit(100)->get(); // Top 100 users
        $products = \App\Models\Product::all();
        
        if ($users->isEmpty() || $products->isEmpty()) {
            return;
        }
        
        $this->logProgress('Generating wishlists...');
        
        foreach ($users as $user) {
            $wishlistCount = rand(0, 10);
            
            for ($i = 0; $i < $wishlistCount; $i++) {
                \App\Models\Wishlist::create([
                    'user_id' => $user->id,
                    'product_id' => $products->random()->id,
                    'created_at' => fake()->dateTimeBetween('-3 months', 'now'),
                ]);
            }
        }
        
        $this->result->addGenerated('App\Models\Wishlist', \App\Models\Wishlist::count());
    }

    protected function generateCoupons(): void
    {
        $this->logProgress('Generating coupons...');
        
        $coupons = [
            ['code' => 'WELCOME10', 'discount' => 10, 'type' => 'percentage'],
            ['code' => 'SAVE20', 'discount' => 20, 'type' => 'fixed'],
            ['code' => 'FREESHIP', 'discount' => 100, 'type' => 'shipping'],
            ['code' => 'BLACKFRIDAY', 'discount' => 30, 'type' => 'percentage'],
            ['code' => 'CYBER50', 'discount' => 50, 'type' => 'percentage'],
        ];
        
        foreach ($coupons as $coupon) {
            \App\Models\Coupon::create([
                'code' => $coupon['code'],
                'discount' => $coupon['discount'],
                'type' => $coupon['type'],
                'minimum_amount' => rand(20, 100),
                'usage_limit' => rand(100, 1000),
                'used_count' => rand(0, 50),
                'expires_at' => now()->addMonths(rand(1, 6)),
                'is_active' => true,
            ]);
        }
        
        $this->result->addGenerated('App\Models\Coupon', count($coupons));
    }

    protected function selectUserBySegment($users)
    {
        // 80% of orders from 20% of users (power buyers)
        if (rand(1, 100) <= 80) {
            // Select from top 20% of users
            $powerBuyers = $users->take((int)($users->count() * 0.2));
            return $powerBuyers->random()->id;
        }
        
        return $users->random()->id;
    }

    protected function generateOrderStatus(): string
    {
        $rand = rand(1, 100);
        
        return match(true) {
            $rand <= 60 => 'completed',
            $rand <= 75 => 'shipped',
            $rand <= 85 => 'processing',
            $rand <= 95 => 'pending',
            default => 'cancelled',
        };
    }

    protected function generateRating(): int
    {
        $rand = rand(1, 100);
        
        return match(true) {
            $rand <= 5 => 1,   // 5% - 1 star
            $rand <= 10 => 2,  // 5% - 2 stars
            $rand <= 25 => 3,  // 15% - 3 stars
            $rand <= 55 => 4,  // 30% - 4 stars
            default => 5,      // 45% - 5 stars
        };
    }

    protected function getBlackFridayDate($startDate, $endDate): ?\DateTime
    {
        $year = $startDate->year;
        
        // Black Friday is the day after Thanksgiving (4th Thursday of November)
        $november = new \DateTime("$year-11-01");
        $thanksgiving = new \DateTime("fourth thursday of november $year");
        $blackFriday = $thanksgiving->modify('+1 day');
        
        if ($blackFriday >= $startDate && $blackFriday <= $endDate) {
            return $blackFriday;
        }
        
        return null;
    }

    public function getDefaultConfig(): array
    {
        return [
            'user_count' => 1000,
            'product_count' => 500,
            'order_count' => 5000,
            'time_period' => 365,
            'cart_abandonment_rate' => 0.3,
            'review_rate' => 0.15,
            'repeat_customer_rate' => 0.4,
            'seasonal_pattern' => true,
            'black_friday' => true,
        ];
    }
}