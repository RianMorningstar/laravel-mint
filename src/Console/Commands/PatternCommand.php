<?php

namespace LaravelMint\Console\Commands;

use Illuminate\Console\Command;
use LaravelMint\Patterns\PatternRegistry;

class PatternCommand extends Command
{
    protected $signature = 'mint:patterns 
                            {--list : List all available patterns}
                            {--category= : Filter by category}
                            {--info= : Get info about a specific pattern}';

    protected $description = 'List and inspect available data patterns';

    public function handle(): int
    {
        $registry = app(PatternRegistry::class);

        if ($pattern = $this->option('info')) {
            $this->showPatternInfo($registry, $pattern);
        } else {
            $this->listPatterns($registry);
        }

        return self::SUCCESS;
    }

    protected function listPatterns(PatternRegistry $registry): void
    {
        $category = $this->option('category');
        $patterns = $category
            ? $registry->getByCategory($category)
            : $registry->all();

        $this->info('Available patterns'.($category ? " in category '{$category}'" : '').':');

        foreach ($patterns as $name => $class) {
            $this->line(" - {$name}");
        }

        if (empty($patterns)) {
            $this->warn('No patterns found.');
        }
    }

    protected function showPatternInfo(PatternRegistry $registry, string $pattern): void
    {
        try {
            $info = $registry->info($pattern);

            $this->info("Pattern: {$pattern}");
            $this->line('Name: '.$info['name']);
            $this->line('Description: '.$info['description']);

            if (! empty($info['aliases'])) {
                $this->line('Aliases: '.implode(', ', $info['aliases']));
            }

            if (! empty($info['parameters'])) {
                $this->newLine();
                $this->info('Parameters:');
                foreach ($info['parameters'] as $param => $details) {
                    $this->line(" - {$param}: ".($details['description'] ?? 'No description'));
                }
            }
        } catch (\Exception $e) {
            $this->error("Pattern '{$pattern}' not found.");
        }
    }
}
