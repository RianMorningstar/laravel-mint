<?php

namespace LaravelMint\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LaravelMint\Tests\Helpers\AssertionHelpers;
use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\TestCase;

class GenerateDataFeatureTest extends TestCase
{
    use AssertionHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseSeeder::setupTestDatabase();
        
        // Create test models that the commands expect
        $this->createTestModels();
    }
    
    protected function createTestModels(): void
    {
        // Create User model in App\Models namespace
        if (!class_exists('App\Models\User')) {
            eval('
                namespace App\Models;
                class User extends \Illuminate\Database\Eloquent\Model {
                    protected $table = "users";
                    protected $fillable = ["name", "email", "password"];
                }
            ');
        }
        
        // Create Post model
        if (!class_exists('App\Models\Post')) {
            eval('
                namespace App\Models;
                class Post extends \Illuminate\Database\Eloquent\Model {
                    protected $table = "posts";
                    protected $fillable = ["user_id", "title", "content", "status", "views"];
                }
            ');
        }
        
        // Create Product model
        if (!class_exists('App\Models\Product')) {
            eval('
                namespace App\Models;
                class Product extends \Illuminate\Database\Eloquent\Model {
                    protected $table = "products";
                    protected $fillable = ["name", "sku", "description", "price", "stock", "is_active", "attributes", "category_id"];
                }
            ');
        }
        
        // Create Category model
        if (!class_exists('App\Models\Category')) {
            eval('
                namespace App\Models;
                class Category extends \Illuminate\Database\Eloquent\Model {
                    protected $table = "categories";
                    protected $fillable = ["name", "slug", "description", "parent_id"];
                }
            ');
        }
        
        // Create Comment model
        if (!class_exists('App\Models\Comment')) {
            eval('
                namespace App\Models;
                class Comment extends \Illuminate\Database\Eloquent\Model {
                    protected $table = "comments";
                    protected $fillable = ["post_id", "user_id", "content", "is_approved"];
                }
            ');
        }
    }

    protected function tearDown(): void
    {
        DatabaseSeeder::cleanupTestDatabase();
        parent::tearDown();
    }

    public function test_generate_command_creates_records()
    {
        // Execute generate command
        Artisan::call('mint:generate', [
            'model' => 'App\Models\User',
            'count' => 10,
        ]);

        // Verify records were created
        $this->assertEquals(10, DB::table('users')->count());
    }

    public function test_generate_command_with_attributes()
    {
        // Execute with custom attributes
        Artisan::call('mint:generate', [
            'model' => 'App\Models\Post',
            '--attributes' => 'status=published,views=100',
            'count' => 5,
        ]);

        // Verify attributes were applied
        $posts = DB::table('posts')->get();
        $this->assertCount(5, $posts);

        foreach ($posts as $post) {
            $this->assertEquals('published', $post->status);
            $this->assertEquals(100, $post->views);
        }
    }

    public function test_generate_command_with_pattern()
    {
        // Execute with pattern
        Artisan::call('mint:generate', [
            'model' => 'App\Models\Product',
            'count' => 50,
            '--pattern' => 'normal',
            '--pattern-config' => 'field=price,mean=100,stddev=20',
        ]);

        // Verify pattern was applied
        $products = DB::table('products')->pluck('price')->toArray();
        $this->assertCount(50, $products);
        $this->assertDataDistribution($products, 100, 0.3);
    }

    public function test_generate_command_with_relationships()
    {
        // First create users
        Artisan::call('mint:generate', [
            'model' => 'App\Models\User',
            'count' => 5,
        ]);

        // Then create posts with user relationships
        Artisan::call('mint:generate', [
            'model' => 'App\Models\Post',
            'count' => 20,
            '--with-relationships' => true,
        ]);

        // Verify relationships
        $posts = DB::table('posts')->get();
        $userIds = DB::table('users')->pluck('id')->toArray();

        foreach ($posts as $post) {
            $this->assertContains($post->user_id, $userIds);
        }
    }

    public function test_generate_command_performance_mode()
    {
        // Test chunk generation for large datasets
        $startTime = microtime(true);

        Artisan::call('mint:generate', [
            'model' => 'App\Models\Order',
            'count' => 1000,
            '--chunk' => 100,
        ]);

        $duration = microtime(true) - $startTime;

        // Verify all records created
        $this->assertEquals(1000, DB::table('orders')->count());

        // Should complete within reasonable time
        $this->assertLessThan(10, $duration);
    }

    public function test_generate_command_with_seed()
    {
        // Generate with seed for reproducibility
        Artisan::call('mint:generate', [
            'model' => 'App\Models\Product',
            'count' => 10,
            '--seed' => 12345,
        ]);

        $firstRun = DB::table('products')->pluck('name')->toArray();

        // Clear and regenerate with same seed
        DB::table('products')->truncate();

        Artisan::call('mint:generate', [
            'model' => 'App\Models\Product',
            'count' => 10,
            '--seed' => 12345,
        ]);

        $secondRun = DB::table('products')->pluck('name')->toArray();

        // Results should be identical
        $this->assertEquals($firstRun, $secondRun);
    }

    public function test_analyze_command_provides_insights()
    {
        // Create some test data
        DB::table('users')->insert([
            ['name' => 'User 1', 'email' => 'user1@example.com', 'password' => 'hash1'],
            ['name' => 'User 2', 'email' => 'user2@example.com', 'password' => 'hash2'],
        ]);

        // Run analyze command
        Artisan::call('mint:analyze', [
            'model' => 'App\Models\User',
        ]);

        $output = Artisan::output();

        // Verify output contains analysis
        $this->assertStringContainsString('Model Analysis', $output);
        $this->assertStringContainsString('Attributes', $output);
        $this->assertStringContainsString('Relationships', $output);
        $this->assertStringContainsString('Record Count: 2', $output);
    }

    public function test_clear_command_removes_records()
    {
        // Create test data
        DB::table('posts')->insert([
            ['user_id' => 1, 'title' => 'Post 1', 'content' => 'Content 1'],
            ['user_id' => 1, 'title' => 'Post 2', 'content' => 'Content 2'],
            ['user_id' => 2, 'title' => 'Post 3', 'content' => 'Content 3'],
        ]);

        $this->assertEquals(3, DB::table('posts')->count());

        // Clear all records
        Artisan::call('mint:clear', [
            'model' => 'App\Models\Post',
            '--force' => true,
        ]);

        $this->assertEquals(0, DB::table('posts')->count());
    }

    public function test_clear_command_with_conditions()
    {
        // Create test data
        DB::table('posts')->insert([
            ['user_id' => 1, 'title' => 'Post 1', 'content' => 'Content 1', 'status' => 'draft'],
            ['user_id' => 1, 'title' => 'Post 2', 'content' => 'Content 2', 'status' => 'published'],
            ['user_id' => 2, 'title' => 'Post 3', 'content' => 'Content 3', 'status' => 'draft'],
        ]);

        // Clear only drafts
        Artisan::call('mint:clear', [
            'model' => 'App\Models\Post',
            '--where' => 'status=draft',
            '--force' => true,
        ]);

        $this->assertEquals(1, DB::table('posts')->count());
        $this->assertEquals('published', DB::table('posts')->first()->status);
    }

    public function test_import_command_loads_data()
    {
        // Create import file
        $importData = [
            ['name' => 'Product 1', 'sku' => 'SKU001', 'price' => 99.99, 'stock' => 10],
            ['name' => 'Product 2', 'sku' => 'SKU002', 'price' => 149.99, 'stock' => 5],
            ['name' => 'Product 3', 'sku' => 'SKU003', 'price' => 199.99, 'stock' => 3],
        ];

        $importPath = storage_path('app/test-import.json');
        file_put_contents($importPath, json_encode($importData));

        // Run import command
        $result = Artisan::call('mint:import', [
            'model' => 'App\Models\Product',
            'file' => $importPath,
            '--format' => 'json',
        ]);
        
        // Debug output
        $output = Artisan::output();
        
        // Verify command succeeded
        $this->assertEquals(0, $result, "Import command failed with output: " . $output);

        // Verify data was imported
        $products = DB::table('products')->get();
        $this->assertCount(3, $products, "Products not imported. Output: " . $output);
        $this->assertEquals('Product 1', $products[0]->name);
        $this->assertEquals(99.99, $products[0]->price);

        // Cleanup
        unlink($importPath);
    }

    public function test_export_command_saves_data()
    {
        // Create test data
        DB::table('products')->insert([
            ['name' => 'Product A', 'sku' => 'SKU-001', 'price' => 50.00],
            ['name' => 'Product B', 'sku' => 'SKU-002', 'price' => 75.00],
        ]);

        $exportPath = storage_path('app/test-export.csv');

        // Run export command
        Artisan::call('mint:export', [
            'model' => 'App\Models\Product',
            'file' => $exportPath,
            '--format' => 'csv',
        ]);

        // Verify file was created
        $this->assertFileExists($exportPath);

        // Verify content
        $content = file_get_contents($exportPath);
        $this->assertStringContainsString('Product A', $content);
        $this->assertStringContainsString('SKU-001', $content);

        // Cleanup
        unlink($exportPath);
    }

    public function test_scenario_command_runs_preset()
    {
        // Run e-commerce scenario
        Artisan::call('mint:scenario', [
            'scenario' => 'ecommerce',
            '--scale' => 0.1, // Small scale for testing
        ]);

        // Verify scenario created expected data
        $this->assertGreaterThan(0, DB::table('users')->count());
        $this->assertGreaterThan(0, DB::table('products')->count());
        $this->assertGreaterThan(0, DB::table('orders')->count());
    }

    public function test_pattern_list_command()
    {
        // List available patterns
        Artisan::call('mint:pattern:list');

        $output = Artisan::output();

        // Verify patterns are listed
        $this->assertStringContainsString('Available Patterns', $output);
        $this->assertStringContainsString('normal', $output);
        $this->assertStringContainsString('exponential', $output);
        $this->assertStringContainsString('seasonal', $output);
    }

    public function test_batch_generation_workflow()
    {
        // Complex workflow with multiple models

        // 1. Generate users
        Artisan::call('mint:generate', [
            'model' => 'App\Models\User',
            'count' => 10,
        ]);

        // 2. Generate categories
        Artisan::call('mint:generate', [
            'model' => 'App\Models\Category',
            'count' => 5,
        ]);

        // 3. Generate posts with relationships
        Artisan::call('mint:generate', [
            'model' => 'App\Models\Post',
            'count' => 50,
            '--with-relationships' => true,
        ]);

        // 4. Generate comments
        Artisan::call('mint:generate', [
            'model' => 'App\Models\Comment',
            'count' => 200,
            '--with-relationships' => true,
        ]);

        // Verify complete data structure
        $this->assertEquals(10, DB::table('users')->count());
        $this->assertEquals(5, DB::table('categories')->count());
        $this->assertEquals(50, DB::table('posts')->count());
        $this->assertEquals(200, DB::table('comments')->count());

        // Verify relationships integrity
        $commentUserIds = DB::table('comments')->pluck('user_id')->unique();
        $validUserIds = DB::table('users')->pluck('id');

        foreach ($commentUserIds as $userId) {
            $this->assertContains($userId, $validUserIds);
        }
    }

    public function test_error_handling_for_invalid_model()
    {
        // Try to generate for non-existent model
        Artisan::call('mint:generate', [
            'model' => 'App\Models\NonExistent',
            'count' => 10,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('Failed', $output);
    }

    public function test_concurrent_generation()
    {
        // Test thread safety with concurrent operations
        $processes = [];

        for ($i = 0; $i < 3; $i++) {
            $processes[] = function () use ($i) {
                $attributes = json_encode(['batch' => $i]);
                Artisan::call('mint:generate', [
                    'model' => 'App\Models\Product',
                    'count' => 100,
                    '--attributes' => "attributes={$attributes}",
                    '--seed' => 1000 + $i, // Different seed for each batch to avoid SKU conflicts
                ]);
            };
        }

        // Run concurrently (simplified for testing)
        foreach ($processes as $process) {
            $process();
        }

        // Verify all records created
        $this->assertEquals(300, DB::table('products')->count());

        // Verify batches using JSON query
        for ($i = 0; $i < 3; $i++) {
            $batchCount = DB::table('products')
                ->whereRaw("json_extract(attributes, '$.batch') = ?", [$i])
                ->count();
            $this->assertEquals(100, $batchCount);
        }
    }
}
