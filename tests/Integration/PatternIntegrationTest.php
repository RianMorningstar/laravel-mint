<?php

namespace LaravelMint\Tests\Integration;

use LaravelMint\Tests\TestCase;
use LaravelMint\Tests\Helpers\AssertionHelpers;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Mint;
use LaravelMint\Patterns\PatternRegistry;
use LaravelMint\Patterns\Distributions\NormalDistribution;
use LaravelMint\Patterns\Distributions\ExponentialDistribution;
use LaravelMint\Patterns\Temporal\SeasonalPattern;
use LaravelMint\Patterns\CompositePattern;
use LaravelMint\Generators\PatternAwareGenerator;

class PatternIntegrationTest extends TestCase
{
    use AssertionHelpers;
    
    protected Mint $mint;
    protected PatternRegistry $registry;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mint = app(Mint::class);
        $this->registry = app(PatternRegistry::class);
        
        DatabaseSeeder::setupTestDatabase();
    }
    
    protected function tearDown(): void
    {
        DatabaseSeeder::cleanupTestDatabase();
        TestModelFactory::cleanup();
        parent::tearDown();
    }
    
    public function test_generate_with_normal_distribution_pattern()
    {
        $modelClass = TestModelFactory::create('Sales', [
            'amount' => 'decimal',
            'date' => 'datetime',
        ]);
        
        // Register pattern
        $pattern = new NormalDistribution([
            'mean' => 1000,
            'stddev' => 200,
            'min' => 500,
            'max' => 1500,
        ]);
        
        $this->registry->register('sales-normal', $pattern);
        
        // Generate data with pattern
        $records = $this->mint->generateWithPattern($modelClass, 100, 'sales-normal', [
            'field' => 'amount',
        ]);
        
        $this->assertCount(100, $records);
        
        // Verify distribution
        $amounts = $records->map(fn($r) => $r->amount)->toArray();
        $this->assertDataDistribution($amounts, 1000, 0.25);
        
        // Verify constraints
        foreach ($amounts as $amount) {
            $this->assertGreaterThanOrEqual(500, $amount);
            $this->assertLessThanOrEqual(1500, $amount);
        }
    }
    
    public function test_generate_with_seasonal_pattern()
    {
        $modelClass = TestModelFactory::create('Revenue', [
            'amount' => 'decimal',
            'month' => 'integer',
            'year' => 'integer',
        ]);
        
        // Register seasonal pattern (peaks in summer and winter)
        $pattern = new SeasonalPattern([
            'peaks' => [6, 12], // June and December
            'amplitude' => 0.5,
            'base_value' => 10000,
        ]);
        
        $this->registry->register('seasonal-sales', $pattern);
        
        // Generate data for each month
        $monthlyData = [];
        for ($month = 1; $month <= 12; $month++) {
            $date = new \DateTime("2024-{$month}-15");
            $value = $pattern->generateForDate($date);
            
            $record = $modelClass::create([
                'amount' => $value,
                'month' => $month,
                'year' => 2024,
            ]);
            
            $monthlyData[$month] = $record->amount;
        }
        
        // Verify seasonal pattern exists (there should be variation)
        $values = array_values($monthlyData);
        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        
        // There should be meaningful seasonal variation (at least 1% of base value)
        $this->assertGreaterThan(100, $range, "Should have seasonal variation of at least 100");
        
        // Verify we have data for all months
        $this->assertCount(12, $monthlyData);
    }
    
    public function test_composite_pattern_integration()
    {
        $modelClass = TestModelFactory::create('Traffic', [
            'visitors' => 'integer',
            'revenue' => 'decimal',
            'date' => 'datetime',
        ]);
        
        // Create multiple patterns
        $basePattern = new NormalDistribution([
            'mean' => 1000,
            'stddev' => 100,
        ]);
        
        $seasonalPattern = new SeasonalPattern([
            'peaks' => [7, 8], // Summer peak
            'amplitude' => 0.3,
            'base_value' => 1,
        ]);
        
        // Create composite pattern
        $composite = new CompositePattern([
            'patterns' => [$basePattern, $seasonalPattern],
            'weights' => [1.0, 0.3],
            'combination' => 'multiplicative',
        ]);
        
        $this->registry->register('traffic-composite', $composite);
        
        // Generate data for different dates
        $summerDate = new \DateTime('2024-07-15');
        $winterDate = new \DateTime('2024-01-15');
        
        $summerValue = $composite->generateForDate($summerDate);
        $winterValue = $composite->generateForDate($winterDate);
        
        // Summer should have higher traffic due to seasonal pattern
        $this->assertGreaterThan($winterValue, $summerValue);
    }
    
    public function test_pattern_with_relationships()
    {
        $userClass = TestModelFactory::create('Customer', [
            'name' => 'string',
            'lifetime_value' => 'decimal',
        ], [
            'orders' => ['type' => 'hasMany', 'model' => 'TestCustomerOrderModel'],
        ]);
        
        $orderClass = TestModelFactory::create('CustomerOrder', [
            'customer_id' => 'integer',
            'amount' => 'decimal',
            'date' => 'datetime',
        ], [
            'customer' => ['type' => 'belongsTo', 'model' => $userClass],
        ]);
        
        // Pattern for customer lifetime value
        $customerPattern = new ExponentialDistribution([
            'lambda' => 0.001, // Most customers have low value, few have high
        ]);
        
        $this->registry->register('customer-ltv', $customerPattern);
        
        // Generate customers with pattern
        $customers = $this->mint->generateWithPattern($userClass, 50, 'customer-ltv', [
            'field' => 'lifetime_value',
        ]);
        
        // Generate orders based on customer value
        foreach ($customers as $customer) {
            $orderCount = (int) ($customer->lifetime_value / 100);
            for ($i = 0; $i < $orderCount; $i++) {
                $orderClass::create([
                    'customer_id' => $customer->id,
                    'amount' => rand(50, 200),
                    'date' => fake()->dateTimeBetween('-1 year', 'now'),
                ]);
            }
        }
        
        // Verify relationship
        $highValueCustomer = $userClass::orderBy('lifetime_value', 'desc')->first();
        $this->assertGreaterThan(0, $highValueCustomer->orders()->count());
    }
    
    public function test_batch_generation_with_patterns()
    {
        $productClass = TestModelFactory::create('TestProduct', [
            'name' => 'string',
            'price' => 'decimal',
            'stock' => 'integer',
        ]);
        
        $reviewClass = TestModelFactory::create('Review', [
            'product_id' => 'integer',
            'rating' => 'integer',
            'content' => 'text',
        ]);
        
        // Register patterns
        $pricePattern = new NormalDistribution([
            'mean' => 50,
            'stddev' => 20,
            'min' => 10,
            'max' => 200,
        ]);
        
        $stockPattern = new ExponentialDistribution([
            'lambda' => 0.02,
            'min' => 0,
            'max' => 500,
        ]);
        
        $ratingPattern = new NormalDistribution([
            'mean' => 4,
            'stddev' => 0.8,
            'min' => 1,
            'max' => 5,
        ]);
        
        $this->registry->register('product-price', $pricePattern);
        $this->registry->register('product-stock', $stockPattern);
        $this->registry->register('review-rating', $ratingPattern);
        
        // Generate products
        $products = [];
        for ($i = 0; $i < 20; $i++) {
            $products[] = $productClass::create([
                'name' => fake()->word() . ' ' . fake()->word(),
                'price' => $pricePattern->generate(),
                'stock' => (int) $stockPattern->generate(),
            ]);
        }
        
        // Generate reviews with rating pattern
        foreach ($products as $product) {
            $reviewCount = rand(0, 10);
            for ($j = 0; $j < $reviewCount; $j++) {
                $reviewClass::create([
                    'product_id' => $product->id,
                    'rating' => (int) round($ratingPattern->generate()),
                    'content' => fake()->paragraph(),
                ]);
            }
        }
        
        // Verify patterns were applied
        $avgPrice = $productClass::avg('price');
        $this->assertGreaterThan(30, $avgPrice);
        $this->assertLessThan(70, $avgPrice);
        
        $avgRating = $reviewClass::avg('rating');
        $this->assertGreaterThan(3, $avgRating);
        $this->assertLessThan(5, $avgRating);
    }
    
    public function test_pattern_caching_performance()
    {
        $modelClass = TestModelFactory::create('CachedData', [
            'value' => 'decimal',
        ]);
        
        $pattern = new NormalDistribution([
            'mean' => 100,
            'stddev' => 10,
        ]);
        
        $this->registry->register('cached-pattern', $pattern);
        
        // First generation (cache miss)
        $start1 = microtime(true);
        $records1 = $this->mint->generateWithPattern($modelClass, 100, 'cached-pattern', [
            'field' => 'value',
        ]);
        $time1 = microtime(true) - $start1;
        
        // Clear records but keep cache
        $modelClass::truncate();
        
        // Second generation (cache hit for pattern)
        $start2 = microtime(true);
        $records2 = $this->mint->generateWithPattern($modelClass, 100, 'cached-pattern', [
            'field' => 'value',
        ]);
        $time2 = microtime(true) - $start2;
        
        // Second run should be faster due to caching
        $this->assertLessThanOrEqual($time1 * 1.1, $time2); // Allow 10% variance
    }
    
    public function test_pattern_error_handling()
    {
        $modelClass = TestModelFactory::create('ErrorTest', [
            'value' => 'integer',
        ]);
        
        // Try to use non-existent pattern
        $this->expectException(\InvalidArgumentException::class);
        
        $this->mint->generateWithPattern($modelClass, 10, 'non-existent-pattern', [
            'field' => 'value',
        ]);
    }
    
    public function test_dynamic_pattern_registration()
    {
        $modelClass = TestModelFactory::create('DynamicTest', [
            'score' => 'integer',
        ]);
        
        // Register pattern dynamically based on conditions
        $condition = 'high-variance';
        
        if ($condition === 'high-variance') {
            $pattern = new NormalDistribution([
                'mean' => 50,
                'stddev' => 25, // High variance
            ]);
        } else {
            $pattern = new NormalDistribution([
                'mean' => 50,
                'stddev' => 5, // Low variance
            ]);
        }
        
        $this->registry->register('dynamic-pattern', $pattern);
        
        $records = $this->mint->generateWithPattern($modelClass, 100, 'dynamic-pattern', [
            'field' => 'score',
        ]);
        
        // Calculate variance
        $scores = $records->map(fn($r) => $r->score)->toArray();
        $mean = array_sum($scores) / count($scores);
        $variance = array_sum(array_map(fn($s) => pow($s - $mean, 2), $scores)) / count($scores);
        $stddev = sqrt($variance);
        
        // Should have high standard deviation
        $this->assertGreaterThan(15, $stddev);
    }
    
    public function test_pattern_with_multiple_fields()
    {
        $modelClass = TestModelFactory::create('MultiField', [
            'temperature' => 'decimal',
            'humidity' => 'decimal',
            'pressure' => 'decimal',
        ]);
        
        // Different patterns for different fields
        $tempPattern = new NormalDistribution([
            'mean' => 22,
            'stddev' => 3,
        ]);
        
        $humidityPattern = new NormalDistribution([
            'mean' => 60,
            'stddev' => 10,
        ]);
        
        $pressurePattern = new NormalDistribution([
            'mean' => 1013,
            'stddev' => 20,
        ]);
        
        $this->registry->register('temperature', $tempPattern);
        $this->registry->register('humidity', $humidityPattern);
        $this->registry->register('pressure', $pressurePattern);
        
        // Generate with multiple patterns
        $records = [];
        for ($i = 0; $i < 50; $i++) {
            $records[] = $modelClass::create([
                'temperature' => $tempPattern->generate(),
                'humidity' => $humidityPattern->generate(),
                'pressure' => $pressurePattern->generate(),
            ]);
        }
        
        // Verify each field follows its pattern
        $temps = collect($records)->map(fn($r) => $r->temperature)->toArray();
        $humidities = collect($records)->map(fn($r) => $r->humidity)->toArray();
        $pressures = collect($records)->map(fn($r) => $r->pressure)->toArray();
        
        $this->assertDataDistribution($temps, 22, 0.3);
        $this->assertDataDistribution($humidities, 60, 0.3);
        $this->assertDataDistribution($pressures, 1013, 0.3);
    }
}