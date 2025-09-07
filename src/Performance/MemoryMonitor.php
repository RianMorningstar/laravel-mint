<?php

namespace LaravelMint\Performance;

class MemoryMonitor
{
    protected array $checkpoints = [];

    protected array $peaks = [];

    protected int $limit;

    protected int $threshold;

    protected bool $autoGc = true;

    protected array $callbacks = [];

    protected array $history = [];

    protected int $historyLimit = 100;

    public function __construct()
    {
        $this->limit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $this->threshold = (int) ($this->limit * 0.8); // 80% threshold
    }

    /**
     * Start monitoring
     */
    public function start(string $label = 'default'): void
    {
        $this->checkpoints[$label] = [
            'start_memory' => memory_get_usage(true),
            'start_real' => memory_get_usage(false),
            'start_time' => microtime(true),
        ];
    }

    /**
     * Stop monitoring and get metrics
     */
    public function stop(string $label = 'default'): MemoryMetrics
    {
        if (! isset($this->checkpoints[$label])) {
            throw new \RuntimeException("No checkpoint found for label: {$label}");
        }

        $checkpoint = $this->checkpoints[$label];
        $currentMemory = memory_get_usage(true);
        $currentReal = memory_get_usage(false);
        $peakMemory = memory_get_peak_usage(true);
        $peakReal = memory_get_peak_usage(false);

        $metrics = new MemoryMetrics([
            'label' => $label,
            'memory_used' => $currentMemory - $checkpoint['start_memory'],
            'real_used' => $currentReal - $checkpoint['start_real'],
            'peak_memory' => $peakMemory,
            'peak_real' => $peakReal,
            'execution_time' => microtime(true) - $checkpoint['start_time'],
            'current_usage' => $currentMemory,
            'percentage' => ($currentMemory / $this->limit) * 100,
        ]);

        // Store in history
        $this->addToHistory($metrics);

        // Store peak if it's the highest
        if (! isset($this->peaks[$label]) || $peakMemory > $this->peaks[$label]) {
            $this->peaks[$label] = $peakMemory;
        }

        unset($this->checkpoints[$label]);

        return $metrics;
    }

    /**
     * Monitor a callable
     */
    public function monitor(callable $callback, string $label = 'operation'): array
    {
        $this->start($label);

        try {
            $result = $callback();
            $metrics = $this->stop($label);

            return [
                'result' => $result,
                'metrics' => $metrics,
            ];
        } catch (\Exception $e) {
            $this->stop($label);
            throw $e;
        }
    }

    /**
     * Check current memory usage
     */
    public function check(): MemoryStatus
    {
        $current = memory_get_usage(true);
        $real = memory_get_usage(false);
        $peak = memory_get_peak_usage(true);

        $status = new MemoryStatus([
            'current' => $current,
            'real' => $real,
            'peak' => $peak,
            'limit' => $this->limit,
            'threshold' => $this->threshold,
            'percentage' => ($current / $this->limit) * 100,
            'available' => $this->limit - $current,
            'is_critical' => $current > $this->threshold,
        ]);

        // Check if we need to trigger callbacks
        if ($status->isCritical()) {
            $this->triggerCallbacks('critical', $status);

            if ($this->autoGc) {
                $this->forceGarbageCollection();
            }
        }

        return $status;
    }

    /**
     * Watch memory usage continuously
     */
    public function watch(callable $operation, int $intervalMs = 100): WatchResult
    {
        $result = new WatchResult;
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Start monitoring in background
        $monitoring = true;
        $samples = [];

        // Note: In real implementation, this would use async/parallel processing
        // For now, we'll take samples before and after
        $beforeStatus = $this->check();
        $samples[] = $beforeStatus->toArray();

        try {
            $operationResult = $operation();
            $result->setResult($operationResult);
        } catch (\Exception $e) {
            $result->setError($e->getMessage());
        }

        $afterStatus = $this->check();
        $samples[] = $afterStatus->toArray();

        $result->setSamples($samples);
        $result->setExecutionTime(microtime(true) - $startTime);
        $result->setMemoryUsed(memory_get_usage(true) - $startMemory);
        $result->setPeakMemory(memory_get_peak_usage(true));

        return $result;
    }

    /**
     * Force garbage collection
     */
    public function forceGarbageCollection(): int
    {
        $before = memory_get_usage(true);
        gc_collect_cycles();
        $after = memory_get_usage(true);

        $freed = $before - $after;

        if (isset($this->callbacks['gc'])) {
            call_user_func($this->callbacks['gc'], $freed);
        }

        return $freed;
    }

