<?php

namespace LaravelMint\Performance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class StreamProcessor
{
    protected int $chunkSize = 1000;

    protected int $memoryLimit;

    protected int $memoryThreshold;

    protected bool $gcEnabled = true;

    protected array $callbacks = [];

    public function __construct()
    {
        $this->memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $this->memoryThreshold = (int) ($this->memoryLimit * 0.8); // Use 80% as threshold
    }

    /**
     * Process records in a memory-efficient stream
     */
    public function stream(string $modelClass, callable $processor, array $options = []): StreamResult
    {
        $result = new StreamResult;
        $chunkSize = $options['chunk_size'] ?? $this->chunkSize;
        $query = $modelClass::query();

        // Apply query constraints
        if (isset($options['where'])) {
            foreach ($options['where'] as $column => $value) {
                $query->where($column, $value);
            }
        }

        if (isset($options['order_by'])) {
            $query->orderBy($options['order_by'], $options['order_direction'] ?? 'asc');
        }

        $totalCount = $query->count();
        $result->setTotal($totalCount);

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $processed = 0;

        // Use cursor for memory efficiency
        if ($options['use_cursor'] ?? true) {
            $this->processCursor($query, $processor, $result);
        } else {
            $this->processChunks($query, $processor, $result, $chunkSize);
        }

        $result->setExecutionTime(microtime(true) - $startTime);
        $result->setPeakMemory(memory_get_peak_usage(true) - $startMemory);

        return $result;
    }

    /**
     * Process using cursor (most memory efficient)
     */
    protected function processCursor($query, callable $processor, StreamResult $result): void
    {
        $processed = 0;

        foreach ($query->cursor() as $record) {
            try {
                $processor($record);
                $processed++;
                $result->incrementProcessed();

                // Check memory usage periodically
                if ($processed % 100 === 0) {
                    $this->checkMemory($result);
                    $this->reportProgress($processed, $result->getTotal());
                }
            } catch (\Exception $e) {
                $result->addError("Record {$record->id}: ".$e->getMessage());
            }
        }
    }

    /**
     * Process using chunks
     */
    protected function processChunks($query, callable $processor, StreamResult $result, int $chunkSize): void
    {
        $query->chunk($chunkSize, function ($records) use ($processor, $result) {
            foreach ($records as $record) {
                try {
                    $processor($record);
                    $result->incrementProcessed();
                } catch (\Exception $e) {
                    $result->addError("Record {$record->id}: ".$e->getMessage());
                }
            }

            $this->checkMemory($result);
            $this->reportProgress($result->getProcessed(), $result->getTotal());

            // Force garbage collection after each chunk
            if ($this->gcEnabled) {
                gc_collect_cycles();
            }
        });
    }

    /**
     * Generate records in a stream
     */
    public function generate(string $modelClass, int $count, callable $generator, array $options = []): StreamResult
    {
        $result = new StreamResult;
        $result->setTotal($count);

        $chunkSize = $options['chunk_size'] ?? $this->chunkSize;
        $batchInsert = $options['batch_insert'] ?? true;

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $chunks = (int) ceil($count / $chunkSize);
        $generated = 0;

        for ($i = 0; $i < $chunks; $i++) {
            $currentChunkSize = min($chunkSize, $count - $generated);
            $records = [];

            for ($j = 0; $j < $currentChunkSize; $j++) {
                try {
                    $data = $generator($generated + $j);

                    if ($batchInsert) {
                        $records[] = $this->prepareForInsert($data, $modelClass);
                    } else {
                        $modelClass::create($data);
                        $result->incrementProcessed();
                    }
                } catch (\Exception $e) {
                    $result->addError("Generation error at index {$generated}: ".$e->getMessage());
                }
            }

            // Batch insert
            if ($batchInsert && ! empty($records)) {
                try {
                    DB::table((new $modelClass)->getTable())->insert($records);
                    $result->addProcessed(count($records));
                } catch (\Exception $e) {
                    $result->addError('Batch insert error: '.$e->getMessage());
                }
            }

            $generated += $currentChunkSize;

            $this->checkMemory($result);
            $this->reportProgress($generated, $count);

            if ($this->gcEnabled) {
                gc_collect_cycles();
            }
        }

        $result->setExecutionTime(microtime(true) - $startTime);
        $result->setPeakMemory(memory_get_peak_usage(true) - $startMemory);

        return $result;
    }

