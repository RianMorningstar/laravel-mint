<?php

namespace LaravelMint\Performance;

use Illuminate\Support\Facades\DB;

class Benchmark
{
    protected array $benchmarks = [];

    protected array $results = [];

    protected MemoryMonitor $memoryMonitor;

    protected QueryOptimizer $queryOptimizer;

    protected int $iterations = 100;

    protected bool $verbose = false;

    public function __construct()
    {
        $this->memoryMonitor = new MemoryMonitor;
        $this->queryOptimizer = new QueryOptimizer;
    }

    /**
     * Run a benchmark
     */
    public function run(string $name, callable $callback, array $options = []): BenchmarkResult
    {
        $iterations = $options['iterations'] ?? $this->iterations;
        $warmup = $options['warmup'] ?? max(1, (int) ($iterations * 0.1));

        $result = new BenchmarkResult($name);

        // Warmup runs
        for ($i = 0; $i < $warmup; $i++) {
            $callback();
        }

        // Reset before actual benchmark
        gc_collect_cycles();

        // Memory monitoring
        $this->memoryMonitor->start($name);

        // Query profiling
        $profile = $this->queryOptimizer->profile(function () use ($callback, $iterations, $result) {
            $times = [];
            $memories = [];

            for ($i = 0; $i < $iterations; $i++) {
                $startTime = microtime(true);
                $startMemory = memory_get_usage(true);

                try {
                    $callback();
                } catch (\Exception $e) {
                    $result->addError($e->getMessage());
                }

                $times[] = microtime(true) - $startTime;
                $memories[] = memory_get_usage(true) - $startMemory;
            }

            $result->setTimes($times);
            $result->setMemories($memories);
        });

        // Stop memory monitoring
        $memoryMetrics = $this->memoryMonitor->stop($name);

        // Calculate statistics
        $result->calculate();
        $result->setQueryProfile($profile);
        $result->setMemoryMetrics($memoryMetrics);

        // Store result
        $this->results[$name] = $result;

        return $result;
    }

    /**
     * Compare multiple implementations
     */
    public function compare(array $implementations, array $options = []): ComparisonResult
    {
        $comparison = new ComparisonResult;

        foreach ($implementations as $name => $callback) {
            if ($this->verbose) {
                echo "Running benchmark: {$name}...\n";
            }

            $result = $this->run($name, $callback, $options);
            $comparison->addResult($name, $result);
        }

        $comparison->analyze();

        return $comparison;
    }

    /**
     * Benchmark data generation
     */
    public function benchmarkGeneration(string $modelClass, int $count, array $methods = []): array
    {
        $results = [];

        // Default generation
        $results['default'] = $this->run('Default Generation', function () use ($modelClass, $count) {
            DB::beginTransaction();

            for ($i = 0; $i < $count; $i++) {
                $modelClass::factory()->create();
            }

            DB::rollBack();
        }, ['iterations' => 10]);

        // Batch insert
        $results['batch'] = $this->run('Batch Insert', function () use ($modelClass, $count) {
            DB::beginTransaction();

            $data = [];
            for ($i = 0; $i < $count; $i++) {
                $data[] = $modelClass::factory()->make()->toArray();
            }

            DB::table((new $modelClass)->getTable())->insert($data);
            DB::rollBack();
        }, ['iterations' => 10]);

        // Stream processing
        if (in_array('stream', $methods)) {
            $results['stream'] = $this->run('Stream Processing', function () use ($modelClass, $count) {
                DB::beginTransaction();

                $processor = new StreamProcessor;
                $processor->generate($modelClass, $count, function ($index) use ($modelClass) {
                    return $modelClass::factory()->make()->toArray();
                });

                DB::rollBack();
            }, ['iterations' => 10]);
        }

        // Parallel processing
        if (in_array('parallel', $methods)) {
            $results['parallel'] = $this->run('Parallel Processing', function () use ($modelClass, $count) {
                DB::beginTransaction();

                $processor = new ParallelProcessor;
                $processor->generate($modelClass, $count, function ($index) use ($modelClass) {
                    return $modelClass::factory()->make()->toArray();
                });

                DB::rollBack();
            }, ['iterations' => 10]);
        }

        return $results;
    }

