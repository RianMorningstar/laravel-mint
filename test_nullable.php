<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Mint;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

$modelClass = TestModelFactory::create('NullableTest', [
    'required_field' => 'string',
    'optional_field' => 'string',
]);

// Recreate table with required_field as NOT NULL
$connection = app('db')->connection();
$connection->statement('DROP TABLE IF EXISTS nullabletests');
$connection->statement('CREATE TABLE nullabletests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    required_field VARCHAR(255) NOT NULL,
    optional_field VARCHAR(255),
    created_at DATETIME,
    updated_at DATETIME
)');

// Test with PRAGMA
$tableInfo = $connection->select("PRAGMA table_info(nullabletests)");
foreach ($tableInfo as $col) {
    echo "Column: {$col->name}, nullable: " . (!$col->notnull ? 'true' : 'false') . "\n";
}

$mint = app(Mint::class);
$analyzer = new ModelAnalyzer($mint);
$analysis = $analyzer->analyze($modelClass);

echo "\nAnalysis results:\n";
foreach ($analysis['attributes'] as $field => $details) {
    echo "Field: {$field}, nullable: " . ($details['nullable'] ? 'true' : 'false') . "\n";
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
