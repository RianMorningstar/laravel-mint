<?php
require 'vendor/autoload.php';

use LaravelMint\Mint;

$app = require 'vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Register the service provider
$app->register(\LaravelMint\MintServiceProvider::class);

// Now check config
$mint = $app->make(Mint::class);
$cacheEnabled = $mint->getConfig('analysis.cache_results');
echo "Cache enabled (with provider): " . var_export($cacheEnabled, true) . "\n";

// Check env
echo "MINT_CACHE_ANALYSIS env: " . var_export(env('MINT_CACHE_ANALYSIS'), true) . "\n";

// Check config directly
echo "Config value: " . var_export(config('mint.analysis.cache_results'), true) . "\n";
