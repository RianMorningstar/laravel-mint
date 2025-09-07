<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Analyzers\SchemaInspector;
use LaravelMint\Mint;
use Illuminate\Support\Facades\Schema;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Set up like the test
$mint = $app->make(Mint::class);
$analyzer = new ModelAnalyzer($mint);

DatabaseSeeder::setupTestDatabase();

// Test default values
$modelClass = TestModelFactory::create('DefaultTest', [
    'status' => 'string',
    'count' => 'integer',
]);

echo "Model class: $modelClass\n";

// Set defaults  
Schema::table('defaulttests', function ($table) {
    $table->string('status')->default('active')->change();
    $table->integer('count')->default(0)->change();
});

// Direct schema check
$connection = app('db')->connection();
$info = $connection->select("PRAGMA table_info(defaulttests)");
echo "\nDirect SQLite check:\n";
foreach ($info as $col) {
    if (in_array($col->name, ['status', 'count'])) {
        echo "  {$col->name}: default=" . var_export($col->dflt_value, true) . "\n";
    }
}

// SchemaInspector check
$schemaInspector = new SchemaInspector($mint);
$schemaInfo = $schemaInspector->inspect($modelClass);
echo "\nSchemaInspector columns:\n";
foreach (['status', 'count'] as $field) {
    if (isset($schemaInfo['columns'][$field])) {
        echo "  $field: default=" . var_export($schemaInfo['columns'][$field]['default'], true) . "\n";
    } else {
        echo "  $field: NOT FOUND\n";
    }
}

// ModelAnalyzer check
$analysis = $analyzer->analyze($modelClass);
echo "\nModelAnalyzer attributes:\n";
foreach (['status', 'count'] as $field) {
    if (isset($analysis['attributes'][$field])) {
        echo "  $field: default=" . var_export($analysis['attributes'][$field]['default'], true) . "\n";
    } else {
        echo "  $field: NOT FOUND\n";
    }
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
