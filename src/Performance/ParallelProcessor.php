<?php

namespace LaravelMint\Performance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class ParallelProcessor
{
    protected int $workers = 4;

    protected int $chunkSize = 1000;

    protected string $method = 'process'; // process, thread, or queue

    protected array $results = [];

    protected array $errors = [];

    /**
     * Process data in parallel
     */
    public function process(array $data, callable $processor, array $options = []): ParallelResult
    {
        $this->workers = $options['workers'] ?? $this->workers;
        $this->chunkSize = $options['chunk_size'] ?? $this->chunkSize;
        $this->method = $options['method'] ?? $this->method;

        $result = new ParallelResult;
        $startTime = microtime(true);

        switch ($this->method) {
            case 'process':
                $this->processWithSubprocesses($data, $processor, $result);
                break;
            case 'queue':
                $this->processWithQueues($data, $processor, $result);
                break;
            default:
                $this->processWithBatches($data, $processor, $result);
        }

        $result->setExecutionTime(microtime(true) - $startTime);

        return $result;
    }

    /**
     * Process using subprocesses (most efficient for CPU-bound tasks)
     */
    protected function processWithSubprocesses(array $data, callable $processor, ParallelResult $result): void
    {
        $chunks = array_chunk($data, $this->chunkSize);
        $chunkGroups = array_chunk($chunks, $this->workers);

        foreach ($chunkGroups as $group) {
            $processes = [];

            foreach ($group as $index => $chunk) {
                // Create a temporary file for the chunk
                $inputFile = tempnam(sys_get_temp_dir(), 'mint_input_');
                $outputFile = tempnam(sys_get_temp_dir(), 'mint_output_');

                file_put_contents($inputFile, serialize([
                    'chunk' => $chunk,
                    'processor' => serialize($processor),
                ]));

                // Create subprocess command
                $command = $this->buildWorkerCommand($inputFile, $outputFile);

                $processes[] = [
                    'process' => Process::start($command),
                    'input' => $inputFile,
                    'output' => $outputFile,
                ];
            }

            // Wait for all processes to complete
            foreach ($processes as $proc) {
                $proc['process']->wait();

                if ($proc['process']->successful()) {
                    $output = unserialize(file_get_contents($proc['output']));
                    $result->addProcessed($output['processed']);

                    if (! empty($output['errors'])) {
                        foreach ($output['errors'] as $error) {
                            $result->addError($error);
                        }
                    }
                } else {
                    $result->addError('Process failed: '.$proc['process']->errorOutput());
                }

                // Clean up temp files
                @unlink($proc['input']);
                @unlink($proc['output']);
            }
        }
    }

    /**
     * Process using queues (best for I/O-bound tasks)
     */
    protected function processWithQueues(array $data, callable $processor, ParallelResult $result): void
    {
        $chunks = array_chunk($data, $this->chunkSize);
        $jobIds = [];

        foreach ($chunks as $chunk) {
            // Dispatch job to queue
            $jobId = uniqid('mint_job_');
            $jobIds[] = $jobId;

            // Note: This would dispatch to Laravel's queue system
            // For now, we'll simulate with direct processing
            $this->processChunk($chunk, $processor, $result);
        }

        // In real implementation, wait for queue jobs to complete
        // and collect results
    }

    /**
     * Process using batches (fallback method)
     */
    protected function processWithBatches(array $data, callable $processor, ParallelResult $result): void
    {
        $chunks = array_chunk($data, $this->chunkSize);

        foreach ($chunks as $chunk) {
            $this->processChunk($chunk, $processor, $result);
        }
    }

    /**
     * Process a single chunk
     */
    protected function processChunk(array $chunk, callable $processor, ParallelResult $result): void
    {
        foreach ($chunk as $item) {
            try {
                $processor($item);
                $result->incrementProcessed();
            } catch (\Exception $e) {
                $result->addError($e->getMessage());
            }
        }
    }

    /**
     * Build worker command for subprocess
     */
    protected function buildWorkerCommand(string $inputFile, string $outputFile): string
    {
        $script = __DIR__.'/../../workers/parallel_worker.php';

        // Create worker script if it doesn't exist
        if (! file_exists($script)) {
            $this->createWorkerScript($script);
        }

        return sprintf('php %s %s %s', escapeshellarg($script), escapeshellarg($inputFile), escapeshellarg($outputFile));
    }

