<?php

namespace LaravelMint\Tests\Unit;

use LaravelMint\Patterns\AbstractPattern;
use LaravelMint\Patterns\CompositePattern;
use LaravelMint\Patterns\Distributions\ExponentialDistribution;
use LaravelMint\Patterns\Distributions\NormalDistribution;
use LaravelMint\Patterns\PatternInterface;
use LaravelMint\Patterns\PatternRegistry;
use LaravelMint\Patterns\Temporal\SeasonalPattern;
use LaravelMint\Patterns\Temporal\WeeklyPattern;
use LaravelMint\Tests\Helpers\AssertionHelpers;
use LaravelMint\Tests\TestCase;
use Mockery;

class PatternRegistryTest extends TestCase
{
    use AssertionHelpers;

    protected PatternRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new PatternRegistry;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_registry_instance_is_created()
    {
        $this->assertInstanceOf(PatternRegistry::class, $this->registry);
    }

    public function test_register_pattern()
    {
        $pattern = Mockery::mock(PatternInterface::class);
        $pattern->shouldReceive('getName')->andReturn('test-pattern');

        $this->registry->register('test-pattern', $pattern);

        $this->assertTrue($this->registry->has('test-pattern'));
    }

    public function test_get_registered_pattern()
    {
        $pattern = Mockery::mock(PatternInterface::class);
        $pattern->shouldReceive('getName')->andReturn('test-pattern');

        $this->registry->register('test-pattern', $pattern);

        $retrieved = $this->registry->get('test-pattern');

        $this->assertSame($pattern, $retrieved);
    }

    public function test_get_nonexistent_pattern_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Pattern not found: nonexistent');

