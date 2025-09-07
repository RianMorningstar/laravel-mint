<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Mint;
use Illuminate\Support\Facades\Schema;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

$modelClass = TestModelFactory::create('DefaultTest', [
    'status' => 'string',
    'count' => 'integer',
]);

// Set defaults using raw SQL since Schema::table with change() might not work properly in SQLite
$connection = app('db')->connection();

// Drop and recreate with defaults
$connection->statement('CREATE TABLE defaulttests_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    status VARCHAR(255) DEFAULT \'active\',
    count INTEGER DEFAULT 0,
    created_at DATETIME,
    updated_at DATETIME
)');
$connection->statement('INSERT INTO defaulttests_new SELECT * FROM defaulttests');
$connection->statement('DROP TABLE defaulttests');
$connection->statement('ALTER TABLE defaulttests_new RENAME TO defaulttests');

// Check with PRAGMA
$tableInfo = $connection->select("PRAGMA table_info(defaulttests)");
echo "Table schema:\n";
foreach ($tableInfo as $col) {
    if (in_array($col->name, ['status', 'count'])) {
        echo "  {$col->name}: default={$col->dflt_value}\n";
    }
}

$mint = app(Mint::class);
$analyzer = new ModelAnalyzer($mint);
$analysis = $analyzer->analyze($modelClass);
$attributes = $analysis['attributes'];

echo "\nModelAnalyzer attributes:\n";
foreach (['status', 'count'] as $field) {
    if (isset($attributes[$field])) {
        $default = $attributes[$field]['default'] ?? 'null';
        echo "  $field: default=" . (is_null($default) ? 'null' : var_export($default, true)) . "\n";
    }
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
