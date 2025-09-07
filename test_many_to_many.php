<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Mint;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

$userClass = TestModelFactory::create('User', [
    'name' => 'string',
]);

$roleClass = TestModelFactory::create('Role', [
    'name' => 'string',
], [
    'users' => ['type' => 'belongsToMany', 'model' => $userClass],
]);

// Check the generated method
$roleInstance = new $roleClass;
$reflection = new ReflectionClass($roleClass);
echo "Methods on Role model:\n";
foreach ($reflection->getMethods() as $method) {
    if ($method->class === $roleClass) {
        echo "  - {$method->getName()}\n";
        if ($method->getName() === 'users') {
            echo "    Method found! Trying to invoke...\n";
            try {
                $result = $method->invoke($roleInstance);
                echo "    Result class: " . get_class($result) . "\n";
                echo "    Parent classes: " . implode(', ', class_parents($result)) . "\n";
            } catch (Exception $e) {
                echo "    Error: " . $e->getMessage() . "\n";
            }
        }
    }
}

$mint = app(Mint::class);
$analyzer = new ModelAnalyzer($mint);
$analysis = $analyzer->analyze($roleClass);

echo "\nRelationships detected:\n";
if (isset($analysis['relationships']['users'])) {
    echo "  users: type=" . $analysis['relationships']['users']['type'] . "\n";
} else {
    echo "  users relationship not found\n";
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
