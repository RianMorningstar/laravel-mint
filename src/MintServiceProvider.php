<?php

namespace LaravelMint;

use Illuminate\Support\ServiceProvider;
use LaravelMint\Commands\AnalyzeCommand;
use LaravelMint\Commands\ClearCommand;
use LaravelMint\Commands\GenerateCommand;
use LaravelMint\Commands\ImportCommand;
use LaravelMint\Commands\PatternListCommand;
use LaravelMint\Commands\PatternShowCommand;
use LaravelMint\Console\Commands\ExportCommand;
use LaravelMint\Console\Commands\ImportCommand as ConsoleImportCommand;
use LaravelMint\Console\Commands\ScenarioListCommand;
use LaravelMint\Console\Commands\ScenarioRunCommand;
use LaravelMint\Console\Commands\SeedCommand;
use LaravelMint\Integration\FactoryIntegration;
use LaravelMint\Integration\SeederIntegration;
use LaravelMint\Integration\WebhookManager;
use LaravelMint\Patterns\PatternRegistry;
use LaravelMint\Scenarios\ScenarioRunner;

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

        $this->app->singleton(PatternRegistry::class, function ($app) {
            return new PatternRegistry;
        });

        $this->app->singleton(ScenarioRunner::class, function ($app) {
            return new ScenarioRunner($app->make('mint'));
        });

        $this->app->singleton(SeederIntegration::class, function ($app) {
            return new SeederIntegration($app->make('mint'));
        });

        $this->app->singleton(FactoryIntegration::class, function ($app) {
            return new FactoryIntegration($app->make('mint'));
        });

        $this->app->singleton(WebhookManager::class, function ($app) {
            $manager = new WebhookManager;
            $manager->configure(config('mint.webhooks', []));

            return $manager;
        });
    }

    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/Config/mint.php' => config_path('mint.php'),
            ], 'mint-config');

            $this->publishes([
                __DIR__.'/../patterns' => base_path('patterns'),
            ], 'mint-patterns');

            $this->publishes([
                __DIR__.'/Http/routes.php' => base_path('routes/mint.php'),
            ], 'mint-routes');

            $this->commands([
                AnalyzeCommand::class,
                GenerateCommand::class,
                ClearCommand::class,
                ImportCommand::class,
                PatternListCommand::class,
                PatternShowCommand::class,
                ScenarioRunCommand::class,
                ScenarioListCommand::class,
                ConsoleImportCommand::class,
                ExportCommand::class,
                SeedCommand::class,
            ]);
        }
    }

    public function provides(): array
    {
        return ['mint'];
    }
}
