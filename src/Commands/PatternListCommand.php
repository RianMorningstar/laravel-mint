<?php

namespace LaravelMint\Commands;

use Illuminate\Console\Command;
use LaravelMint\Patterns\PatternRegistry;
use Symfony\Component\Console\Helper\Table;

class PatternListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mint:pattern:list 
                            {--category= : Filter by category (distribution, temporal, etc.)}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all available patterns for data generation';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $registry = new PatternRegistry;
        $category = $this->option('category');

        if ($category) {
            $patterns = $registry->getByCategory($category);
            $this->info("Patterns in category: {$category}");
        } else {
            $patterns = $registry->all();
            $this->info('All available patterns:');
        }

        if ($this->option('json')) {
            $this->outputJson($patterns, $registry);
        } else {
            $this->outputTable($patterns, $registry);
        }

        return 0;
    }

    /**
     * Output patterns as JSON
     */
    protected function outputJson(array $patterns, PatternRegistry $registry): void
    {
        $output = [];

        foreach ($patterns as $name => $class) {
            $info = $registry->info($name);
            $output[$name] = $info;
        }

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }

    /**
     * Output patterns as table
     */
    protected function outputTable(array $patterns, PatternRegistry $registry): void
    {
        $this->newLine();

        // Group by category
        $categories = [];
        foreach ($patterns as $name => $class) {
            $parts = explode('.', $name);
            $category = count($parts) > 1 ? $parts[0] : 'general';

            if (! isset($categories[$category])) {
                $categories[$category] = [];
            }

            $categories[$category][$name] = $class;
        }

        foreach ($categories as $category => $categoryPatterns) {
            $this->comment(ucfirst($category).' Patterns:');

            $table = new Table($this->output);
            $table->setHeaders(['Name', 'Description', 'Aliases']);

            $rows = [];
            foreach ($categoryPatterns as $name => $class) {
                $info = $registry->info($name);
                $aliases = implode(', ', $info['aliases']);

                $rows[] = [
                    $name,
                    $info['description'],
                    $aliases ?: '-',
                ];
            }

            $table->setRows($rows);
            $table->render();
            $this->newLine();
        }

        // Show usage example
        $this->info('Usage Examples:');
        $this->line('  Generate with normal distribution:');
        $this->line('  php artisan mint:generate User --count=100 --use-patterns --column-patterns=\'{"age": {"type": "normal", "mean": 35, "stddev": 10}}\'');
        $this->newLine();
        $this->line('  Generate with Pareto distribution (80/20 rule):');
        $this->line('  php artisan mint:generate Order --count=1000 --use-patterns --column-patterns=\'{"total": {"type": "pareto", "alpha": 1.16, "xmin": 10}}\'');
        $this->newLine();
        $this->line('  View pattern details:');
        $this->line('  php artisan mint:pattern:show normal');
    }
}
