<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Mint;

class ClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mint:clear 
                            {model : The model class to clear}
                            {--where=* : Conditions in key=value format}
                            {--force : Skip confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear generated test data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $model = $this->argument('model');

        // Parse where conditions
        $conditions = [];
        $whereStrings = $this->option('where');

        if (is_string($whereStrings)) {
            $whereStrings = [$whereStrings];
        }

        foreach ($whereStrings as $whereString) {
            if (strpos($whereString, '=') !== false) {
                [$key, $value] = explode('=', $whereString, 2);
                $conditions[trim($key)] = trim($value);
            }
        }

        // Confirm deletion unless forced
        if (! $this->option('force')) {
            $count = empty($conditions)
                ? $model::count()
                : $model::where($conditions)->count();

            if (! $this->confirm("This will delete {$count} records. Continue?")) {
                $this->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $mint = app(Mint::class);
            $deleted = $mint->clear($model, $conditions);

            $this->info("Successfully deleted {$deleted} records.");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to clear data: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
