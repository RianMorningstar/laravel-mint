<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Analyzers\SchemaInspector;
use LaravelMint\Mint;

// Bootstrap
$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Set up fresh database
DatabaseSeeder::setupTestDatabase();

// Create Mint with test configuration
$mint = new Mint();
$mint->setConnection(app('db')->connection());

// Create analyzer
$analyzer = new ModelAnalyzer($mint);
$schemaInspector = new SchemaInspector($mint);

// Create model
$modelClass = TestModelFactory::create('NullableTest', [
    'required_field' => 'string',
    'optional_field' => 'string',
]);

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

// Get schema info first
$schemaInfo = $schemaInspector->inspect($modelClass);
echo "Schema columns:\n";
foreach ($schemaInfo['columns'] as $name => $details) {
    if (in_array($name, ['required_field', 'optional_field'])) {
        echo "  $name: nullable=" . ($details['nullable'] ? 'true' : 'false') . "\n";
    }
}

// Now analyze
$analysis = $analyzer->analyze($modelClass);
$attributes = $analysis['attributes'];

echo "\nAnalyzer attributes:\n";
foreach (['required_field', 'optional_field'] as $field) {
    if (isset($attributes[$field])) {
        echo "  $field: nullable=" . ($attributes[$field]['nullable'] ? 'true' : 'false') . "\n";
    } else {
        echo "  $field: NOT FOUND\n";
    }
}

// Check test assertions
$test1 = $attributes['required_field']['nullable'] ?? true;
$test2 = $attributes['optional_field']['nullable'] ?? false;
echo "\nTest assertions:\n";
echo "  \$attributes['required_field']['nullable'] ?? true = " . ($test1 ? 'true' : 'false') . " (should be false)\n";
echo "  \$attributes['optional_field']['nullable'] ?? false = " . ($test2 ? 'true' : 'false') . " (should be true)\n";

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
