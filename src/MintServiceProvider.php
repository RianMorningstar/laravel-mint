<?php

namespace LaravelMint;

use Illuminate\Support\ServiceProvider;
use LaravelMint\Commands\AnalyzeCommand;
use LaravelMint\Commands\GenerateCommand;
use LaravelMint\Commands\ClearCommand;
use LaravelMint\Commands\ImportCommand;

class MintServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/Config/mint.php', 'mint'
        );

        $this->app->singleton('mint', function ($app) {
            return new Mint($app);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/Config/mint.php' => config_path('mint.php'),
            ], 'mint-config');

            $this->publishes([
                __DIR__.'/../patterns' => base_path('patterns'),
            ], 'mint-patterns');

            $this->commands([
                AnalyzeCommand::class,
                GenerateCommand::class,
                ClearCommand::class,
                ImportCommand::class,
            ]);
        }
    }

    public function provides(): array
    {
        return ['mint'];
    }
}