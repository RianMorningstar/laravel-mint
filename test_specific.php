<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Mint;
use Illuminate\Support\Facades\Schema;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Run setUp just like the test
$mint = app(Mint::class);
$analyzer = new ModelAnalyzer($mint);

DatabaseSeeder::setupTestDatabase();

// Test 1: Nullable fields
echo "=== NULLABLE TEST ===\n";
$modelClass = TestModelFactory::create('NullableTest', [
    'required_field' => 'string',
    'optional_field' => 'string',
]);

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

$analysis = $analyzer->analyze($modelClass);
$attributes = $analysis['attributes'];

echo "required_field nullable: " . ($attributes['required_field']['nullable'] ?? 'NOT SET') . " (should be false)\n";
echo "optional_field nullable: " . ($attributes['optional_field']['nullable'] ?? 'NOT SET') . " (should be true)\n";

// Test 2: Unique constraints  
echo "\n=== UNIQUE TEST ===\n";
$modelClass2 = TestModelFactory::create('UniqueTest', [
    'email' => 'string',
    'username' => 'string',
]);

Schema::table('uniquetests', function ($table) {
    $table->unique('email');
    $table->unique('username');
});

$analysis2 = $analyzer->analyze($modelClass2);
$attributes2 = $analysis2['attributes'];

echo "email unique: " . (isset($attributes2['email']['unique']) ? ($attributes2['email']['unique'] ? 'true' : 'false') : 'NOT SET') . " (should be true)\n";
echo "username unique: " . (isset($attributes2['username']['unique']) ? ($attributes2['username']['unique'] ? 'true' : 'false') : 'NOT SET') . " (should be true)\n";

// Test 3: Default values
echo "\n=== DEFAULT VALUES TEST ===\n";
$modelClass3 = TestModelFactory::create('DefaultTest', [
    'status' => 'string',
    'count' => 'integer',
]);

Schema::table('defaulttests', function ($table) {
    $table->string('status')->default('active')->change();
    $table->integer('count')->default(0)->change();
});

$analysis3 = $analyzer->analyze($modelClass3);
$attributes3 = $analysis3['attributes'];

$statusDefault = $attributes3['status']['default'] ?? null;
$countDefault = $attributes3['count']['default'] ?? null;
echo "status default: " . var_export($statusDefault, true) . " (should be 'active')\n";
echo "count default: " . var_export($countDefault, true) . " (should be 0)\n";

// Test 4: Indexes
echo "\n=== INDEXES TEST ===\n";
$modelClass4 = TestModelFactory::create('IndexTest', [
    'email' => 'string',
    'status' => 'string',
    'created_at' => 'datetime',
]);

Schema::table('indextests', function ($table) {
    $table->index('status');
    $table->index(['status', 'created_at']);
});

$analysis4 = $analyzer->analyze($modelClass4);
$indexes = $analysis4['indexes'] ?? [];

echo "Indexes count: " . count($indexes) . "\n";
if (!empty($indexes)) {
    foreach ($indexes as $idx) {
        echo "  Index: " . ($idx['name'] ?? 'unnamed') . " on column(s): " . 
             (isset($idx['column']) ? $idx['column'] : implode(', ', $idx['columns'] ?? [])) . "\n";
    }
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
