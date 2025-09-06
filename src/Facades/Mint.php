<?php

namespace LaravelMint\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array analyze(string $modelClass)
 * @method static void generate(string $modelClass, int $count = 1, array $options = [])
 * @method static void generateWithScenario(string $scenario, array $options = [])
 * @method static int clear(string $modelClass = null)
 * @method static mixed getConfig(string $key = null, $default = null)
 * 
 * @see \LaravelMint\Mint
 */
class Mint extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mint';
    }
}