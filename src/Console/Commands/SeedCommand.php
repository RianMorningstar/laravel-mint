<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Integration\SeederIntegration;

class SeedCommand extends Command
{
    protected $signature = 'mint:seed 
                            {--model=* : Model classes to seed}
                            {--generate : Generate seeder files}
                            {--smart : Generate smart seeder with dependencies}
                            {--environment= : Seed for specific environment}
                            {--rollback : Rollback seeded data}
                            {--stats : Show seeding statistics}';

    protected $description = 'Generate database seeders or seed data';

    protected SeederIntegration $seeder;

    public function __construct(SeederIntegration $seeder)
    {
        parent::__construct();
        $this->seeder = $seeder;
    }

    public function handle()
    {
        // Show statistics
        if ($this->option('stats')) {
            return $this->showStatistics();
        }

        // Rollback seeded data
        if ($this->option('rollback')) {
            return $this->rollbackData();
        }

        // Generate seeder files
        if ($this->option('generate')) {
            return $this->generateSeeders();
        }

        // Seed for environment
        if ($environment = $this->option('environment')) {
            return $this->seedEnvironment($environment);
        }

        $this->error('Please specify an action: --generate, --environment, --rollback, or --stats');

        return 1;
    }

    protected function generateSeeders(): int
    {
        $models = $this->option('model');

        if (empty($models)) {
            $this->error('Please specify models with --model option');

            return 1;
        }

        // Validate models
        foreach ($models as $model) {
            if (! class_exists($model)) {
                $this->error("Model not found: {$model}");

                return 1;
            }
        }

        if ($this->option('smart')) {
            $this->info('Generating smart seeder with dependencies...');

            try {
                $path = $this->seeder->generateSmartSeeder($models);
                $this->info("✓ Smart seeder created: {$path}");

                $this->newLine();
                $this->info('To use this seeder, add it to your DatabaseSeeder:');
                $this->line('  $this->call(SmartDataSeeder::class);');

                return 0;
            } catch (\Exception $e) {
                $this->error("Failed to generate smart seeder: {$e->getMessage()}");

                return 1;
            }
        }

        // Generate individual seeders
        $this->info('Generating seeders...');
        $generated = [];

        foreach ($models as $model) {
            try {
                $path = $this->seeder->generateSeeder($model);
                $generated[] = basename($path);
                $this->info('✓ Created: '.basename($path));
            } catch (\Exception $e) {
                $this->error("Failed to generate seeder for {$model}: {$e->getMessage()}");
            }
        }

        if (! empty($generated)) {
            $this->newLine();
            $this->info('To use these seeders, add them to your DatabaseSeeder:');
            foreach ($generated as $seeder) {
                $className = str_replace('.php', '', $seeder);
                $this->line("  \$this->call({$className}::class);");
            }
        }

        return empty($generated) ? 1 : 0;
    }

    protected function seedEnvironment(string $environment): int
    {
        $this->info("Seeding data for environment: {$environment}");

        try {
            $this->seeder->seedForEnvironment($environment);
            $this->info('✓ Seeding completed successfully');

            // Show statistics
            $stats = $this->seeder->getStatistics($environment);
            if (! empty($stats[$environment])) {
                $this->newLine();
                $this->info('Seeded records:');
                foreach ($stats[$environment] as $model => $count) {
                    $this->line("  • {$model}: {$count}");
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("Seeding failed: {$e->getMessage()}");

            return 1;
        }
    }

    protected function rollbackData(): int
    {
        $environment = $this->option('environment') ?? app()->environment();

        $this->warn("Rolling back seeded data for environment: {$environment}");

        if (! $this->confirm('Are you sure you want to rollback seeded data?')) {
            $this->info('Rollback cancelled');

            return 0;
        }

        try {
            $deleted = $this->seeder->rollback($environment);
            $this->info("✓ Rolled back {$deleted} records");

            return 0;
        } catch (\Exception $e) {
            $this->error("Rollback failed: {$e->getMessage()}");

            return 1;
        }
    }

    protected function showStatistics(): int
    {
        $stats = $this->seeder->getStatistics();

        if (empty($stats)) {
            $this->info('No seeding statistics available');

            return 0;
        }

        $this->info('Seeding Statistics:');
        $this->newLine();

        foreach ($stats as $environment => $models) {
            $this->info("Environment: {$environment}");

            $tableData = [];
            $total = 0;

            foreach ($models as $model => $count) {
                $tableData[] = [class_basename($model), number_format($count)];
                $total += $count;
            }

            $tableData[] = ['<info>Total</info>', '<info>'.number_format($total).'</info>'];

            $this->table(['Model', 'Count'], $tableData);
            $this->newLine();
        }

        return 0;
    }
}
