<?php

namespace LaravelMint\Tests\Unit;

use LaravelMint\Mint;
use LaravelMint\Tests\TestCase;

class MintServiceProviderTest extends TestCase
{
    public function test_service_provider_is_registered()
    {
        $this->assertTrue($this->app->providerIsLoaded('LaravelMint\\MintServiceProvider'));
    }

    public function test_mint_facade_is_registered()
    {
        $this->assertInstanceOf(Mint::class, $this->app->make('mint'));
    }

    public function test_config_is_loaded()
    {
        $config = $this->app['config']->get('mint');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('generation', $config);
        $this->assertArrayHasKey('patterns', $config);
        $this->assertArrayHasKey('scenarios', $config);
    }

    public function test_commands_are_registered()
    {
        $commands = [
            'mint:analyze',
            'mint:generate',
            'mint:clear',
            'mint:import',
        ];

        // Get all registered commands
        $registeredCommands = \Illuminate\Support\Facades\Artisan::all();

        foreach ($commands as $command) {
            $this->assertArrayHasKey($command, $registeredCommands, "Command {$command} is not registered");
        }
    }
}
