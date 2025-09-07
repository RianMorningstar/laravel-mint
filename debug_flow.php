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

$modelClass = TestModelFactory::create('DebugTest', [
    'field1' => 'string',
]);

$mint = app(Mint::class);
$instance = new $modelClass;

echo "Model class: $modelClass\n";
echo "Has getSchemaColumns? " . (method_exists($instance, 'getSchemaColumns') ? 'yes' : 'no') . "\n";

// Check what SchemaInspector gets
$schemaInspector = new SchemaInspector($mint);
$schemaInfo = $schemaInspector->inspect($modelClass);

echo "\nSchemaInspector columns (should be from actual DB):\n";
foreach ($schemaInfo['columns'] as $name => $details) {
    echo "  $name: type=" . $details['type'] . ", nullable=" . ($details['nullable'] ? 'true' : 'false') . "\n";
}

// Now check what ModelAnalyzer does
$analyzer = new ModelAnalyzer($mint);

// Let's trace the flow in performAnalysis
$reflection = new ReflectionClass($modelClass);
$schemaInfo2 = $schemaInspector->inspect($modelClass);

echo "\nIn performAnalysis, schemaInfo has:\n";
echo "  columns? " . (isset($schemaInfo2['columns']) && !empty($schemaInfo2['columns']) ? 'yes (' . count($schemaInfo2['columns']) . ')' : 'no/empty') . "\n";

if (isset($schemaInfo2['columns']) && !empty($schemaInfo2['columns'])) {
    echo "  Using columns from SchemaInspector\n";
} elseif (method_exists($instance, 'getSchemaColumns')) {
    echo "  Falling back to getSchemaColumns method\n";
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