    /**
     * Benchmark query performance
     */
    public function benchmarkQuery(callable $query, array $optimizations = []): array
    {
        $results = [];

        // Baseline query
        $results['baseline'] = $this->run('Baseline Query', $query);

        // With eager loading
        if (in_array('eager', $optimizations)) {
            $results['eager'] = $this->run('With Eager Loading', function () use ($query) {
                $this->queryOptimizer->enableAnalyzeMode();
                $query();
            });
        }

        // With caching
        if (in_array('cache', $optimizations)) {
            $results['cached'] = $this->run('With Caching', function () use ($query) {
                $cache = new CacheManager;
                $cache->remember('benchmark_query', $query, 60);
            });
        }

        return $results;
    }

    /**
     * Load test
     */
    public function loadTest(callable $operation, array $config = []): LoadTestResult
    {
        $duration = $config['duration'] ?? 60; // seconds
        $concurrency = $config['concurrency'] ?? 10;
        $rampUp = $config['ramp_up'] ?? 10; // seconds

        $result = new LoadTestResult;
        $startTime = microtime(true);
        $endTime = $startTime + $duration;

        $operations = 0;
        $errors = 0;
        $responseTimes = [];

        while (microtime(true) < $endTime) {
            $opStartTime = microtime(true);

            try {
                $operation();
                $operations++;
            } catch (\Exception $e) {
                $errors++;
                $result->addError($e->getMessage());
            }

            $responseTimes[] = microtime(true) - $opStartTime;

            // Simple rate limiting based on concurrency
            usleep((int) (1000000 / $concurrency));
        }

        $result->setOperations($operations);
        $result->setErrors($errors);
        $result->setResponseTimes($responseTimes);
        $result->setDuration(microtime(true) - $startTime);
        $result->calculate();

        return $result;
    }

    /**
     * Set iterations
     */
    public function setIterations(int $iterations): self
    {
        $this->iterations = max(1, $iterations);

        return $this;
    }

    /**
     * Set verbose mode
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;

        return $this;
    }

    /**
     * Get results
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Export results
     */
    public function export(string $format = 'json'): string
    {
        $data = array_map(function ($result) {
            return $result->toArray();
        }, $this->results);

        return match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            'csv' => $this->exportCsv($data),
            default => print_r($data, true),
        };
    }

    /**
     * Export as CSV
     */
    protected function exportCsv(array $data): string
    {
        $csv = "Benchmark,Mean Time,Median Time,Min Time,Max Time,Memory,Queries\n";

        foreach ($data as $name => $result) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%d\n",
                $name,
                $result['mean_time'],
                $result['median_time'],
                $result['min_time'],
                $result['max_time'],
                $result['mean_memory'],
                $result['query_count'] ?? 0
            );
        }

        return $csv;
    }
}

class BenchmarkResult
{
    protected string $name;

    protected array $times = [];

    protected array $memories = [];

    protected array $errors = [];

    protected ?QueryProfile $queryProfile = null;

    protected ?MemoryMetrics $memoryMetrics = null;

    protected array $statistics = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function setTimes(array $times): void
    {
        $this->times = $times;
    }

    public function setMemories(array $memories): void
    {
        $this->memories = $memories;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function setQueryProfile(QueryProfile $profile): void
    {
        $this->queryProfile = $profile;
    }

    public function setMemoryMetrics(MemoryMetrics $metrics): void
    {
        $this->memoryMetrics = $metrics;
    }

    public function calculate(): void
    {
        if (! empty($this->times)) {
            $this->statistics['mean_time'] = array_sum($this->times) / count($this->times);
            $this->statistics['median_time'] = $this->median($this->times);
            $this->statistics['min_time'] = min($this->times);
            $this->statistics['max_time'] = max($this->times);
            $this->statistics['stddev_time'] = $this->standardDeviation($this->times);
            $this->statistics['ops_per_second'] = 1 / $this->statistics['mean_time'];
        }

        if (! empty($this->memories)) {
            $this->statistics['mean_memory'] = array_sum($this->memories) / count($this->memories);
            $this->statistics['max_memory'] = max($this->memories);
        }
    }

    protected function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor(($count - 1) / 2);

        if ($count % 2) {
            return $values[$middle];
        }

        return ($values[$middle] + $values[$middle + 1]) / 2;
    }

