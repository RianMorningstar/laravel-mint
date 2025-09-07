<?php
require 'vendor/autoload.php';

use Illuminate\Support\Facades\Schema;
use LaravelMint\Tests\Helpers\DatabaseSeeder;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

// Create table with nullable field
Schema::create('test_change', function ($table) {
    $table->id();
    $table->string('status')->nullable();
});

$connection = app('db')->connection();

// Check initial
$info = $connection->select("PRAGMA table_info(test_change)");
foreach ($info as $col) {
    if ($col->name === 'status') {
        echo "Before change(): default=" . var_export($col->dflt_value, true) . "\n";
    }
}

// Try to change default - this will silently fail without doctrine/dbal
try {
    Schema::table('test_change', function ($table) {
        $table->string('status')->default('active')->change();
    });
    echo "Schema::table()->change() executed without error\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check after
$info = $connection->select("PRAGMA table_info(test_change)");
foreach ($info as $col) {
    if ($col->name === 'status') {
        echo "After change(): default=" . var_export($col->dflt_value, true) . "\n";
    }
}

Schema::dropIfExists('test_change');
DatabaseSeeder::cleanupTestDatabase();
