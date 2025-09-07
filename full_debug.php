<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Mint;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->register(\LaravelMint\MintServiceProvider::class);

$mint = $app->make(Mint::class);
$analyzer = new ModelAnalyzer($mint);

// Clear all cache first
\Illuminate\Support\Facades\Cache::flush();

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

$analysis = $analyzer->analyze($modelClass);

echo "All attributes:\n";
foreach ($analysis['attributes'] as $field => $details) {
    echo "  $field: nullable=" . ($details['nullable'] ? 'true' : 'false') . "\n";
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
