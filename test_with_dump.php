<?php
require 'vendor/autoload.php';

use LaravelMint\Tests\TestCase;
use LaravelMint\Tests\Helpers\DatabaseSeeder;
use LaravelMint\Tests\Helpers\TestModelFactory;
use LaravelMint\Analyzers\ModelAnalyzer;
use LaravelMint\Mint;

// Bootstrap the application
$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Create base test case services
$testCase = new class extends TestCase {
    public function runSetUp() {
        $this->setUp();
    }
    public function runTearDown() {
        $this->tearDown();
    }
};

$testCase->runSetUp();

// Now run test
DatabaseSeeder::setupTestDatabase();

$mint = app(Mint::class);
$analyzer = new ModelAnalyzer($mint);

$modelClass = TestModelFactory::create('NullableTest', [
    'required_field' => 'string',
    'optional_field' => 'string',
]);

// Both fields are created as nullable by TestModelFactory
// Let's change required_field to NOT NULL
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

$analysis = $analyzer->analyze($modelClass);
$attributes = $analysis['attributes'];

echo "Attributes keys: " . implode(', ', array_keys($attributes)) . "\n";
echo "required_field details:\n";
var_dump($attributes['required_field'] ?? 'NOT FOUND');
echo "optional_field details:\n";
var_dump($attributes['optional_field'] ?? 'NOT FOUND');

$testCase->runTearDown();
DatabaseSeeder::cleanupTestDatabase();
TestModelFactory::cleanup();
