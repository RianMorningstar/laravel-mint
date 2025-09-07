<?php
require 'vendor/autoload.php';

use Illuminate\Support\Facades\Schema;
use LaravelMint\Tests\Helpers\DatabaseSeeder;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

// Create table
Schema::create('test_defaults', function ($table) {
    $table->id();
    $table->string('status')->nullable();
    $table->integer('count')->nullable();
});

$connection = app('db')->connection();

// Check initial schema
echo "Initial schema:\n";
$info = $connection->select("PRAGMA table_info(test_defaults)");
foreach ($info as $col) {
    if (in_array($col->name, ['status', 'count'])) {
        echo "  {$col->name}: default={$col->dflt_value}\n";
    }
}

// Try to change defaults using Schema::table
echo "\nTrying Schema::table with change()...\n";
Schema::table('test_defaults', function ($table) {
    $table->string('status')->default('active')->change();
    $table->integer('count')->default(0)->change();
});

// Check schema after change
echo "After Schema::table:\n";
$info = $connection->select("PRAGMA table_info(test_defaults)");
foreach ($info as $col) {
    if (in_array($col->name, ['status', 'count'])) {
        echo "  {$col->name}: default={$col->dflt_value}\n";
    }
}

// Clean up
Schema::dropIfExists('test_defaults');
DatabaseSeeder::cleanupTestDatabase();
