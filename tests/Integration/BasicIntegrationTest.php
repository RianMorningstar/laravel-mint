<?php

namespace LaravelMint\Tests\Integration;

use LaravelMint\Tests\TestCase;
use LaravelMint\Mint;
use LaravelMint\MintServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class BasicIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a simple test table
        Schema::create('test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('value')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
    
    protected function tearDown(): void
    {
        Schema::dropIfExists('test_models');
        parent::tearDown();
    }
    
    public function test_service_provider_loads_correctly()
    {
        $this->assertTrue($this->app->providerIsLoaded(MintServiceProvider::class));
    }
    
    public function test_mint_facade_is_accessible()
    {
        $mint = $this->app->make('mint');
        $this->assertInstanceOf(Mint::class, $mint);
    }
    
    public function test_configuration_is_loaded()
    {
        $config = Config::get('mint');
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('generation', $config);
        $this->assertArrayHasKey('patterns', $config);
    }
    
    public function test_commands_are_registered()
    {
        $expectedCommands = [
            'mint:generate',
            'mint:analyze',
            'mint:clear',
        ];
        
        $registeredCommands = Artisan::all();
        
        foreach ($expectedCommands as $command) {
            $this->assertArrayHasKey(
                $command,
                $registeredCommands,
                "Command {$command} is not registered"
            );
        }
    }
    
    public function test_basic_data_generation()
    {
        // Create a simple model class for testing
        eval('
            class TestModel extends \Illuminate\Database\Eloquent\Model {
                protected $table = "test_models";
                protected $fillable = ["name", "value", "is_active"];
            }
        ');
        
        $mint = $this->app->make(Mint::class);
        
        // Generate some records
        $records = $mint->generate('TestModel', 5);
        
        $this->assertCount(5, $records);
        $this->assertEquals(5, \TestModel::count());
        
        // Verify each record has expected fields
        foreach ($records as $record) {
            $this->assertNotEmpty($record->name);
            $this->assertIsInt($record->value);
            $this->assertIsBool($record->is_active);
        }
    }
    
    public function test_data_generation_with_custom_attributes()
    {
        eval('
            class TestModelCustom extends \Illuminate\Database\Eloquent\Model {
                protected $table = "test_models";
                protected $fillable = ["name", "value", "is_active"];
            }
        ');
        
        $mint = $this->app->make(Mint::class);
        
        // Generate with custom attributes
        $records = $mint->generate('TestModelCustom', 3, [
            'value' => 100,
            'is_active' => false,
        ]);
        
        foreach ($records as $record) {
            $this->assertEquals(100, $record->value);
            $this->assertFalse($record->is_active);
        }
    }
    
    public function test_clear_functionality()
    {
        eval('
            class TestModelClear extends \Illuminate\Database\Eloquent\Model {
                protected $table = "test_models";
                protected $fillable = ["name", "value", "is_active"];
            }
        ');
        
        $mint = $this->app->make(Mint::class);
        
        // Generate and then clear
        $mint->generate('TestModelClear', 10);
        $this->assertEquals(10, \TestModelClear::count());
        
        $mint->clear('TestModelClear');
        $this->assertEquals(0, \TestModelClear::count());
    }
    
    public function test_analyze_functionality()
    {
        eval('
            class TestModelAnalyze extends \Illuminate\Database\Eloquent\Model {
                protected $table = "test_models";
                protected $fillable = ["name", "value", "is_active"];
                protected $casts = [
                    "is_active" => "boolean",
                    "value" => "integer",
                ];
            }
        ');
        
        $mint = $this->app->make(Mint::class);
        
        $analysis = $mint->analyze('TestModelAnalyze');
        
        $this->assertIsArray($analysis);
        $this->assertArrayHasKey('model', $analysis);
        $this->assertArrayHasKey('table', $analysis);
        $this->assertArrayHasKey('attributes', $analysis);
        $this->assertEquals('test_models', $analysis['table']);
    }
    
    public function test_batch_generation()
    {
        eval('
            class TestModelBatch1 extends \Illuminate\Database\Eloquent\Model {
                protected $table = "test_models";
                protected $fillable = ["name", "value", "is_active"];
            }
            
            class TestModelBatch2 extends \Illuminate\Database\Eloquent\Model {
                protected $table = "test_models";
                protected $fillable = ["name", "value", "is_active"];
            }
        ');
        
        $mint = $this->app->make(Mint::class);
        
        $batch = [
            'TestModelBatch1' => 5,
            'TestModelBatch2' => 3,
        ];
        
        $results = $mint->generateBatch($batch);
        
        $this->assertArrayHasKey('TestModelBatch1', $results);
        $this->assertArrayHasKey('TestModelBatch2', $results);
        $this->assertCount(5, $results['TestModelBatch1']);
        $this->assertCount(3, $results['TestModelBatch2']);
    }
    
    public function test_performance_for_moderate_dataset()
    {
        eval('
            class TestModelPerformance extends \Illuminate\Database\Eloquent\Model {
                protected $table = "test_models";
                protected $fillable = ["name", "value", "is_active"];
            }
        ');
        
        $mint = $this->app->make(Mint::class);
        
        $startTime = microtime(true);
        $mint->generate('TestModelPerformance', 100);
        $duration = microtime(true) - $startTime;
        
        $this->assertEquals(100, \TestModelPerformance::count());
        $this->assertLessThan(5, $duration, "Generation took too long: {$duration} seconds");
    }
}