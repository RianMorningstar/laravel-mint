<?php

namespace LaravelMint\Commands;

use Illuminate\Console\Command;
use LaravelMint\Patterns\PatternRegistry;
use Symfony\Component\Console\Helper\Table;

class PatternShowCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mint:pattern:show 
                            {pattern : The pattern name or alias}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show details about a specific pattern';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $registry = new PatternRegistry();
        $patternName = $this->argument('pattern');
        
        if (!$registry->has($patternName)) {
            $this->error("Pattern '{$patternName}' not found");
            
            // Suggest similar patterns
            $this->suggestSimilarPatterns($patternName, $registry);
            
            return 1;
        }
        
        $info = $registry->info($patternName);
        
        if ($this->option('json')) {
            $this->line(json_encode($info, JSON_PRETTY_PRINT));
        } else {
            $this->displayPatternInfo($info);
        }
        
        return 0;
    }

    /**
     * Display pattern information
     */
    protected function displayPatternInfo(array $info): void
    {
        $this->info($info['name']);
        $this->line(str_repeat('=', strlen($info['name'])));
        $this->newLine();
        
        $this->comment('Description:');
        $this->line('  ' . $info['description']);
        $this->newLine();
        
        if (!empty($info['aliases'])) {
            $this->comment('Aliases:');
            foreach ($info['aliases'] as $alias) {
                $this->line("  • {$alias}");
            }
            $this->newLine();
        }
        
        $this->comment('Parameters:');
        if (empty($info['parameters'])) {
            $this->line('  No parameters');
        } else {
            $table = new Table($this->output);
            $table->setHeaders(['Parameter', 'Type', 'Default', 'Required', 'Description']);
            
            $rows = [];
            foreach ($info['parameters'] as $name => $param) {
                $rows[] = [
                    $name,
                    $param['type'],
                    $this->formatValue($param['default']),
                    $param['required'] ? 'Yes' : 'No',
                    $param['description'],
                ];
            }
            
            $table->setRows($rows);
            $table->render();
        }
        $this->newLine();
        
        $this->comment('Class:');
        $this->line('  ' . $info['class']);
        $this->newLine();
        
        $this->comment('Usage Examples:');
        $this->displayUsageExamples($info);
    }

    /**
     * Format parameter value for display
     */
    protected function formatValue($value): string
    {
        if ($value === null) {
            return 'null';
        }
        
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        if (is_array($value)) {
            return json_encode($value);
        }
        
        return (string) $value;
    }

    /**
     * Display usage examples for the pattern
     */
    protected function displayUsageExamples(array $info): void
    {
        $className = class_basename($info['class']);
        
        switch ($className) {
            case 'NormalDistribution':
                $this->line('  # Generate ages with normal distribution');
                $this->line('  php artisan mint:generate User --count=100 \\');
                $this->line('    --use-patterns \\');
                $this->line('    --column-patterns=\'{"age": {"type": "normal", "mean": 35, "stddev": 10, "min": 18, "max": 80}}\'');
                $this->newLine();
                $this->line('  # Generate prices centered around $50');
                $this->line('  --column-patterns=\'{"price": {"type": "normal", "mean": 50, "stddev": 15, "min": 0.01}}\'');
                break;
                
            case 'ParetoDistribution':
                $this->line('  # Generate customer order values (80/20 rule)');
                $this->line('  php artisan mint:generate Order --count=1000 \\');
                $this->line('    --use-patterns \\');
                $this->line('    --column-patterns=\'{"total": {"type": "pareto", "alpha": 1.16, "xmin": 10, "max": 10000}}\'');
                $this->newLine();
                $this->line('  # Generate page views (few pages get most traffic)');
                $this->line('  --column-patterns=\'{"views": {"type": "pareto", "alpha": 1.5, "xmin": 1}}\'');
                break;
                
            case 'PoissonDistribution':
                $this->line('  # Generate support tickets per day');
                $this->line('  php artisan mint:generate Ticket --count=365 \\');
                $this->line('    --use-patterns \\');
                $this->line('    --column-patterns=\'{"daily_count": {"type": "poisson", "lambda": 15}}\'');
                break;
                
            case 'BusinessHours':
                $this->line('  # Generate traffic patterns for business hours');
                $this->line('  php artisan mint:generate PageView --count=1000 \\');
                $this->line('    --use-patterns \\');
                $this->line('    --column-patterns=\'{"visits": {');
                $this->line('      "type": "business_hours",');
                $this->line('      "peak_value": 1000,');
                $this->line('      "off_peak_value": 100,');
                $this->line('      "business_hours": {"start": 9, "end": 17},');
                $this->line('      "timezone": "America/New_York"');
                $this->line('    }}\'');
                break;
                
            case 'SeasonalPattern':
                $this->line('  # Generate sales with seasonal variations');
                $this->line('  php artisan mint:generate Sale --count=365 \\');
                $this->line('    --use-patterns \\');
                $this->line('    --column-patterns=\'{"revenue": {');
                $this->line('      "type": "seasonal",');
                $this->line('      "base_value": 10000,');
                $this->line('      "amplitude": 3000,');
                $this->line('      "period": "year",');
                $this->line('      "peaks": ["december", "july"]');
                $this->line('    }}\'');
                break;
                
            default:
                $this->line('  php artisan mint:generate Model --count=100 \\');
                $this->line('    --use-patterns \\');
                $this->line('    --column-patterns=\'{"column_name": {"type": "' . $info['name'] . '"}}\'');
        }
    }

    /**
     * Suggest similar patterns
     */
    protected function suggestSimilarPatterns(string $search, PatternRegistry $registry): void
    {
        $patterns = array_merge(
            array_keys($registry->all()),
            array_keys($registry->aliases())
        );
        
        $suggestions = [];
        foreach ($patterns as $pattern) {
            $similarity = similar_text(strtolower($search), strtolower($pattern), $percent);
            if ($percent > 50) {
                $suggestions[$pattern] = $percent;
            }
        }
        
        if (!empty($suggestions)) {
            arsort($suggestions);
            $this->newLine();
            $this->comment('Did you mean:');
            foreach (array_slice($suggestions, 0, 3, true) as $pattern => $percent) {
                $this->line("  • {$pattern}");
            }
        }
    }
}