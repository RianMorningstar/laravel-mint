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

$mint = app(Mint::class);

// Manually simulate what performAnalysis does
$instance = new $modelClass;
$schemaInspector = new SchemaInspector($mint);
$schemaInfo = $schemaInspector->inspect($modelClass);

echo "SchemaInfo columns count: " . count($schemaInfo['columns']) . "\n";
echo "Has required_field in columns? " . (isset($schemaInfo['columns']['required_field']) ? 'yes' : 'no') . "\n";

$attributes = [];
if (isset($schemaInfo['columns']) && !empty($schemaInfo['columns'])) {
    echo "Processing columns from schemaInfo...\n";
    foreach ($schemaInfo['columns'] as $column => $details) {
        if (in_array($column, ['required_field', 'optional_field'])) {
            echo "  Processing $column:\n";
            echo "    nullable from details: " . var_export($details['nullable'] ?? 'NOT SET', true) . "\n";
            $attributes[$column] = [
                'type' => $details['type'] ?? 'string',
                'nullable' => $details['nullable'] ?? false,
                'default' => $details['default'] ?? null,
                'unique' => $details['unique'] ?? false,
            ];
            echo "    nullable in attributes: " . var_export($attributes[$column]['nullable'], true) . "\n";
        }
    }
} elseif (method_exists($instance, 'getSchemaColumns')) {
    echo "Would fall back to getSchemaColumns\n";
}

echo "\nFinal attributes:\n";
var_dump($attributes);

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
