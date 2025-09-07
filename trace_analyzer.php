<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\SchemaInspector;
use LaravelMint\Mint;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

// Create model with only 2 fields defined
$modelClass = TestModelFactory::create('TraceTest', [
    'field1' => 'string',
    'field2' => 'string',
]);

// Model's getSchemaColumns returns only field1 and field2
$instance = new $modelClass;
echo "getSchemaColumns: " . implode(', ', array_keys($instance->getSchemaColumns())) . "\n";

// But the actual database table has more columns (id, created_at, updated_at)
$connection = app('db')->connection();
$columns = $connection->getSchemaBuilder()->getColumnListing('tracetests');
echo "Database columns: " . implode(', ', $columns) . "\n";

// What does SchemaInspector return?
$mint = app(Mint::class);
$schemaInspector = new SchemaInspector($mint);
$schemaInfo = $schemaInspector->inspect($modelClass);
echo "SchemaInspector columns: " . implode(', ', array_keys($schemaInfo['columns'])) . "\n";

// The issue: ModelAnalyzer checks if schemaInfo['columns'] is not empty
// If it is, it uses those columns
// Otherwise it falls back to getSchemaColumns
echo "\nIn ModelAnalyzer performAnalysis:\n";
echo "  isset(schemaInfo['columns']): " . (isset($schemaInfo['columns']) ? 'true' : 'false') . "\n";
echo "  !empty(schemaInfo['columns']): " . (!empty($schemaInfo['columns']) ? 'true' : 'false') . "\n";
echo "  Will use: " . (!empty($schemaInfo['columns']) ? 'schemaInfo columns' : 'getSchemaColumns') . "\n";

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
