<?php

namespace LaravelMint\Tests\Unit;

use Illuminate\Support\Facades\Config;
use LaravelMint\Scenarios\BaseScenario;
use LaravelMint\Scenarios\Presets\EcommerceScenario;
use LaravelMint\Scenarios\Presets\SaaSScenario;
use LaravelMint\Scenarios\ScenarioBuilder;
use LaravelMint\Scenarios\ScenarioInterface;
use LaravelMint\Scenarios\ScenarioManager;
use LaravelMint\Scenarios\ScenarioResult;
use LaravelMint\Scenarios\ScenarioValidator;
use LaravelMint\Tests\Helpers\AssertionHelpers;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Tests\TestCase;
use LaravelMint\Mint;
use Mockery;

class ScenarioManagerTest extends TestCase
{
    use AssertionHelpers;

    protected ScenarioManager $manager;

    protected ScenarioBuilder $builder;

    protected ScenarioValidator $validator;

    protected Mint $mint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mint = $this->app->make(Mint::class);
        $this->builder = new ScenarioBuilder;
        $this->validator = new ScenarioValidator;
        $this->manager = new ScenarioManager($this->mint);
    }

    protected function tearDown(): void
    {
        TestModelFactory::cleanup();
        Mockery::close();
        parent::tearDown();
    }

    public function test_manager_instance_is_created()
    {
        $this->assertInstanceOf(ScenarioManager::class, $this->manager);
    }

    public function test_register_scenario()
    {
        $scenario = Mockery::mock(ScenarioInterface::class);
        $scenario->shouldReceive('getName')->andReturn('test-scenario');
        $scenario->shouldReceive('getDescription')->andReturn('Test scenario');

        $this->manager->register('test-scenario', $scenario);

        $this->assertTrue($this->manager->has('test-scenario'));
    }

    public function test_get_registered_scenario()
    {
        $scenario = Mockery::mock(ScenarioInterface::class);
        $scenario->shouldReceive('getName')->andReturn('test-scenario');
        $scenario->shouldReceive('getDescription')->andReturn('Test scenario');

        $this->manager->register('test-scenario', $scenario);

        $retrieved = $this->manager->get('test-scenario');

        $this->assertSame($scenario, $retrieved);
    }

    public function test_list_all_scenarios()
    {
        $scenario1 = Mockery::mock(ScenarioInterface::class);
        $scenario1->shouldReceive('getName')->andReturn('scenario1');
        $scenario1->shouldReceive('getDescription')->andReturn('First scenario');

        $scenario2 = Mockery::mock(ScenarioInterface::class);
        $scenario2->shouldReceive('getName')->andReturn('scenario2');
        $scenario2->shouldReceive('getDescription')->andReturn('Second scenario');

        $this->manager->register('scenario1', $scenario1);
        $this->manager->register('scenario2', $scenario2);

        $list = $this->manager->list();

        $this->assertCount(2, $list);
        $this->assertArrayHasKey('scenario1', $list);
        $this->assertArrayHasKey('scenario2', $list);
        $this->assertEquals('First scenario', $list['scenario1']['description']);
    }

    public function test_run_scenario()
    {
        $result = new ScenarioResult(true, [
            'records_created' => 100,
            'duration' => 1.5,
        ]);

        $scenario = Mockery::mock(ScenarioInterface::class);
        $scenario->shouldReceive('getName')->andReturn('test-scenario');
        $scenario->shouldReceive('getDescription')->andReturn('Test scenario');
        $scenario->shouldReceive('run')->once()->andReturn($result);

        $this->manager->register('test-scenario', $scenario);

        $executionResult = $this->manager->run('test-scenario');

        $this->assertTrue($executionResult->isSuccessful());
        $this->assertEquals(100, $executionResult->getData()['records_created']);
    }

    public function test_run_scenario_with_options()
    {
        $options = ['scale' => 2, 'seed' => 12345];

        $scenario = Mockery::mock(ScenarioInterface::class);
        $scenario->shouldReceive('getName')->andReturn('configurable');
        $scenario->shouldReceive('getDescription')->andReturn('Configurable scenario');
        $scenario->shouldReceive('run')
            ->with($options)
            ->once()
            ->andReturn(new ScenarioResult(true));

        $this->manager->register('configurable', $scenario);

        $result = $this->manager->run('configurable', $options);

        $this->assertTrue($result->isSuccessful());
    }

    public function test_scenario_builder_creates_scenario()
    {
        $config = [
            'name' => 'custom-scenario',
            'description' => 'Custom test scenario',
            'steps' => [
                [
                    'model' => 'User',
                    'count' => 10,
                    'attributes' => ['status' => 'active'],
                ],
                [
                    'model' => 'Post',
                    'count' => 50,
                    'relationships' => ['user'],
                ],
            ],
        ];

        $scenario = $this->builder->build($config);

        $this->assertInstanceOf(ScenarioInterface::class, $scenario);
        $this->assertEquals('custom-scenario', $scenario->getName());
    }

    public function test_scenario_validator_validates_config()
    {
        $validScenario = Mockery::mock(ScenarioInterface::class);
        $validScenario->shouldReceive('getName')->andReturn('valid-scenario');
        $validScenario->shouldReceive('getDescription')->andReturn('Valid scenario');
        $validScenario->shouldReceive('getRequiredModels')->andReturn([]);
        $validScenario->shouldReceive('getOptionalModels')->andReturn([]);
        $validScenario->shouldReceive('getParameters')->andReturn([]);
        $validScenario->shouldReceive('validate')->andReturn(true);
        $validScenario->shouldReceive('getValidationErrors')->andReturn([]);

        $invalidScenario = Mockery::mock(ScenarioInterface::class);
        $invalidScenario->shouldReceive('getName')->andReturn('');
        $invalidScenario->shouldReceive('getDescription')->andReturn('');
        $invalidScenario->shouldReceive('getRequiredModels')->andReturn([]);
        $invalidScenario->shouldReceive('getOptionalModels')->andReturn([]);
        $invalidScenario->shouldReceive('getParameters')->andReturn([]);
        $invalidScenario->shouldReceive('validate')->andReturn(false);
        $invalidScenario->shouldReceive('getValidationErrors')->andReturn(['Invalid scenario']);

        $validResult = $this->validator->validate($validScenario);
        $invalidResult = $this->validator->validate($invalidScenario);

        $this->assertTrue($validResult->isValid());
        $this->assertFalse($invalidResult->isValid());
    }

    public function test_base_scenario_implementation()
    {
        $scenario = new class extends BaseScenario
        {
            protected string $name = 'test-base';

            protected string $description = 'Test base scenario';

            protected function initialize(): void
            {
                // Initialize test scenario
            }

            protected function execute(): void
            {
                // Execute test scenario
                $this->generatedData['User'] = collect(range(1, 5));
                $this->generatedData['Post'] = collect(range(1, 10));
            }

            protected function defineSteps(): array
            {
                return [
                    ['model' => 'User', 'count' => 5],
                    ['model' => 'Post', 'count' => 10],
                ];
            }
        };

        $this->assertEquals('test-base', $scenario->getName());
        $this->assertEquals('Test base scenario', $scenario->getDescription());

        // Mock models for testing
        TestModelFactory::create('User', ['name' => 'string']);
        TestModelFactory::create('Post', ['title' => 'string']);

        $result = $scenario->run();

        $this->assertInstanceOf(ScenarioResult::class, $result);
    }

    public function test_ecommerce_scenario_preset()
    {
        $scenario = new EcommerceScenario;

        $this->assertEquals('ecommerce', $scenario->getName());
        $this->assertStringContainsString('e-commerce', strtolower($scenario->getDescription()));

        // Test that it defines proper steps
        $reflection = new \ReflectionClass($scenario);
        $method = $reflection->getMethod('defineSteps');
        $method->setAccessible(true);
        $steps = $method->invoke($scenario);

        $this->assertNotEmpty($steps);

        // Should include typical e-commerce models
        $models = array_column($steps, 'model');
        $this->assertContains('User', $models);
        $this->assertContains('Product', $models);
        $this->assertContains('Order', $models);
    }

    public function test_saas_scenario_preset()
    {
        $scenario = new SaaSScenario;

        $this->assertEquals('saas', $scenario->getName());
        $this->assertStringContainsString('saas', strtolower($scenario->getDescription()));

        // Test that it defines proper steps
        $reflection = new \ReflectionClass($scenario);
        $method = $reflection->getMethod('defineSteps');
        $method->setAccessible(true);
        $steps = $method->invoke($scenario);

        $this->assertNotEmpty($steps);

        // Should include typical SaaS models
        $models = array_column($steps, 'model');
        $this->assertContains('User', $models);
        $this->assertContains('Subscription', $models);
        $this->assertContains('Plan', $models);
    }

    public function test_scenario_with_patterns()
    {
        $scenario = new class extends BaseScenario
        {
            protected string $name = 'pattern-scenario';

            protected function initialize(): void
            {
                // Initialize test scenario
            }

            protected function execute(): void
            {
                // Execute test scenario with patterns
                $this->generatedData['User'] = collect(range(1, 100));
            }

            protected function defineSteps(): array
            {
                return [
                    [
                        'model' => 'User',
                        'count' => 100,
                        'pattern' => 'normal',
                        'pattern_config' => [
                            'field' => 'age',
                            'mean' => 35,
                            'stddev' => 10,
                        ],
                    ],
                ];
            }
        };

        TestModelFactory::create('User', ['name' => 'string', 'age' => 'integer']);

        $result = $scenario->run();

        $this->assertTrue($result->isSuccessful());
    }

    public function test_scenario_with_callbacks()
    {
        $callbackState = ['before' => false, 'after' => false];

        $scenario = new class($callbackState) extends BaseScenario
        {
            protected string $name = 'callback-scenario';

            private array $state;

            public function __construct(array &$state)
            {
                $this->state = &$state;
                parent::__construct();
            }

            protected function initialize(): void
            {
                // Initialize test scenario
            }

            protected function execute(): void
            {
                // Execute test scenario
                $this->generatedData['User'] = collect([1]);
            }

            protected function defineSteps(): array
            {
                return [
                    ['model' => 'User', 'count' => 1],
                ];
            }

            protected function beforeRun(): void
            {
                $this->state['before'] = true;
            }

            protected function afterRun(ScenarioResult $result): void
            {
                $this->state['after'] = true;
            }
        };

        TestModelFactory::create('User', ['name' => 'string']);

        $scenario->run();

        $this->assertTrue($callbackState['before']);
        $this->assertTrue($callbackState['after']);
    }

    public function test_scenario_error_handling()
    {
        $scenario = new class extends BaseScenario
        {
            protected string $name = 'error-scenario';

            protected function initialize(): void
            {
                // Initialize test scenario
            }

            protected function execute(): void
            {
                // This will fail as NonExistentModel doesn't exist
                throw new \Exception('Model not found');
            }

            protected function defineSteps(): array
            {
                return [
                    ['model' => 'NonExistentModel', 'count' => 10],
                ];
            }
        };

        $result = $scenario->run();

        $this->assertFalse($result->isSuccessful());
        $this->assertArrayHasKey('error', $result->getData());
    }

    public function test_scenario_with_transactions()
    {
        $scenario = new class extends BaseScenario
        {
            protected string $name = 'transaction-scenario';

            protected bool $useTransaction = true;

            protected function initialize(): void
            {
                // Initialize test scenario
            }

            protected function execute(): void
            {
                // Execute test scenario with transaction
                $this->generatedData['User'] = collect(range(1, 5));
                $this->generatedData['Post'] = collect(range(1, 10));
            }

            protected function defineSteps(): array
            {
                return [
                    ['model' => 'User', 'count' => 5],
                    ['model' => 'Post', 'count' => 10],
                ];
            }
        };

        TestModelFactory::create('User', ['name' => 'string']);
        TestModelFactory::create('Post', ['title' => 'string']);

        $result = $scenario->run();

        $this->assertTrue($result->isSuccessful());
    }

    public function test_load_scenarios_from_config()
    {
        Config::set('mint.scenarios.custom', [
            'class' => EcommerceScenario::class,
            'enabled' => true,
        ]);

        $this->manager->loadFromConfig();

        $this->assertTrue($this->manager->has('custom'));
    }

    public function test_scenario_result_object()
    {
        $result = new ScenarioResult(true, [
            'records' => 100,
            'duration' => 2.5,
            'memory' => 1024,
        ]);

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(100, $result->getData()['records']);
        $this->assertEquals(2.5, $result->getData()['duration']);

        // Test with failure
        $failureResult = new ScenarioResult(false, [
            'error' => 'Something went wrong',
        ]);

        $this->assertFalse($failureResult->isSuccessful());
        $this->assertEquals('Something went wrong', $failureResult->getData()['error']);
    }

    public function test_scenario_performance()
    {
        $scenario = new class extends BaseScenario
        {
            protected string $name = 'performance-scenario';

            protected function initialize(): void
            {
                // Initialize test scenario
            }

            protected function execute(): void
            {
                // Execute test scenario for performance
                $this->generatedData['User'] = collect(range(1, 1000));
            }

            protected function defineSteps(): array
            {
                return [
                    ['model' => 'User', 'count' => 1000],
                ];
            }
        };

        TestModelFactory::create('User', ['name' => 'string']);

        $this->assertPerformance(
            fn () => $scenario->run(),
            maxSeconds: 5.0,
            maxMemoryMb: 100
        );
    }
}
