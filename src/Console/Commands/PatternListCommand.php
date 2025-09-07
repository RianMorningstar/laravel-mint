<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Patterns\PatternRegistry;

class PatternListCommand extends Command
{
    protected $signature = 'mint:pattern:list 
                            {--category= : Filter by category}';

    protected $description = 'List all available data patterns';

    public function handle(): int
    {
        $registry = app(PatternRegistry::class);

        $category = $this->option('category');
        $patterns = $category
            ? $registry->getByCategory($category)
            : $registry->all();

        $this->info('Available Patterns'.($category ? " in category '{$category}'" : '').':');

        foreach ($patterns as $name => $class) {
            $this->line(" - {$name}");
        }

        return self::SUCCESS;
    }
}
