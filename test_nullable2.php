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

// Check what getSchemaColumns returns
$instance = new $modelClass;
if (method_exists($instance, 'getSchemaColumns')) {
    echo "Model has getSchemaColumns method\n";
    $cols = $instance->getSchemaColumns();
    var_dump($cols);
}

// Recreate table with required_field as NOT NULL  
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
$analyzer = new ModelAnalyzer($mint);
$analysis = $analyzer->analyze($modelClass);

echo "\nAttributes in analysis:\n";
var_dump(array_keys($analysis['attributes']));

echo "\nAttribute details:\n";
var_dump($analysis['attributes']);

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