        $this->registry->get('nonexistent');
    }

    public function test_list_all_patterns()
    {
        $pattern1 = Mockery::mock(PatternInterface::class);
        $pattern1->shouldReceive('getName')->andReturn('pattern1');

        $pattern2 = Mockery::mock(PatternInterface::class);
        $pattern2->shouldReceive('getName')->andReturn('pattern2');

        $this->registry->register('pattern1', $pattern1);
        $this->registry->register('pattern2', $pattern2);

        $patterns = $this->registry->all();

        $this->assertCount(2, $patterns);
        $this->assertArrayHasKey('pattern1', $patterns);
        $this->assertArrayHasKey('pattern2', $patterns);
    }

    public function test_remove_pattern()
    {
        $pattern = Mockery::mock(PatternInterface::class);
        $pattern->shouldReceive('getName')->andReturn('removable');

        $this->registry->register('removable', $pattern);
        $this->assertTrue($this->registry->has('removable'));

        $this->registry->remove('removable');
        $this->assertFalse($this->registry->has('removable'));
    }

    public function test_normal_distribution_pattern()
    {
        $pattern = new NormalDistribution([
            'mean' => 100,
            'stddev' => 15,
        ]);

        $this->registry->register('normal', $pattern);

        $values = [];
        for ($i = 0; $i < 1000; $i++) {
            $values[] = $pattern->generate();
        }

        $this->assertDataDistribution($values, 100, 0.15);
    }

    public function test_exponential_distribution_pattern()
    {
        $pattern = new ExponentialDistribution([
            'lambda' => 0.5,
        ]);

        $this->registry->register('exponential', $pattern);

        $values = [];
        for ($i = 0; $i < 1000; $i++) {
            $values[] = $pattern->generate();
        }

        // Exponential distribution with lambda=0.5 has mean=2
        $this->assertDataDistribution($values, 2, 0.3);
    }

    public function test_seasonal_pattern()
    {
        $pattern = new SeasonalPattern([
            'peaks' => [6, 12], // June and December
            'amplitude' => 0.5,
            'base_value' => 100,
        ]);

        $this->registry->register('seasonal', $pattern);

        // Test peak months
        $juneValue = $pattern->generateForDate(new \DateTime('2024-06-15'));
        $decemberValue = $pattern->generateForDate(new \DateTime('2024-12-15'));

        // Test trough months
        $marchValue = $pattern->generateForDate(new \DateTime('2024-03-15'));
        $septemberValue = $pattern->generateForDate(new \DateTime('2024-09-15'));

        $this->assertGreaterThan($marchValue, $juneValue);
        $this->assertGreaterThan($septemberValue, $decemberValue);
    }

    public function test_weekly_pattern()
    {
        $pattern = new WeeklyPattern([
            'weekday_multiplier' => 1.0,
            'weekend_multiplier' => 1.5,
            'base_value' => 100,
        ]);

        $this->registry->register('weekly', $pattern);

        $mondayValue = $pattern->generateForDate(new \DateTime('2024-01-01')); // Monday
        $saturdayValue = $pattern->generateForDate(new \DateTime('2024-01-06')); // Saturday

        $this->assertGreaterThan($mondayValue, $saturdayValue);
    }

    public function test_composite_pattern()
    {
        $pattern1 = Mockery::mock(PatternInterface::class);
        $pattern1->shouldReceive('generate')->andReturn(100);
        $pattern1->shouldReceive('getName')->andReturn('pattern1');

        $pattern2 = Mockery::mock(PatternInterface::class);
        $pattern2->shouldReceive('generate')->andReturn(50);
        $pattern2->shouldReceive('getName')->andReturn('pattern2');

        $composite = new CompositePattern([
            'patterns' => [$pattern1, $pattern2],
            'weights' => [0.7, 0.3],
            'combination' => 'weighted_sum',
        ]);

        $this->registry->register('composite', $composite);

        $value = $composite->generate();

        // Should be 100 * 0.7 + 50 * 0.3 = 70 + 15 = 85
        $this->assertEquals(85, $value);
    }

    public function test_pattern_with_constraints()
    {
        $pattern = new NormalDistribution([
            'mean' => 50,
            'stddev' => 10,
            'min' => 0,
            'max' => 100,
        ]);

        $this->registry->register('constrained', $pattern);

        for ($i = 0; $i < 100; $i++) {
            $value = $pattern->generate();
            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThanOrEqual(100, $value);
        }
    }

    public function test_pattern_configuration()
    {
        $config = [
            'mean' => 75,
            'stddev' => 5,
        ];

        $pattern = new NormalDistribution($config);

        $this->assertEquals($config, $pattern->getConfiguration());
    }

    public function test_pattern_validation()
    {
        $this->expectException(\InvalidArgumentException::class);

        // Invalid configuration - missing required parameter
        new NormalDistribution([
            'stddev' => 10,
            // missing 'mean'
        ]);
    }

    public function test_register_custom_pattern_class()
    {
        $customPattern = new class(['multiplier' => 2]) extends AbstractPattern
        {
            public function generate(array $context = []): mixed
            {
                return rand(1, 10) * $this->config['multiplier'];
            }

            public function getName(): string
            {
                return 'custom-multiplier';
            }

            protected function validateConfig(array $config): void
            {
                if (! isset($config['multiplier'])) {
                    throw new \InvalidArgumentException('Multiplier is required');
                }
            }

            protected function initialize(): void
            {
                // No special initialization needed
            }
        };

        $this->registry->register('custom', $customPattern);

        $value = $this->registry->get('custom')->generate();
        $this->assertIsInt($value);
        $this->assertGreaterThanOrEqual(2, $value);
        $this->assertLessThanOrEqual(20, $value);
    }

    public function test_pattern_serialization()
    {
        $pattern = new NormalDistribution([
            'mean' => 100,
            'stddev' => 20,
        ]);

        $serialized = serialize($pattern);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(NormalDistribution::class, $unserialized);
        $this->assertEquals($pattern->getConfiguration(), $unserialized->getConfiguration());
    }

    public function test_load_patterns_from_config()
    {
        $config = [
            'normal' => [
                'class' => NormalDistribution::class,
                'config' => ['mean' => 50, 'stddev' => 10],
            ],
            'exponential' => [
                'class' => ExponentialDistribution::class,
                'config' => ['lambda' => 0.1],
            ],
        ];

        $this->registry->loadFromConfig($config);

        $this->assertTrue($this->registry->has('normal'));
        $this->assertTrue($this->registry->has('exponential'));
    }

    public function test_pattern_factory()
    {
        $factory = function ($config) {
            return new NormalDistribution($config);
        };

        $this->registry->registerFactory('factory-pattern', $factory, [
            'mean' => 100,
            'stddev' => 15,
        ]);

        $pattern = $this->registry->get('factory-pattern');
        $this->assertInstanceOf(NormalDistribution::class, $pattern);
    }
}
