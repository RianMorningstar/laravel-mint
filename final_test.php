<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Mint;

// Bootstrap
$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Register Mint in container
$app->singleton(Mint::class, function ($app) {
    return new Mint($app);
});

// Set up fresh database
DatabaseSeeder::setupTestDatabase();

// Get services
$mint = $app->make(Mint::class);
$analyzer = new ModelAnalyzer($mint);

// Create model
$modelClass = TestModelFactory::create('NullableTest', [
    'required_field' => 'string',
    'optional_field' => 'string',
]);

echo "Initial model class: $modelClass\n";

// Recreate table with NOT NULL constraint
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

// Analyze
$analysis = $analyzer->analyze($modelClass);
$attributes = $analysis['attributes'];

echo "\nAnalysis complete. Checking attributes...\n";
echo "Attributes has " . count($attributes) . " columns\n";

// Debug output
if (!isset($attributes['required_field'])) {
    echo "ERROR: required_field not in attributes! Keys are: " . implode(', ', array_keys($attributes)) . "\n";
} else {
    $nullable = $attributes['required_field']['nullable'] ?? 'NOT SET';
    echo "required_field nullable: " . (is_bool($nullable) ? ($nullable ? 'true' : 'false') : $nullable) . "\n";
}

if (!isset($attributes['optional_field'])) {
    echo "ERROR: optional_field not in attributes! Keys are: " . implode(', ', array_keys($attributes)) . "\n";
} else {
    $nullable = $attributes['optional_field']['nullable'] ?? 'NOT SET';
    echo "optional_field nullable: " . (is_bool($nullable) ? ($nullable ? 'true' : 'false') : $nullable) . "\n";
}

// Test the exact assertions from the test
$test1_result = $attributes['required_field']['nullable'] ?? true;
$test2_result = $attributes['optional_field']['nullable'] ?? false;

echo "\nTest assertions:\n";
echo "Line 131: assertFalse(\$attributes['required_field']['nullable'] ?? true)\n";
echo "  Result: " . ($test1_result ? 'true' : 'false') . " - " . ($test1_result === false ? "PASS" : "FAIL") . "\n";

echo "Line 132: assertTrue(\$attributes['optional_field']['nullable'] ?? false)\n";
echo "  Result: " . ($test2_result ? 'true' : 'false') . " - " . ($test2_result === true ? "PASS" : "FAIL") . "\n";

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
