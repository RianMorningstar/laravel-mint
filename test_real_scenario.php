<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\SchemaInspector;
use LaravelMint\Mint;
use Illuminate\Support\Facades\Schema;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Clean start
DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
DatabaseSeeder::setupTestDatabase();

// Exactly as the test does it
$modelClass = TestModelFactory::create('DefaultTest', [
    'status' => 'string',
    'count' => 'integer',
]);

// Set defaults
Schema::table('defaulttests', function ($table) {
    $table->string('status')->default('active')->change();
    $table->integer('count')->default(0)->change();
});

// Check raw SQLite info
$connection = app('db')->connection();
echo "Raw SQLite info:\n";
$info = $connection->select("PRAGMA table_info(defaulttests)");
foreach ($info as $col) {
    if (in_array($col->name, ['status', 'count'])) {
        echo "  {$col->name}: type={$col->type}, default=" . var_export($col->dflt_value, true) . "\n";
    }
}

// Check SchemaInspector
$mint = app(Mint::class);
$schemaInspector = new SchemaInspector($mint);
$schemaInfo = $schemaInspector->inspectTable('defaulttests');

echo "\nSchemaInspector columns:\n";
foreach (['status', 'count'] as $field) {
    if (isset($schemaInfo['columns'][$field])) {
        $default = $schemaInfo['columns'][$field]['default'];
        echo "  {$field}: default=" . var_export($default, true) . " (type: " . gettype($default) . ")\n";
    }
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