    /**
     * Process large file in stream
     */
    public function processFile(string $filepath, callable $processor, array $options = []): StreamResult
    {
        $result = new StreamResult;

        if (! file_exists($filepath)) {
            $result->addError("File not found: {$filepath}");

            return $result;
        }

        $fileSize = filesize($filepath);
        $result->setTotal($fileSize);

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        $handle = fopen($filepath, 'r');
        $lineNumber = 0;
        $bytesRead = 0;

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $bytesRead += strlen($line);

            try {
                $processor($line, $lineNumber);
                $result->incrementProcessed();
            } catch (\Exception $e) {
                $result->addError("Line {$lineNumber}: ".$e->getMessage());
            }

            // Check memory periodically
            if ($lineNumber % 1000 === 0) {
                $this->checkMemory($result);
                $this->reportProgress($bytesRead, $fileSize);
            }
        }

        fclose($handle);

        $result->setExecutionTime(microtime(true) - $startTime);
        $result->setPeakMemory(memory_get_peak_usage(true) - $startMemory);

        return $result;
    }

    /**
     * Create lazy collection for streaming
     */
    public function lazy(string $modelClass, array $constraints = []): LazyCollection
    {
        $query = $modelClass::query();

        foreach ($constraints as $method => $params) {
            $query->$method(...(array) $params);
        }

        return $query->cursor();
    }

    /**
     * Stream with transformation pipeline
     */
    public function pipeline(string $modelClass, array $transformers, array $options = []): StreamResult
    {
        $pipeline = function ($record) use ($transformers) {
            $result = $record;

            foreach ($transformers as $transformer) {
                $result = $transformer($result);

                if ($result === null) {
                    break; // Filter out nulls
                }
            }

            return $result;
        };

        return $this->stream($modelClass, $pipeline, $options);
    }

    /**
     * Check memory usage
     */
    protected function checkMemory(StreamResult $result): void
    {
        $currentMemory = memory_get_usage(true);

        if ($currentMemory > $this->memoryThreshold) {
            $result->addWarning(sprintf(
                'Memory usage approaching limit: %s / %s',
                $this->formatBytes($currentMemory),
                $this->formatBytes($this->memoryLimit)
            ));

            // Force garbage collection
            if ($this->gcEnabled) {
                gc_collect_cycles();
            }
        }
    }

    /**
     * Report progress
     */
    protected function reportProgress(int $current, int $total): void
    {
        if (isset($this->callbacks['progress'])) {
            call_user_func($this->callbacks['progress'], $current, $total);
        }
    }

    /**
     * Set progress callback
     */
    public function onProgress(callable $callback): self
    {
        $this->callbacks['progress'] = $callback;

        return $this;
    }

    /**
     * Set chunk size
     */
    public function chunkSize(int $size): self
    {
        $this->chunkSize = $size;

        return $this;
    }

    /**
     * Enable/disable garbage collection
     */
    public function garbageCollection(bool $enabled): self
    {
        $this->gcEnabled = $enabled;

        return $this;
    }

    /**
     * Prepare data for batch insert
     */
    protected function prepareForInsert(array $data, string $modelClass): array
    {
        $model = new $modelClass;

        // Add timestamps if needed
        if ($model->timestamps) {
            $now = now();
            $data['created_at'] = $data['created_at'] ?? $now;
            $data['updated_at'] = $data['updated_at'] ?? $now;
        }

        // Filter to fillable attributes only
        $fillable = $model->getFillable();
        if (! empty($fillable)) {
            $data = array_intersect_key($data, array_flip($fillable));
        }

        return $data;
    }

    /**
     * Parse memory limit string
     */
    protected function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);

        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Format bytes
     */
    protected function formatBytes(int $bytes): string
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

class StreamResult
{
    protected int $total = 0;

    protected int $processed = 0;

    protected array $errors = [];

    protected array $warnings = [];

    protected float $executionTime = 0;

    protected int $peakMemory = 0;

    public function setTotal(int $total): void
    {
        $this->total = $total;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

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

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function setExecutionTime(float $time): void
    {
        $this->executionTime = $time;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function setPeakMemory(int $bytes): void
    {
        $this->peakMemory = $bytes;
    }

    public function getPeakMemory(): int
    {
        return $this->peakMemory;
    }

    public function isSuccess(): bool
    {
        return empty($this->errors);
    }

    public function toArray(): array
    {
        return [
            'success' => $this->isSuccess(),
            'total' => $this->total,
            'processed' => $this->processed,
            'errors' => count($this->errors),
            'warnings' => count($this->warnings),
            'execution_time' => round($this->executionTime, 2).'s',
            'peak_memory' => $this->formatBytes($this->peakMemory),
        ];
    }

    protected function formatBytes(int $bytes): string
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