    protected function standardDeviation(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        $variance /= count($values);

        return sqrt($variance);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'iterations' => count($this->times),
            'mean_time' => isset($this->statistics['mean_time'])
                ? round($this->statistics['mean_time'] * 1000, 4).'ms'
                : 'N/A',
            'median_time' => isset($this->statistics['median_time'])
                ? round($this->statistics['median_time'] * 1000, 4).'ms'
                : 'N/A',
            'min_time' => isset($this->statistics['min_time'])
                ? round($this->statistics['min_time'] * 1000, 4).'ms'
                : 'N/A',
            'max_time' => isset($this->statistics['max_time'])
                ? round($this->statistics['max_time'] * 1000, 4).'ms'
                : 'N/A',
            'stddev' => isset($this->statistics['stddev_time'])
                ? round($this->statistics['stddev_time'] * 1000, 4).'ms'
                : 'N/A',
            'ops_per_second' => isset($this->statistics['ops_per_second'])
                ? round($this->statistics['ops_per_second'], 2)
                : 'N/A',
            'mean_memory' => isset($this->statistics['mean_memory'])
                ? $this->formatBytes($this->statistics['mean_memory'])
                : 'N/A',
            'max_memory' => isset($this->statistics['max_memory'])
                ? $this->formatBytes($this->statistics['max_memory'])
                : 'N/A',
            'query_count' => $this->queryProfile ? $this->queryProfile->getQueryCount() : 0,
            'errors' => count($this->errors),
        ];
    }

    protected function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}

class ComparisonResult
{
    protected array $results = [];

    protected array $analysis = [];

    public function addResult(string $name, BenchmarkResult $result): void
    {
        $this->results[$name] = $result;
    }

    public function analyze(): void
    {
        if (empty($this->results)) {
            return;
        }

        // Find fastest and slowest
        $times = [];
        foreach ($this->results as $name => $result) {
            $data = $result->toArray();
            if (isset($data['mean_time'])) {
                $times[$name] = (float) str_replace('ms', '', $data['mean_time']);
            }
        }

        if (! empty($times)) {
            asort($times);
            $fastest = array_key_first($times);
            $slowest = array_key_last($times);

            $this->analysis['fastest'] = $fastest;
            $this->analysis['slowest'] = $slowest;
            $this->analysis['speedup'] = $times[$slowest] / $times[$fastest];
        }
    }

    public function toArray(): array
    {
        $data = [
            'results' => [],
            'analysis' => $this->analysis,
        ];

        foreach ($this->results as $name => $result) {
            $data['results'][$name] = $result->toArray();
        }

        return $data;
    }

    public function getWinner(): ?string
    {
        return $this->analysis['fastest'] ?? null;
    }
}

class LoadTestResult
{
    protected int $operations = 0;

    protected int $errors = 0;

    protected array $responseTimes = [];

    protected float $duration = 0;

    protected array $errorMessages = [];

    protected array $statistics = [];

    public function setOperations(int $count): void
    {
        $this->operations = $count;
    }

    public function setErrors(int $count): void
    {
        $this->errors = $count;
    }

    public function setResponseTimes(array $times): void
    {
        $this->responseTimes = $times;
    }

    public function setDuration(float $duration): void
    {
        $this->duration = $duration;
    }

    public function addError(string $message): void
    {
        $this->errorMessages[] = $message;
    }

    public function calculate(): void
    {
        if (! empty($this->responseTimes)) {
            sort($this->responseTimes);
            $count = count($this->responseTimes);

            $this->statistics = [
                'throughput' => $this->operations / $this->duration,
                'error_rate' => ($this->errors / $this->operations) * 100,
                'mean_response' => array_sum($this->responseTimes) / $count,
                'median_response' => $this->responseTimes[(int) ($count / 2)],
                'p95_response' => $this->responseTimes[(int) ($count * 0.95)],
                'p99_response' => $this->responseTimes[(int) ($count * 0.99)],
                'min_response' => min($this->responseTimes),
                'max_response' => max($this->responseTimes),
            ];
        }
    }

    public function toArray(): array
    {
        return [
            'duration' => round($this->duration, 2).'s',
            'operations' => $this->operations,
            'errors' => $this->errors,
            'throughput' => round($this->statistics['throughput'] ?? 0, 2).' ops/s',
            'error_rate' => round($this->statistics['error_rate'] ?? 0, 2).'%',
            'mean_response' => round(($this->statistics['mean_response'] ?? 0) * 1000, 2).'ms',
            'median_response' => round(($this->statistics['median_response'] ?? 0) * 1000, 2).'ms',
            'p95_response' => round(($this->statistics['p95_response'] ?? 0) * 1000, 2).'ms',
            'p99_response' => round(($this->statistics['p99_response'] ?? 0) * 1000, 2).'ms',
        ];
    }
}
