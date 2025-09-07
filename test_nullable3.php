<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Analyzers\SchemaInspector;
use LaravelMint\Mint;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

// First create the model
$modelClass = TestModelFactory::create('NullableTest', [
    'required_field' => 'string',
    'optional_field' => 'string',
]);

// Then recreate the table with different schema
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

// Now analyze
$mint = app(Mint::class);
$schemaInspector = new SchemaInspector($mint);
$schemaInfo = $schemaInspector->inspect($modelClass);

echo "Schema Inspector Results:\n";
echo "Columns from SchemaInspector:\n";
foreach ($schemaInfo['columns'] as $col => $details) {
    echo "  $col: nullable=" . ($details['nullable'] ? 'true' : 'false') . "\n";
}

$analyzer = new ModelAnalyzer($mint);
$analysis = $analyzer->analyze($modelClass);

echo "\nModelAnalyzer Results:\n";
echo "Has attributes? " . (isset($analysis['attributes']) ? 'yes' : 'no') . "\n";
if (isset($analysis['attributes'])) {
    foreach ($analysis['attributes'] as $col => $details) {
        if (in_array($col, ['required_field', 'optional_field'])) {
            echo "  $col: nullable=" . ($details['nullable'] ? 'true' : 'false') . "\n";
        }
    }
}

// Test the same assertions as the test
$attributes = $analysis['attributes'];
$test1 = $attributes['required_field']['nullable'] ?? true;
$test2 = $attributes['optional_field']['nullable'] ?? false;
echo "\nTest assertions:\n";
echo "required_field nullable (should be false): " . ($test1 ? 'true' : 'false') . "\n";
echo "optional_field nullable (should be true): " . ($test2 ? 'true' : 'false') . "\n";

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
