<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\SchemaInspector;
use LaravelMint\Mint;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->register(\LaravelMint\MintServiceProvider::class);

$mint = $app->make(Mint::class);
$schemaInspector = new SchemaInspector($mint);

DatabaseSeeder::setupTestDatabase();

// Create model
$modelClass = TestModelFactory::create('NullableTest', [
    'required_field' => 'string',
    'optional_field' => 'string',
]);

echo "Step 1: Model created: $modelClass\n";

// Check initial table schema
$connection = app('db')->connection();
$info = $connection->select("PRAGMA table_info(nullabletests)");
echo "\nStep 2: Initial table schema:\n";
foreach ($info as $col) {
    if (in_array($col->name, ['required_field', 'optional_field'])) {
        echo "  {$col->name}: notnull={$col->notnull}\n";
    }
}

// Recreate table with NOT NULL constraint
$connection->statement('CREATE TABLE nullabletests_new AS SELECT * FROM nullabletests WHERE 0');
$connection->statement('DROP TABLE nullabletests');
$connection->statement('CREATE TABLE nullabletests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    required_field VARCHAR(255) NOT NULL,
    optional_field VARCHAR(255),
    created_at DATETIME,
    updated_at DATETIME
)');

echo "\nStep 3: After recreating table:\n";
$info = $connection->select("PRAGMA table_info(nullabletests)");
foreach ($info as $col) {
    if (in_array($col->name, ['required_field', 'optional_field'])) {
        echo "  {$col->name}: notnull={$col->notnull}\n";
    }
}

// Check what SchemaInspector sees
$schemaInfo = $schemaInspector->inspectTable('nullabletests');
echo "\nStep 4: SchemaInspector sees:\n";
foreach (['required_field', 'optional_field'] as $field) {
    $nullable = $schemaInfo['columns'][$field]['nullable'] ?? 'NOT SET';
    echo "  {$field}: nullable=" . (is_bool($nullable) ? ($nullable ? 'true' : 'false') : $nullable) . "\n";
}

// Check what model's getSchemaColumns returns
$instance = new $modelClass;
$schemaColumns = $instance->getSchemaColumns();
echo "\nStep 5: Model's getSchemaColumns returns:\n";
foreach (array_keys($schemaColumns) as $field) {
    echo "  $field\n";
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
