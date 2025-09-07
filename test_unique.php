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

$modelClass = TestModelFactory::create('UniqueTest', [
    'email' => 'string',
    'username' => 'string',
]);

// Add unique index
Schema::table('uniquetests', function ($table) {
    $table->unique('email');
    $table->unique('username');
});

// Check indexes
$connection = app('db')->connection();
$indexes = $connection->select("PRAGMA index_list(uniquetests)");
echo "Indexes on table:\n";
foreach ($indexes as $idx) {
    echo "  {$idx->name}: unique=" . $idx->unique . "\n";
    $info = $connection->select("PRAGMA index_info({$idx->name})");
    foreach ($info as $col) {
        echo "    column: {$col->name}\n";
    }
}

$mint = app(Mint::class);
$schemaInspector = new SchemaInspector($mint);
$schemaInfo = $schemaInspector->inspect($modelClass);

echo "\nColumns from SchemaInspector:\n";
foreach ($schemaInfo['columns'] as $col => $details) {
    if (in_array($col, ['email', 'username'])) {
        echo "  $col: unique=" . ($details['unique'] ? 'true' : 'false') . "\n";
    }
}

echo "\nIndexes from SchemaInspector:\n";
var_dump($schemaInfo['indexes']);

$analyzer = new ModelAnalyzer($mint);
$analysis = $analyzer->analyze($modelClass);
$attributes = $analysis['attributes'];

echo "\nModelAnalyzer attributes:\n";
foreach (['email', 'username'] as $field) {
    if (isset($attributes[$field])) {
        echo "  $field: unique=" . ($attributes[$field]['unique'] ? 'true' : 'false') . "\n";
    }
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
