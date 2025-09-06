<?php

namespace LaravelMint\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use LaravelMint\MintServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            MintServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Mint' => 'LaravelMint\\Facades\\Mint',
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}