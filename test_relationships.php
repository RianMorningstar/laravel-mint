<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Mint;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

// Test hasMany relationship
$postClass = TestModelFactory::create('Post', [
    'title' => 'string',
]);

$commentClass = TestModelFactory::create('Comment', [
    'content' => 'text',
    'post_id' => 'integer',
], [
    'post' => ['type' => 'belongsTo', 'model' => $postClass],
]);

// Update Post model to have comments relationship
$postClass = TestModelFactory::create('Post', [
    'title' => 'string',
], [
    'comments' => ['type' => 'hasMany', 'model' => $commentClass],
]);

// Check if the method exists
$postInstance = new $postClass;
$reflection = new ReflectionClass($postClass);
echo "Methods on Post model:\n";
foreach ($reflection->getMethods() as $method) {
    if ($method->class === $postClass) {
        echo "  - {$method->getName()}\n";
        if ($method->getName() === 'comments') {
            echo "    Calling comments()...\n";
            try {
                $result = $method->invoke($postInstance);
                echo "    Result class: " . get_class($result) . "\n";
            } catch (Exception $e) {
                echo "    Error: " . $e->getMessage() . "\n";
            }
        }
    }
}

$mint = app(Mint::class);
$analyzer = new ModelAnalyzer($mint);
$analysis = $analyzer->analyze($postClass);

echo "\nRelationships detected:\n";
var_dump($analysis['relationships']);

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
