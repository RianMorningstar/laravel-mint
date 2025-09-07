<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Mint;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->register(\LaravelMint\MintServiceProvider::class);

// Set up like the test
$mint = $app->make(Mint::class);
$analyzer = new ModelAnalyzer($mint);

DatabaseSeeder::setupTestDatabase();

// Run the nullable test exactly as it is
$modelClass = TestModelFactory::create('NullableTest', [
    'required_field' => 'string',
    'optional_field' => 'string',
]);

echo "Model class: $modelClass\n";

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

// Clear cache after schema change
\Illuminate\Support\Facades\Cache::forget("mint.analysis.{$modelClass}");

$analysis = $analyzer->analyze($modelClass);
$attributes = $analysis['attributes'];

echo "Attributes found:\n";
foreach ($attributes as $field => $details) {
    if (in_array($field, ['required_field', 'optional_field'])) {
        echo "  $field: nullable=" . ($details['nullable'] ? 'true' : 'false') . "\n";
    }
}

// Test assertions
$assert1 = $attributes['required_field']['nullable'] ?? true;
$assert2 = $attributes['optional_field']['nullable'] ?? false;

echo "\nTest assertions:\n";
echo "  assertFalse(\$attributes['required_field']['nullable'] ?? true) = " . ($assert1 === false ? 'PASS' : 'FAIL') . "\n";
echo "  assertTrue(\$attributes['optional_field']['nullable'] ?? false) = " . ($assert2 === true ? 'PASS' : 'FAIL') . "\n";

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
