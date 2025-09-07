<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use Illuminate\Support\Facades\Schema;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

DatabaseSeeder::setupTestDatabase();

$modelClass = TestModelFactory::create('DefaultTest', [
    'status' => 'string',
    'count' => 'integer',
]);

// Set defaults
Schema::table('defaulttests', function ($table) {
    $table->string('status')->default('active')->change();
    $table->integer('count')->default(0)->change();
});

// Check raw SQLite info
$connection = app('db')->connection();
$info = $connection->select("PRAGMA table_info(defaulttests)");
foreach ($info as $col) {
    if (in_array($col->name, ['status', 'count'])) {
        $raw = $col->dflt_value;
        echo "{$col->name}:\n";
        echo "  Raw value: " . var_export($raw, true) . "\n";
        echo "  Length: " . strlen($raw) . "\n";
        echo "  Bytes: ";
        for ($i = 0; $i < strlen($raw); $i++) {
            echo ord($raw[$i]) . " ";
        }
        echo "\n";
    }
}

DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
