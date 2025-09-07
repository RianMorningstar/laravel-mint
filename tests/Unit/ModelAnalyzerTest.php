<?php

namespace LaravelMint\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Analyzers\RelationshipMapper;
use LaravelMint\Analyzers\SchemaInspector;
use LaravelMint\Tests\Helpers\AssertionHelpers;
use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Tests\TestCase;
use LaravelMint\Mint;
use Mockery;

class ModelAnalyzerTest extends TestCase
{
    use AssertionHelpers;

    protected ModelAnalyzer $analyzer;

    protected SchemaInspector $schemaInspector;

    protected RelationshipMapper $relationshipMapper;

    protected Mint $mint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mint = $this->app->make(Mint::class);
        $this->schemaInspector = new SchemaInspector($this->mint);
        $this->relationshipMapper = new RelationshipMapper($this->mint);
        $this->analyzer = new ModelAnalyzer($this->mint);

        DatabaseSeeder::setupTestDatabase();
    }

    protected function tearDown(): void
    {
        DatabaseSeeder::cleanupTestDatabase();
        TestModelFactory::cleanup();
        Mockery::close();
        parent::tearDown();
    }

    public function test_analyzer_instance_is_created()
    {
        $this->assertInstanceOf(ModelAnalyzer::class, $this->analyzer);
    }

    public function test_analyze_model_structure()
    {
        $modelClass = TestModelFactory::create('Product', [
            'name' => 'string',
            'price' => 'decimal',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ]);

        $analysis = $this->analyzer->analyze($modelClass);

        $this->assertIsArray($analysis);
        $this->assertArrayHasKey('model', $analysis);
        $this->assertArrayHasKey('table', $analysis);
        $this->assertArrayHasKey('attributes', $analysis);
        $this->assertArrayHasKey('fillable', $analysis);
        $this->assertArrayHasKey('guarded', $analysis);
        $this->assertArrayHasKey('casts', $analysis);
        $this->assertArrayHasKey('relationships', $analysis);
    }

    public function test_detect_attribute_types()
    {
        $modelClass = TestModelFactory::create('TestModel', [
            'string_field' => 'string',
            'integer_field' => 'integer',
            'boolean_field' => 'boolean',
            'decimal_field' => 'decimal',
            'datetime_field' => 'datetime',
            'json_field' => 'json',
        ]);

        // Check that table exists
        $instance = new $modelClass;
        $tableName = $instance->getTable();
        $this->assertTrue(Schema::hasTable($tableName), "Table {$tableName} does not exist");
        
        // Check columns exist
        $columns = Schema::getColumnListing($tableName);
        $this->assertContains('string_field', $columns, 'Column string_field not found. Available columns: ' . implode(', ', $columns));
        
        $analysis = $this->analyzer->analyze($modelClass);
        $attributes = $analysis['attributes'];

        // Check that attributes exist
        $this->assertArrayHasKey('string_field', $attributes, 'Missing string_field. Available: ' . implode(', ', array_keys($attributes)));
        $this->assertEquals('string', $attributes['string_field']['type']);
        $this->assertEquals('integer', $attributes['integer_field']['type']);
        $this->assertEquals('boolean', $attributes['boolean_field']['type']);
        $this->assertEquals('decimal', $attributes['decimal_field']['type']);
        $this->assertEquals('datetime', $attributes['datetime_field']['type']);
        $this->assertEquals('json', $attributes['json_field']['type']);
    }

    public function test_detect_nullable_fields()
    {
        $modelClass = TestModelFactory::create('NullableTest', [
            'required_field' => 'string',
            'optional_field' => 'string',
        ]);

        // Both fields are created as nullable by TestModelFactory
        // Let's change required_field to NOT NULL
        $connection = app('db')->connection();
        $connection->statement('CREATE TABLE nullabletests_new AS SELECT * FROM nullabletests WHERE 0');
        $connection->statement('DROP TABLE nullabletests');
        $connection->statement('CREATE TABLE nullabletests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            required_field VARCHAR(255) NOT NULL,
            optional_field VARCHAR(255),
            created_at DATETIME,
            updated_at DATETIME
        )');

        $analysis = $this->analyzer->analyze($modelClass);
        $attributes = $analysis['attributes'];

        $this->assertFalse($attributes['required_field']['nullable'] ?? true);
        $this->assertTrue($attributes['optional_field']['nullable'] ?? false);
    }

    public function test_detect_unique_constraints()
    {
        $modelClass = TestModelFactory::create('UniqueTest', [
            'email' => 'string',
            'username' => 'string',
        ]);

        // Add unique index
        Schema::table('uniquetests', function ($table) {
            $table->unique('email');
            $table->unique('username');
        });

        $analysis = $this->analyzer->analyze($modelClass);
        $attributes = $analysis['attributes'];

        $this->assertTrue($attributes['email']['unique'] ?? false);
        $this->assertTrue($attributes['username']['unique'] ?? false);
    }

    public function test_detect_default_values()
    {
        $modelClass = TestModelFactory::create('DefaultTest', [
            'status' => 'string',
            'count' => 'integer',
        ]);

        // Set defaults
        Schema::table('defaulttests', function ($table) {
            $table->string('status')->default('active')->change();
            $table->integer('count')->default(0)->change();
        });

        $analysis = $this->analyzer->analyze($modelClass);
        $attributes = $analysis['attributes'];

        $this->assertEquals('active', $attributes['status']['default'] ?? null);
        $this->assertEquals(0, $attributes['count']['default'] ?? null);
    }

    public function test_detect_belongs_to_relationship()
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

        $analysis = $this->analyzer->analyze($postClass);
        $relationships = $analysis['relationships'];

        $this->assertArrayHasKey('user', $relationships);
        $this->assertEquals('belongsTo', $relationships['user']['type']);
        $this->assertEquals($userClass, $relationships['user']['model']);
    }

    public function test_detect_has_many_relationship()
    {
        $postClass = TestModelFactory::create('Post', [
            'title' => 'string',
        ]);

        $commentClass = TestModelFactory::create('Comment', [
            'content' => 'text',
            'post_id' => 'integer',
        ], [
            'post' => ['type' => 'belongsTo', 'model' => $postClass],
        ]);

        // Update Post model to have comments relationship
        $postClass = TestModelFactory::create('Post', [
            'title' => 'string',
        ], [
            'comments' => ['type' => 'hasMany', 'model' => $commentClass],
        ]);

        $analysis = $this->analyzer->analyze($postClass);
        $relationships = $analysis['relationships'];

        $this->assertArrayHasKey('comments', $relationships);
        $this->assertEquals('hasMany', $relationships['comments']['type']);
    }

    public function test_detect_many_to_many_relationship()
    {
        $userClass = TestModelFactory::create('User', [
            'name' => 'string',
        ]);

        $roleClass = TestModelFactory::create('Role', [
            'name' => 'string',
        ], [
            'users' => ['type' => 'belongsToMany', 'model' => $userClass],
        ]);

        $analysis = $this->analyzer->analyze($roleClass);
        $relationships = $analysis['relationships'];

        $this->assertArrayHasKey('users', $relationships);
        $this->assertEquals('belongsToMany', $relationships['users']['type']);
    }

    public function test_detect_indexes()
    {
        $modelClass = TestModelFactory::create('IndexTest', [
            'email' => 'string',
            'status' => 'string',
            'created_at' => 'datetime',
        ]);

        Schema::table('indextests', function ($table) {
            $table->index('status');
            $table->index(['status', 'created_at']);
        });

        $analysis = $this->analyzer->analyze($modelClass);
        $indexes = $analysis['indexes'] ?? [];

        $this->assertNotEmpty($indexes);
        $this->assertContains('status', array_column($indexes, 'column'));
    }

    public function test_detect_fillable_and_guarded()
    {
        $modelClass = TestModelFactory::create('FillableTest', [
            'name' => 'string',
            'email' => 'string',
            'password' => 'string',
        ]);

        // Override fillable in the model
        eval("
            class FillableTestWithAttributes extends {$modelClass} {
                protected \$fillable = ['name', 'email'];
                protected \$hidden = ['password'];
            }
        ");

        $analysis = $this->analyzer->analyze('FillableTestWithAttributes');

        $this->assertContains('name', $analysis['fillable']);
        $this->assertContains('email', $analysis['fillable']);
        $this->assertNotContains('password', $analysis['fillable']);
    }

    public function test_detect_casts()
    {
        $modelClass = TestModelFactory::create('CastTest', [
            'data' => 'json',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ]);

        $analysis = $this->analyzer->analyze($modelClass);
        $casts = $analysis['casts'];

        $this->assertEquals('array', $casts['data'] ?? null);
        $this->assertEquals('boolean', $casts['is_active'] ?? null);
        $this->assertEquals('datetime', $casts['published_at'] ?? null);
    }

    public function test_analyze_table_size()
    {
        $modelClass = TestModelFactory::create('SizeTest', [
            'value' => 'string',
        ]);

        // Create some records
        for ($i = 0; $i < 100; $i++) {
            $modelClass::create(['value' => "Test {$i}"]);
        }

        $analysis = $this->analyzer->analyze($modelClass);

        $this->assertArrayHasKey('record_count', $analysis);
        $this->assertEquals(100, $analysis['record_count']);
    }

    public function test_detect_scopes()
    {
        eval("
            class ScopedModel extends \Illuminate\Database\Eloquent\Model {
                protected \$table = 'scoped_models';
                
                public function scopeActive(\$query) {
                    return \$query->where('is_active', true);
                }
                
                public function scopeRecent(\$query) {
                    return \$query->orderBy('created_at', 'desc');
                }
            }
        ");

        $analysis = $this->analyzer->analyze('ScopedModel');
        $scopes = $analysis['scopes'] ?? [];

        $this->assertContains('active', $scopes);
        $this->assertContains('recent', $scopes);
    }

    public function test_detect_accessors_and_mutators()
    {
        eval("
            class AccessorModel extends \Illuminate\Database\Eloquent\Model {
                protected \$table = 'accessor_models';
                
                public function getFullNameAttribute() {
                    return \$this->first_name . ' ' . \$this->last_name;
                }
                
                public function setPasswordAttribute(\$value) {
                    \$this->attributes['password'] = bcrypt(\$value);
                }
            }
        ");

        $analysis = $this->analyzer->analyze('AccessorModel');

        $this->assertArrayHasKey('accessors', $analysis);
        $this->assertArrayHasKey('mutators', $analysis);
        $this->assertContains('full_name', $analysis['accessors']);
        $this->assertContains('password', $analysis['mutators']);
    }

    public function test_analyze_validation_rules()
    {
        $modelClass = TestModelFactory::create('ValidationTest', [
            'email' => 'string',
            'age' => 'integer',
            'website' => 'string',
        ]);

        $analysis = $this->analyzer->analyze($modelClass);
        $suggestions = $analysis['validation_suggestions'] ?? [];

        // Should suggest email validation for email field
        $this->assertArrayHasKey('email', $suggestions);
        $this->assertContains('email', $suggestions['email']);

        // Should suggest numeric validation for age
        $this->assertArrayHasKey('age', $suggestions);
        $this->assertContains('integer', $suggestions['age']);

        // Should suggest URL validation for website
        $this->assertArrayHasKey('website', $suggestions);
        $this->assertContains('url', $suggestions['website']);
    }

    public function test_performance_analysis()
    {
        $modelClass = TestModelFactory::create('PerformanceTest', [
            'data' => 'text',
        ]);

        // Create many records
        for ($i = 0; $i < 1000; $i++) {
            $modelClass::create(['data' => str_repeat('x', 1000)]);
        }

        $this->assertPerformance(
            fn () => $this->analyzer->analyze($modelClass),
            maxSeconds: 2.0,
            maxMemoryMb: 50
        );
    }
}
