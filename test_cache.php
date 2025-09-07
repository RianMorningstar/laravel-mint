<?php
require 'vendor/autoload.php';

use LaravelMint\Mint;
use Illuminate\Support\Facades\Cache;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Check Mint config
$mint = app(Mint::class);
$cacheEnabled = $mint->getConfig('analysis.cache_results');
echo "Cache enabled: " . var_export($cacheEnabled, true) . "\n";

// Try cache operations
Cache::put('test_key', 'test_value', 60);
$value = Cache::get('test_key');
echo "Cache working: " . ($value === 'test_value' ? 'yes' : 'no') . "\n";

// Clean up
Cache::forget('test_key');
