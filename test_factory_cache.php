<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

// Create model first time
$modelClass1 = TestModelFactory::create('TestCache', [
    'field1' => 'string',
]);
echo "First call: $modelClass1\n";

// Create same model second time
$modelClass2 = TestModelFactory::create('TestCache', [
    'field1' => 'string',
    'field2' => 'integer',
]);
echo "Second call: $modelClass2\n";

// Check if they're the same
echo "Same class? " . ($modelClass1 === $modelClass2 ? 'yes' : 'no') . "\n";

// Check schema columns
$instance1 = new $modelClass1;
$instance2 = new $modelClass2;

echo "Model 1 schema columns: " . implode(', ', array_keys($instance1->getSchemaColumns())) . "\n";
echo "Model 2 schema columns: " . implode(', ', array_keys($instance2->getSchemaColumns())) . "\n";

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
