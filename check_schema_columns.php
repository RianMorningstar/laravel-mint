<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

$modelClass = TestModelFactory::create('NullableTest', [
    'required_field' => 'string',
    'optional_field' => 'string',
]);

$instance = new $modelClass;
$schemaColumns = $instance->getSchemaColumns();

echo "getSchemaColumns returns:\n";
var_dump($schemaColumns);

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
