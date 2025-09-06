# Laravel Mint

Generate realistic test data that actually makes sense.

[![Latest Version](https://img.shields.io/packagist/v/argent/laravel-mint.svg?style=flat-square)](https://packagist.org/packages/argent/laravel-mint)
[![MIT License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/argent/laravel-mint.svg?style=flat-square)](https://packagist.org/packages/argent/laravel-mint)

## Table of Contents

- [Introduction](#introduction)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Core Features](#core-features)
  - [Model Analysis](#model-analysis)
  - [Smart Data Generation](#smart-data-generation)
  - [Pattern System](#pattern-system)
  - [Scenarios](#scenarios)
  - [Import & Export](#import--export)
- [Examples](#examples)
- [Configuration](#configuration)
- [API Reference](#api-reference)
- [CLI Commands](#cli-commands)
- [Advanced Usage](#advanced-usage)
- [Testing](#testing)
- [Contributing](#contributing)
- [Support](#support)
- [License](#license)

## Introduction

Laravel Mint is a powerful data generation package that goes beyond simple random values. It analyzes your models, understands relationships, and generates data that follows real-world patterns. Whether you need test data for development, load testing, or demos, Mint creates datasets that actually make sense.

### Why Laravel Mint?

Ever generated test data where all users signed up on the same day? Or where order values are completely random instead of following realistic distributions? That's what we fix. Laravel Mint generates data that looks and behaves like production data, making your testing and development more meaningful.

### Key Features

- **Smart Model Analysis**: Automatically detects relationships, validations, and data types
- **Real-World Patterns**: Uses statistical distributions and temporal patterns for realistic data
- **Pre-Built Scenarios**: Ready-to-use e-commerce and SaaS data scenarios
- **High Performance**: Stream processing and parallel generation for large datasets
- **Import/Export**: Work with CSV, JSON, Excel, and SQL files
- **API & Webhooks**: RESTful API with CI/CD integration support
- **Factory Integration**: Enhance Laravel factories with pattern support

## Installation

You can install the package via composer:

```bash
composer require argent/laravel-mint
```

The package will automatically register its service provider and facade.

### Requirements

- PHP 8.2 or higher
- Laravel 11.0 or higher
- Optional: `league/csv` for CSV operations
- Optional: `phpoffice/phpspreadsheet` for Excel support

### Publishing Assets

Publish the config file:

```bash
php artisan vendor:publish --tag=mint-config
```

Publish pattern templates:

```bash
php artisan vendor:publish --tag=mint-patterns
```

## Quick Start

Here's the fastest way to see what Laravel Mint can do:

```php
use LaravelMint\Mint;

// Generate 100 users with realistic patterns
app('mint')->generate(User::class, 100);

// Run a complete e-commerce scenario
php artisan mint:scenario ecommerce
```

That's it! You now have users with varied registration dates, realistic email patterns, and proper timestamps. But we're just getting started.

## Core Features

### Model Analysis

Laravel Mint starts by understanding your models. It examines relationships, validation rules, database schemas, and more:

```php
use LaravelMint\Mint;

$mint = app('mint');

// Analyze a model
$analysis = $mint->analyze(User::class);

// See what Mint discovered
print_r($analysis);
// Returns: fillable fields, relationships, validation rules, column types, etc.
```

The analyzer detects:
- Foreign keys and relationships (belongsTo, hasMany, etc.)
- Validation rules from FormRequests
- Database column types and constraints
- Model scopes and accessors
- Enum fields and their valid values

### Smart Data Generation

Mint doesn't just generate random data. It understands context:

```php
// Basic generation with smart defaults
$mint->generate(Product::class, 50);

// This automatically:
// - Sets appropriate prices (not random numbers)
// - Creates realistic SKUs
// - Assigns proper categories
// - Generates SEO-friendly slugs
// - Sets stock levels that make sense
```

#### Customizing Generation

```php
$mint->generate(User::class, 100, [
    'overrides' => [
        'country' => 'USA',
        'is_active' => true,
    ],
    'with_relations' => ['posts', 'comments'],
    'use_patterns' => true,
]);
```

### Pattern System

Real data follows patterns. Laravel Mint includes several statistical distributions to make your test data realistic:

#### Normal Distribution (Bell Curve)

Perfect for natural phenomena like heights, weights, or test scores:

```php
use LaravelMint\Patterns\Distributions\NormalDistribution;

$pattern = new NormalDistribution(mean: 30, stddev: 5);

// Generate ages centered around 30
$mint->generate(User::class, 1000, [
    'column_patterns' => [
        'age' => $pattern
    ]
]);
```

#### Pareto Distribution (80/20 Rule)

Great for modeling real-world scenarios where a few items dominate:

```php
use LaravelMint\Patterns\Distributions\ParetoDistribution;

// 80% of revenue from 20% of customers
$pattern = new ParetoDistribution(xmin: 10, alpha: 1.2);

$mint->generate(Order::class, 1000, [
    'column_patterns' => [
        'total_amount' => $pattern
    ]
]);
```

#### Temporal Patterns

Generate time-based data that looks real:

```php
use LaravelMint\Patterns\Temporal\LinearGrowth;
use LaravelMint\Patterns\Temporal\SeasonalPattern;

// Linear growth over time (like user signups)
$growth = new LinearGrowth(
    start: now()->subYear(),
    end: now(),
    growth_rate: 0.1
);

// Seasonal patterns (like e-commerce sales)
$seasonal = new SeasonalPattern(
    base_value: 1000,
    amplitude: 0.3,
    peaks: ['november', 'december'] // Black Friday & holidays
);

$mint->generate(Order::class, 5000, [
    'column_patterns' => [
        'created_at' => $growth,
        'daily_revenue' => $seasonal
    ]
]);
```

### Scenarios

Scenarios are pre-built data generation templates for common use cases:

#### E-commerce Scenario

```php
// Generate a complete e-commerce dataset
php artisan mint:scenario ecommerce --users=1000 --products=500 --orders=5000

// Or use in code
use LaravelMint\Scenarios\ScenarioRunner;
use LaravelMint\Scenarios\Presets\EcommerceScenario;

$runner = app(ScenarioRunner::class);
$runner->register('ecommerce', EcommerceScenario::class);

$result = $runner->run('ecommerce', [
    'user_count' => 1000,
    'product_count' => 500,
    'order_count' => 5000,
    'seasonal_pattern' => true,
    'black_friday' => true,
]);
```

This generates:
- Users segmented into power buyers (20%), regular (50%), and occasional (30%)
- Products with realistic pricing following market distributions
- Orders with seasonal patterns and Black Friday spikes
- Cart abandonment data
- Product reviews with realistic ratings
- Wishlists and customer behavior data

#### SaaS Scenario

```php
php artisan mint:scenario saas --organizations=100

// Generates:
// - Organizations with growth patterns
// - Subscription lifecycles with realistic churn
// - Team hierarchies
// - Usage metrics and billing data
// - API keys and audit logs
```

### Import & Export

Work with existing data or share your generated datasets:

#### Importing Data

```php
use LaravelMint\Import\ImportManager;

$importer = new ImportManager();

// Map CSV columns to model fields
$importer->mapping(User::class, [
    'name' => 'full_name',        // CSV column -> Model field
    'email' => 'email_address',
    'password' => function ($row) {
        return bcrypt($row['password']);
    }
]);

// Import with validation
$result = $importer->import('users.csv');
```

#### Exporting Data

```php
use LaravelMint\Export\ExportManager;

$exporter = new ExportManager();

// Export specific models and fields
$exporter->model(User::class, ['id', 'name', 'email', 'created_at'])
         ->model(Order::class)
         ->where(Order::class, 'status', 'completed')
         ->compress();

$result = $exporter->export('json', 'exports/backup.json');
```

## Examples

### Example 1: Realistic User Registration Pattern

You want users that signed up over the past year with a growth trend:

```php
use LaravelMint\Mint;
use LaravelMint\Patterns\Temporal\LinearGrowth;

$mint = app('mint');

// Create a growth pattern
$registrationPattern = new LinearGrowth(
    start: now()->subYear(),
    end: now(),
    growth_rate: 0.15  // 15% monthly growth
);

// Generate users
$mint->generate(User::class, 1000, [
    'column_patterns' => [
        'created_at' => $registrationPattern,
        'email_verified_at' => function ($user) {
            // 80% verify within 24 hours
            if (rand(1, 100) <= 80) {
                return $user->created_at->addHours(rand(1, 24));
            }
            return null;
        }
    ]
]);
```

### Example 2: E-commerce with Realistic Order Values

```php
// Most orders are small, few are large (Pareto principle)
use LaravelMint\Patterns\Distributions\ParetoDistribution;

$orderValues = new ParetoDistribution(xmin: 25, alpha: 1.5);

// Generate orders
$mint->generate(Order::class, 1000, [
    'column_patterns' => [
        'subtotal' => $orderValues,
        'tax' => function ($order) {
            return $order->subtotal * 0.08; // 8% tax
        },
        'total' => function ($order) {
            return $order->subtotal + $order->tax;
        }
    ]
]);
```

### Example 3: Building a Test Dataset for Load Testing

```php
// Use streaming for large datasets
use LaravelMint\Performance\StreamProcessor;

$processor = new StreamProcessor();

// Generate 1 million records without memory issues
$processor->generate(User::class, 1000000, function ($index) {
    return [
        'name' => "User {$index}",
        'email' => "user{$index}@example.com",
        'created_at' => now()->subDays(rand(0, 365)),
    ];
}, [
    'chunk_size' => 5000,
    'batch_insert' => true
]);
```

### Example 4: Creating Linked Data with Relationships

```php
// Generate a complete blog dataset
$mint->generate(User::class, 10, [
    'after_create' => function ($user) use ($mint) {
        // Each user gets 5-15 posts
        $postCount = rand(5, 15);
        $mint->generate(Post::class, $postCount, [
            'overrides' => [
                'user_id' => $user->id,
                'published_at' => function () use ($user) {
                    // Posts published after user joined
                    return fake()->dateTimeBetween(
                        $user->created_at,
                        'now'
                    );
                }
            ],
            'after_create' => function ($post) use ($mint) {
                // Each post gets 0-20 comments
                $commentCount = rand(0, 20);
                $mint->generate(Comment::class, $commentCount, [
                    'overrides' => [
                        'post_id' => $post->id,
                        'user_id' => User::inRandomOrder()->first()->id,
                    ]
                ]);
            }
        ]);
    }
]);
```

### Example 5: Custom Scenario for Your Domain

```php
use LaravelMint\Scenarios\BaseScenario;

class RealEstateScenario extends BaseScenario
{
    protected function initialize(): void
    {
        $this->name = 'Real Estate Platform';
        $this->description = 'Generate property listings and inquiries';
        
        $this->requiredModels = [
            Property::class,
            Agent::class,
            Inquiry::class,
        ];
    }
    
    protected function execute(): void
    {
        // Generate agents
        $this->generateModel(Agent::class, 50, [
            'column_patterns' => [
                'years_experience' => new NormalDistribution(7, 3),
                'commission_rate' => new NormalDistribution(0.025, 0.005),
            ]
        ]);
        
        // Generate properties with realistic pricing
        $this->generateModel(Property::class, 500, [
            'column_patterns' => [
                'price' => new ParetoDistribution(200000, 1.5),
                'bedrooms' => new PoissonDistribution(3),
                'square_feet' => new NormalDistribution(1800, 500),
            ]
        ]);
        
        // Generate inquiries with temporal patterns
        $this->generateModel(Inquiry::class, 2000, [
            'column_patterns' => [
                'created_at' => new SeasonalPattern(
                    base_value: 50,
                    peaks: ['march', 'april', 'september'] // Peak seasons
                ),
            ]
        ]);
    }
}
```

### Example 6: API Integration

```php
// Using the REST API for remote generation
$response = Http::withHeaders([
    'X-Mint-Api-Key' => 'your-api-key'
])->post('https://your-app.com/api/mint/generate', [
    'model' => 'App\Models\User',
    'count' => 100,
    'options' => [
        'use_patterns' => true
    ]
]);

// Async generation for large datasets
$response = Http::post('https://your-app.com/api/mint/generate', [
    'model' => 'App\Models\Product',
    'count' => 10000,
    'async' => true,
    'webhook_url' => 'https://your-app.com/webhooks/generation-complete'
]);

$jobId = $response->json('job_id');
```

### Example 7: CI/CD Integration

```yaml
# .github/workflows/test.yml
name: Tests
on: [push]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Generate Test Data
        run: |
          php artisan mint:scenario ecommerce --users=100
          php artisan test
```

### Example 8: Seeder Generation

```php
// Generate a seeder from existing data
php artisan mint:seed --generate --model=User

// Creates: database/seeders/UserSeeder.php
```

### Example 9: Factory Enhancement

```php
use LaravelMint\Integration\FactoryIntegration;

$factory = app(FactoryIntegration::class);

// Enhance your existing factory with patterns
User::factory()
    ->count(100)
    ->state($factory->createState('premium', [
        'subscription_ends_at' => [
            'pattern' => 'temporal',
            'start' => now(),
            'end' => now()->addYear(),
        ],
        'lifetime_value' => [
            'pattern' => 'pareto',
            'xmin' => 100,
            'alpha' => 1.2,
        ]
    ]))
    ->create();
```

### Example 10: Memory-Efficient Processing

```php
use LaravelMint\Performance\StreamProcessor;
use LaravelMint\Performance\MemoryMonitor;

$monitor = new MemoryMonitor();
$processor = new StreamProcessor();

// Monitor memory while generating
$monitor->start('large_generation');

$processor->stream(User::class, function ($user) {
    // Process each user without loading all into memory
    $user->orders()->create([
        'total' => rand(10, 1000)
    ]);
}, [
    'chunk_size' => 100,
    'use_cursor' => true  // Most memory efficient
]);

$metrics = $monitor->stop('large_generation');
echo "Peak memory: " . $metrics->getPeakMemory();
```

## Configuration

After publishing the config file, you can customize Mint's behavior:

```php
// config/mint.php
return [
    'default_count' => 10,
    'chunk_size' => 1000,
    
    'patterns' => [
        'enabled' => true,
        'cache_ttl' => 3600,
    ],
    
    'scenarios' => [
        'path' => app_path('Scenarios'),
        'namespace' => 'App\\Scenarios',
    ],
    
    'api' => [
        'enabled' => true,
        'prefix' => 'api/mint',
        'middleware' => ['api', 'throttle:60,1'],
        'keys' => env('MINT_API_KEYS', ''),
    ],
    
    'webhooks' => [
        'timeout' => 30,
        'retries' => 3,
    ],
    
    'performance' => [
        'parallel_workers' => 4,
        'memory_limit' => '256M',
        'use_transactions' => true,
    ],
];
```

## API Reference

### Authentication

All API endpoints require authentication via API key:

```bash
curl -H "X-Mint-Api-Key: your-key" https://your-app.com/api/mint/models
```

### Endpoints

#### Generate Data
```http
POST /api/mint/generate
Content-Type: application/json

{
    "model": "App\\Models\\User",
    "count": 100,
    "options": {
        "use_patterns": true
    }
}
```

#### Import Data
```http
POST /api/mint/import
Content-Type: multipart/form-data

file: users.csv
mappings[User][name]: full_name
mappings[User][email]: email
```

#### Export Data
```http
POST /api/mint/export
Content-Type: application/json

{
    "models": ["App\\Models\\User"],
    "format": "json",
    "compress": true
}
```

#### List Available Models
```http
GET /api/mint/models
```

#### Run Scenario
```http
POST /api/mint/scenarios/run
Content-Type: application/json

{
    "scenario": "ecommerce",
    "options": {
        "user_count": 1000
    }
}
```

## CLI Commands

Laravel Mint provides several Artisan commands:

### Generate Command
```bash
# Basic generation
php artisan mint:generate User 100

# With options
php artisan mint:generate Product 50 --pattern --with-relations

# Using specific patterns
php artisan mint:generate Order 1000 --pattern=pareto --pattern-config=xmin:10,alpha:1.5
```

### Analyze Command
```bash
# Analyze a model
php artisan mint:analyze User

# Analyze with relationships
php artisan mint:analyze Post --with-relations
```

### Scenario Command
```bash
# List available scenarios
php artisan mint:scenario:list

# Run a scenario
php artisan mint:scenario ecommerce

# Dry run to see what would be generated
php artisan mint:scenario saas --dry-run

# With custom options
php artisan mint:scenario ecommerce --config=user_count:500 --config=seasonal_pattern:false
```

### Import Command
```bash
# Import CSV
php artisan mint:import users.csv --model=User

# With field mapping
php artisan mint:import data.csv --model=Product --mapping=name:product_name

# Skip validation
php artisan mint:import large.json --no-validation
```

### Export Command
```bash
# Export to JSON
php artisan mint:export json --model=User --model=Post

# Export with conditions
php artisan mint:export csv --model=Order --where=Order:status:completed

# Compressed export
php artisan mint:export sql --model=User --compress
```

### Seed Command
```bash
# Generate seeder files
php artisan mint:seed --generate --model=User

# Smart seeder with dependencies
php artisan mint:seed --smart --model=User --model=Post --model=Comment

# Seed for specific environment
php artisan mint:seed --environment=testing
```

## Advanced Usage

### Custom Pattern Creation

Create your own patterns for domain-specific data:

```php
use LaravelMint\Patterns\AbstractPattern;

class PricePattern extends AbstractPattern
{
    public function generate(): float
    {
        // Psychological pricing (ending in 9)
        $base = rand(10, 100);
        return $base - 0.01;
    }
}

// Register and use
$mint->registerPattern('psychological_price', PricePattern::class);
```

### Parallel Processing

For massive datasets, use parallel processing:

```php
use LaravelMint\Performance\ParallelProcessor;

$processor = new ParallelProcessor();
$processor->setWorkers(8); // Use 8 parallel workers

$processor->generate(User::class, 100000, function ($index) {
    return [
        'email' => "user{$index}@example.com",
        'name' => "User {$index}",
    ];
});
```

### Query Optimization

Prevent N+1 queries when generating related data:

```php
use LaravelMint\Performance\QueryOptimizer;

$optimizer = new QueryOptimizer();

$optimizer->profile(function () use ($mint) {
    $mint->generate(Post::class, 100, [
        'with_relations' => ['user', 'comments', 'tags']
    ]);
});

// See optimization suggestions
$suggestions = $optimizer->getSuggestions();
```

### Caching for Performance

Cache generated patterns for reuse:

```php
use LaravelMint\Performance\CacheManager;

$cache = new CacheManager();

// Cache pattern results
$cache->remember('user_ages', function () {
    return (new NormalDistribution(35, 10))->generateMany(1000);
}, 3600);
```

## Testing

Laravel Mint makes testing easier with realistic data:

### In PHPUnit Tests

```php
use LaravelMint\Mint;

class OrderTest extends TestCase
{
    public function test_order_processing()
    {
        $mint = app('mint');
        
        // Generate test data
        $mint->generate(User::class, 1);
        $mint->generate(Product::class, 5);
        $mint->generate(Order::class, 1, [
            'overrides' => [
                'status' => 'pending'
            ]
        ]);
        
        // Run your test
        $order = Order::first();
        $this->assertTrue($order->process());
    }
}
```

### In Pest Tests

```php
use LaravelMint\Mint;

beforeEach(function () {
    $this->mint = app('mint');
});

it('calculates order totals correctly', function () {
    $this->mint->generate(Order::class, 10, [
        'column_patterns' => [
            'subtotal' => new NormalDistribution(100, 20)
        ]
    ]);
    
    $orders = Order::all();
    
    $orders->each(function ($order) {
        expect($order->total)->toBe($order->subtotal + $order->tax);
    });
});
```

### Load Testing

```php
use LaravelMint\Performance\Benchmark;

$benchmark = new Benchmark();

$results = $benchmark->loadTest(function () {
    // Simulate user behavior
    $user = User::factory()->create();
    $user->orders()->create([
        'total' => rand(10, 500)
    ]);
}, [
    'duration' => 60,        // Run for 60 seconds
    'concurrency' => 100,    // 100 concurrent operations
]);

echo "Throughput: " . $results->getThroughput() . " ops/sec";
```

## Contributing

We love contributions! Here's how to get started:

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Write tests for your changes
4. Make your changes
5. Run the test suite (`composer test`)
6. Format your code (`composer format`)
7. Commit your changes (`git commit -m 'Add amazing feature'`)
8. Push to your branch (`git push origin feature/amazing-feature`)
9. Open a Pull Request

### Development Setup

```bash
# Clone your fork
git clone https://github.com/your-username/laravel-mint.git
cd laravel-mint

# Install dependencies
composer install

# Run tests
composer test

# Format code
composer format
```

### Coding Standards

We follow PSR-12 coding standards and use Laravel Pint for formatting. Before submitting a PR, please run:

```bash
composer format
```

## Support

### Documentation

For more detailed documentation, visit our [documentation site](https://laravel-mint.dev/docs).

### Getting Help

- **Issues**: [GitHub Issues](https://github.com/argent/laravel-mint/issues)
- **Discussions**: [GitHub Discussions](https://github.com/argent/laravel-mint/discussions)
- **Email**: support@laravel-mint.dev

### Commercial Support

For commercial support, custom development, or enterprise licenses, contact us at enterprise@laravel-mint.dev.

## Security

If you discover any security issues, please email security@laravel-mint.dev instead of using the issue tracker. All security vulnerabilities will be promptly addressed.

## Credits

- [Argent](https://github.com/argent)
- [All Contributors](../../contributors)

Special thanks to the Laravel community for the inspiration and feedback that shaped this package.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.