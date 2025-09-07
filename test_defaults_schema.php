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

DatabaseSeeder::setupTestDatabase();

$modelClass = TestModelFactory::create('DefaultTest', [
    'status' => 'string',
    'count' => 'integer',
]);

// Set defaults using Schema::table (same as test)
Schema::table('defaulttests', function ($table) {
    $table->string('status')->default('active')->change();
    $table->integer('count')->default(0)->change();
});

$connection = app('db')->connection();

// Check what SQLite sees
$tableInfo = $connection->select("PRAGMA table_info(defaulttests)");
echo "SQLite table info after Schema::table:\n";
foreach ($tableInfo as $col) {
    echo "  {$col->name}: type={$col->type}, default={$col->dflt_value}\n";
}

// Check what SchemaInspector sees
$mint = app(Mint::class);
$schemaInspector = new SchemaInspector($mint);
$schemaInfo = $schemaInspector->inspect($modelClass);

echo "\nSchemaInspector columns:\n";
foreach ($schemaInfo['columns'] as $name => $details) {
    if (in_array($name, ['status', 'count'])) {
        $default = $details['default'] ?? 'null';
        echo "  {$name}: default=" . (is_null($default) ? 'null' : var_export($default, true)) . "\n";
    }
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