    /**
     * Create worker script
     */
    protected function createWorkerScript(string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $script = <<<'PHP'
<?php
// Parallel worker script for Laravel Mint

$inputFile = $argv[1] ?? null;
$outputFile = $argv[2] ?? null;

if (!$inputFile || !$outputFile) {
    exit(1);
}

try {
    $input = unserialize(file_get_contents($inputFile));
    $chunk = $input['chunk'];
    $processor = unserialize($input['processor']);
    
    $processed = 0;
    $errors = [];
    
    foreach ($chunk as $item) {
        try {
            $processor($item);
            $processed++;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
    
    file_put_contents($outputFile, serialize([
        'processed' => $processed,
        'errors' => $errors,
    ]));
} catch (Exception $e) {
    file_put_contents($outputFile, serialize([
        'processed' => 0,
        'errors' => [$e->getMessage()],
    ]));
}
PHP;

        file_put_contents($path, $script);
    }

    /**
     * Map-reduce pattern implementation
     */
    public function mapReduce(array $data, callable $mapper, callable $reducer, $initial = null)
    {
        // Map phase - process in parallel
        $mapped = $this->map($data, $mapper);

        // Reduce phase - combine results
        return $this->reduce($mapped, $reducer, $initial);
    }

    /**
     * Parallel map operation
     */
    public function map(array $data, callable $mapper): array
    {
        $result = $this->process($data, function ($item) use ($mapper) {
            return $mapper($item);
        });

        return $result->getResults();
    }

    /**
     * Reduce operation
     */
    public function reduce(array $data, callable $reducer, $initial = null)
    {
        return array_reduce($data, $reducer, $initial);
    }

    /**
     * Parallel generation
     */
    public function generate(string $modelClass, int $count, callable $generator, array $options = []): ParallelResult
    {
        $workers = $options['workers'] ?? $this->workers;
        $perWorker = (int) ceil($count / $workers);

        $result = new ParallelResult;
        $startTime = microtime(true);

        // Create work distribution
        $workload = [];
        for ($i = 0; $i < $workers; $i++) {
            $start = $i * $perWorker;
            $end = min($start + $perWorker, $count);

            if ($start < $count) {
                $workload[] = [
                    'start' => $start,
                    'end' => $end,
                    'count' => $end - $start,
                ];
            }
        }

        // Process workload in parallel
        $this->processWorkload($workload, $modelClass, $generator, $result);

        $result->setExecutionTime(microtime(true) - $startTime);

        return $result;
    }

    /**
     * Process workload distribution
     */
    protected function processWorkload(array $workload, string $modelClass, callable $generator, ParallelResult $result): void
    {
        foreach ($workload as $work) {
            DB::beginTransaction();

            try {
                for ($i = $work['start']; $i < $work['end']; $i++) {
                    $data = $generator($i);
                    $modelClass::create($data);
                    $result->incrementProcessed();
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $result->addError('Worker failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Set number of workers
     */
    public function setWorkers(int $workers): self
    {
        $this->workers = max(1, $workers);

        return $this;
    }

    /**
     * Set chunk size
     */
    public function setChunkSize(int $size): self
    {
        $this->chunkSize = max(1, $size);

        return $this;
    }

    /**
     * Set processing method
     */
    public function setMethod(string $method): self
    {
        if (in_array($method, ['process', 'queue', 'batch'])) {
            $this->method = $method;
        }

        return $this;
    }

    /**
     * Get optimal worker count based on system
     */
    public function getOptimalWorkers(): int
    {
        // Try to detect CPU cores
        if (function_exists('swoole_cpu_num')) {
            return swoole_cpu_num();
        }

        // Linux/Mac
        if (PHP_OS_FAMILY === 'Linux' || PHP_OS_FAMILY === 'Darwin') {
            $cores = shell_exec('nproc 2>/dev/null');
            if ($cores) {
                return (int) $cores;
            }
        }

        // Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $cores = shell_exec('wmic cpu get NumberOfCores');
            if (preg_match('/(\d+)/', $cores, $matches)) {
                return (int) $matches[1];
            }
        }

        // Default to 4 workers
        return 4;
    }
}

class ParallelResult
{
    protected int $processed = 0;

    protected array $errors = [];

    protected array $results = [];

    protected float $executionTime = 0;

    protected array $workerStats = [];

    public function incrementProcessed(): void
    {
        $this->processed++;
    }

    public function addProcessed(int $count): void
    {
        $this->processed += $count;
    }

    public function getProcessed(): int
    {
        return $this->processed;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function addResult($result): void
    {
        $this->results[] = $result;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function setExecutionTime(float $time): void
    {
        $this->executionTime = $time;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function addWorkerStat(int $workerId, array $stats): void
    {
        $this->workerStats[$workerId] = $stats;
    }

    public function getWorkerStats(): array
    {
        return $this->workerStats;
    }

    public function isSuccess(): bool
    {
        return empty($this->errors);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->isSuccess(),
            'processed' => $this->processed,
            'errors' => count($this->errors),
            'execution_time' => round($this->executionTime, 2).'s',
            'throughput' => $this->executionTime > 0
                ? round($this->processed / $this->executionTime, 2).' items/s'
                : 'N/A',
        ];
    }
}
