<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->register(\LaravelMint\MintServiceProvider::class);

DatabaseSeeder::setupTestDatabase();

$modelClass = TestModelFactory::create('CacheTest', [
    'field1' => 'string',
]);

echo "Model class name: $modelClass\n";
echo "Cache key would be: mint.analysis.{$modelClass}\n";

// Check what Cache::forget expects
\Illuminate\Support\Facades\Cache::put("mint.analysis.{$modelClass}", ['test' => 'data'], 60);
$data = \Illuminate\Support\Facades\Cache::get("mint.analysis.{$modelClass}");
echo "Can store and retrieve: " . (isset($data['test']) ? 'yes' : 'no') . "\n";

\Illuminate\Support\Facades\Cache::forget("mint.analysis.{$modelClass}");
$data = \Illuminate\Support\Facades\Cache::get("mint.analysis.{$modelClass}");
echo "After forget: " . ($data === null ? 'cleared' : 'still there') . "\n";

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