    /**
     * Get memory breakdown
     */
    public function getBreakdown(): array
    {
        $breakdown = [];

        // Get Opcache memory if available
        if (function_exists('opcache_get_status')) {
            $opcache = opcache_get_status(false);
            if ($opcache) {
                $breakdown['opcache'] = [
                    'used' => $opcache['memory_usage']['used_memory'] ?? 0,
                    'free' => $opcache['memory_usage']['free_memory'] ?? 0,
                ];
            }
        }

        // Get realpath cache
        $breakdown['realpath_cache'] = [
            'used' => realpath_cache_size(),
            'limit' => ini_get('realpath_cache_size'),
        ];

        // Get script memory
        $breakdown['script'] = [
            'current' => memory_get_usage(false),
            'current_real' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(false),
            'peak_real' => memory_get_peak_usage(true),
        ];

        return $breakdown;
    }

    /**
     * Register callback
     */
    public function onThreshold(callable $callback): self
    {
        $this->callbacks['critical'] = $callback;

        return $this;
    }

    /**
     * Register garbage collection callback
     */
    public function onGarbageCollection(callable $callback): self
    {
        $this->callbacks['gc'] = $callback;

        return $this;
    }

    /**
     * Set auto garbage collection
     */
    public function setAutoGc(bool $enabled): self
    {
        $this->autoGc = $enabled;

        return $this;
    }

    /**
     * Set memory threshold
     */
    public function setThreshold(int $bytes): self
    {
        $this->threshold = $bytes;

        return $this;
    }

    /**
     * Get memory history
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Clear history
     */
    public function clearHistory(): void
    {
        $this->history = [];
    }

    /**
     * Add to history
     */
    protected function addToHistory(MemoryMetrics $metrics): void
    {
        $this->history[] = $metrics->toArray();

        // Limit history size
        if (count($this->history) > $this->historyLimit) {
            array_shift($this->history);
        }
    }

    /**
     * Trigger callbacks
     */
    protected function triggerCallbacks(string $type, $data): void
    {
        if (isset($this->callbacks[$type])) {
            call_user_func($this->callbacks[$type], $data);
        }
    }

    /**
     * Parse memory limit
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
    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'current' => $this->formatBytes(memory_get_usage(true)),
            'peak' => $this->formatBytes(memory_get_peak_usage(true)),
            'limit' => $this->formatBytes($this->limit),
            'threshold' => $this->formatBytes($this->threshold),
            'available' => $this->formatBytes($this->limit - memory_get_usage(true)),
            'usage_percentage' => round((memory_get_usage(true) / $this->limit) * 100, 2).'%',
        ];

        if (! empty($this->peaks)) {
            $stats['recorded_peaks'] = array_map([$this, 'formatBytes'], $this->peaks);
        }

        return $stats;
    }
}

class MemoryMetrics
{
    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getMemoryUsed(): int
    {
        return $this->data['memory_used'] ?? 0;
    }

    public function getPeakMemory(): int
    {
        return $this->data['peak_memory'] ?? 0;
    }

    public function getExecutionTime(): float
    {
        return $this->data['execution_time'] ?? 0;
    }

    public function getPercentage(): float
    {
        return $this->data['percentage'] ?? 0;
    }

    public function toArray(): array
    {
        return array_merge($this->data, [
            'memory_used_formatted' => $this->formatBytes($this->data['memory_used'] ?? 0),
            'peak_memory_formatted' => $this->formatBytes($this->data['peak_memory'] ?? 0),
            'execution_time_formatted' => round($this->data['execution_time'] ?? 0, 4).'s',
        ]);
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

class MemoryStatus
{
    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getCurrent(): int
    {
        return $this->data['current'] ?? 0;
    }

    public function getAvailable(): int
    {
        return $this->data['available'] ?? 0;
    }

    public function getPercentage(): float
    {
        return $this->data['percentage'] ?? 0;
    }

    public function isCritical(): bool
    {
        return $this->data['is_critical'] ?? false;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}

class WatchResult
{
    protected $result;

    protected ?string $error = null;

    protected array $samples = [];

    protected float $executionTime = 0;

    protected int $memoryUsed = 0;

    protected int $peakMemory = 0;

    public function setResult($result): void
    {
        $this->result = $result;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function setError(string $error): void
    {
        $this->error = $error;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setSamples(array $samples): void
    {
        $this->samples = $samples;
    }

    public function getSamples(): array
    {
        return $this->samples;
    }

    public function setExecutionTime(float $time): void
    {
        $this->executionTime = $time;
    }

    public function setMemoryUsed(int $bytes): void
    {
        $this->memoryUsed = $bytes;
    }

    public function setPeakMemory(int $bytes): void
    {
        $this->peakMemory = $bytes;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->error === null,
            'execution_time' => round($this->executionTime, 4).'s',
            'memory_used' => $this->formatBytes($this->memoryUsed),
            'peak_memory' => $this->formatBytes($this->peakMemory),
            'samples' => count($this->samples),
            'error' => $this->error,
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
