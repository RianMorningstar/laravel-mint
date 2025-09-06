<?php

namespace LaravelMint\Tests\Unit;

use LaravelMint\Tests\TestCase;
use LaravelMint\Tests\Helpers\AssertionHelpers;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Generators\DataGenerator;
use LaravelMint\Generators\SimpleGenerator;
use LaravelMint\Generators\PatternAwareGenerator;
use LaravelMint\Patterns\PatternRegistry;
use Illuminate\Support\Facades\Config;
use Mockery;

class DataGeneratorTest extends TestCase
{
    use AssertionHelpers;
    
    protected DataGenerator $generator;
    protected PatternRegistry $patternRegistry;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->patternRegistry = $this->app->make(PatternRegistry::class);
        $this->generator = new DataGenerator(
            new SimpleGenerator(),
            new PatternAwareGenerator($this->patternRegistry)
        );
    }
    
    protected function tearDown(): void
    {
        TestModelFactory::cleanup();
        Mockery::close();
        parent::tearDown();
    }
    
    public function test_generator_instance_is_created()
    {
        $this->assertInstanceOf(DataGenerator::class, $this->generator);
    }
    
    public function test_generate_string_field()
    {
        $value = $this->generator->generateField('string', 'name');
        
        $this->assertIsString($value);
        $this->assertNotEmpty($value);
    }
    
    public function test_generate_integer_field()
    {
        $value = $this->generator->generateField('integer', 'count');
        
        $this->assertIsInt($value);
        $this->assertGreaterThanOrEqual(0, $value);
    }
    
    public function test_generate_boolean_field()
    {
        $value = $this->generator->generateField('boolean', 'is_active');
        
        $this->assertIsBool($value);
    }
    
    public function test_generate_decimal_field()
    {
        $value = $this->generator->generateField('decimal', 'price');
        
        $this->assertIsFloat($value);
        $this->assertGreaterThanOrEqual(0, $value);
    }
    
    public function test_generate_datetime_field()
    {
        $value = $this->generator->generateField('datetime', 'created_at');
        
        $this->assertInstanceOf(\DateTime::class, $value);
    }
    
    public function test_generate_json_field()
    {
        $value = $this->generator->generateField('json', 'metadata');
        
        $this->assertIsArray($value);
    }
    
    public function test_generate_email_field()
    {
        $value = $this->generator->generateField('string', 'email');
        
        $this->assertMatchesRegularExpression('/^.+@.+\..+$/', $value);
    }
    
    public function test_generate_url_field()
    {
        $value = $this->generator->generateField('string', 'website_url');
        
        $this->assertMatchesRegularExpression('/^https?:\/\/.+/', $value);
    }
    
    public function test_generate_phone_field()
    {
        $value = $this->generator->generateField('string', 'phone_number');
        
        $this->assertMatchesRegularExpression('/^\+?[\d\s\-\(\)]+$/', $value);
    }
    
    public function test_generate_with_constraints()
    {
        $constraints = [
            'min' => 10,
            'max' => 100,
        ];
        
        $value = $this->generator->generateField('integer', 'quantity', $constraints);
        
        $this->assertGreaterThanOrEqual(10, $value);
        $this->assertLessThanOrEqual(100, $value);
    }
    
    public function test_generate_with_enum_constraint()
    {
        $constraints = [
            'in' => ['active', 'inactive', 'pending'],
        ];
        
        $value = $this->generator->generateField('string', 'status', $constraints);
        
        $this->assertContains($value, ['active', 'inactive', 'pending']);
    }
    
    public function test_generate_with_pattern_constraint()
    {
        $constraints = [
            'pattern' => '/^[A-Z]{3}-\d{4}$/',
        ];
        
        $value = $this->generator->generateField('string', 'code', $constraints);
        
        $this->assertMatchesRegularExpression('/^[A-Z]{3}-\d{4}$/', $value);
    }
    
    public function test_generate_unique_values()
    {
        $values = [];
        for ($i = 0; $i < 100; $i++) {
            $values[] = $this->generator->generateField('string', 'unique_id', ['unique' => true]);
        }
        
        $this->assertDataUniqueness($values, 1.0); // 100% unique
    }
    
    public function test_generate_nullable_field()
    {
        $nullCount = 0;
        $iterations = 100;
        
        for ($i = 0; $i < $iterations; $i++) {
            $value = $this->generator->generateField('string', 'optional_field', ['nullable' => true]);
            if ($value === null) {
                $nullCount++;
            }
        }
        
        // Should have some null values
        $this->assertGreaterThan(0, $nullCount);
        $this->assertLessThan($iterations, $nullCount);
    }
    
    public function test_generate_for_model()
    {
        $modelClass = TestModelFactory::create('Product', [
            'name' => 'string',
            'price' => 'decimal',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ]);
        
        $data = $this->generator->generateForModel($modelClass);
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('price', $data);
        $this->assertArrayHasKey('stock', $data);
        $this->assertArrayHasKey('is_active', $data);
    }
    
    public function test_generate_batch_data()
    {
        $modelClass = TestModelFactory::create('Item', [
            'name' => 'string',
            'quantity' => 'integer',
        ]);
        
        $count = 10;
        $batch = $this->generator->generateBatch($modelClass, $count);
        
        $this->assertIsArray($batch);
        $this->assertCount($count, $batch);
        
        foreach ($batch as $item) {
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('quantity', $item);
        }
    }
    
    public function test_generate_with_relationships()
    {
        $userClass = TestModelFactory::create('User', [
            'name' => 'string',
        ]);
        
        $postClass = TestModelFactory::create('Post', [
            'title' => 'string',
            'user_id' => 'integer',
        ], [
            'user' => ['type' => 'belongsTo', 'model' => $userClass],
        ]);
        
        // Create a user first
        $user = $userClass::create(['name' => 'Test User']);
        
        $data = $this->generator->generateForModel($postClass, [
            'relationships' => ['user' => $user],
        ]);
        
        $this->assertEquals($user->id, $data['user_id']);
    }
    
    public function test_generate_with_custom_generator()
    {
        $customGenerator = function($field, $type) {
            return "custom_{$field}";
        };
        
        $this->generator->registerCustomGenerator('custom_type', $customGenerator);
        
        $value = $this->generator->generateField('custom_type', 'field');
        
        $this->assertEquals('custom_field', $value);
    }
    
    public function test_generate_respects_configuration()
    {
        Config::set('mint.generation.defaults.string_length', 50);
        Config::set('mint.generation.defaults.integer_min', 100);
        Config::set('mint.generation.defaults.integer_max', 1000);
        
        $stringValue = $this->generator->generateField('string', 'text');
        $intValue = $this->generator->generateField('integer', 'number');
        
        $this->assertLessThanOrEqual(50, strlen($stringValue));
        $this->assertGreaterThanOrEqual(100, $intValue);
        $this->assertLessThanOrEqual(1000, $intValue);
    }
    
    public function test_generate_with_faker_locale()
    {
        Config::set('app.faker_locale', 'fr_FR');
        
        $generator = new DataGenerator(
            new SimpleGenerator(),
            new PatternAwareGenerator($this->patternRegistry)
        );
        
        $value = $generator->generateField('string', 'address');
        
        $this->assertIsString($value);
        // French addresses might contain French-specific words
    }
    
    public function test_generate_handles_complex_types()
    {
        $types = [
            'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            'ipv4' => '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/',
            'mac_address' => '/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
            'slug' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        ];
        
        foreach ($types as $type => $pattern) {
            $value = $this->generator->generateField('string', $type);
            $this->assertMatchesRegularExpression($pattern, $value, "Failed for type: {$type}");
        }
    }
    
    public function test_generate_incremental_values()
    {
        $values = [];
        for ($i = 0; $i < 10; $i++) {
            $values[] = $this->generator->generateField('integer', 'sequence', [
                'incremental' => true,
                'start' => 1000,
            ]);
        }
        
        for ($i = 1; $i < count($values); $i++) {
            $this->assertGreaterThan($values[$i - 1], $values[$i]);
        }
    }
    
    public function test_generate_weighted_random()
    {
        $weights = [
            'common' => 70,
            'uncommon' => 20,
            'rare' => 10,
        ];
        
        $results = [];
        for ($i = 0; $i < 1000; $i++) {
            $value = $this->generator->generateField('string', 'rarity', [
                'weighted' => $weights,
            ]);
            $results[$value] = ($results[$value] ?? 0) + 1;
        }
        
        // Common should appear most frequently
        $this->assertGreaterThan($results['uncommon'], $results['common']);
        $this->assertGreaterThan($results['rare'], $results['uncommon']);
    }
}