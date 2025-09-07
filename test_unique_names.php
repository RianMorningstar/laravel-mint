<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\TestModelFactory;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Create same model multiple times
$model1 = TestModelFactory::create('TestModel', ['field1' => 'string']);
$model2 = TestModelFactory::create('TestModel', ['field1' => 'string']);
$model3 = TestModelFactory::create('TestModel', ['field1' => 'string', 'field2' => 'integer']);

echo "Model 1: $model1\n";
echo "Model 2: $model2\n";
echo "Model 3: $model3\n";

echo "Are they different? " . ($model1 !== $model2 && $model2 !== $model3 ? 'yes' : 'no') . "\n";

TestModelFactory::cleanup();
