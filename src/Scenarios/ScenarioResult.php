<?php

namespace LaravelMint\Scenarios;

class ScenarioResult
{
    protected string $scenario;

    protected array $generated = [];

    protected array $statistics = [];

    protected array $errors = [];

    protected float $executionTime = 0;

    protected int $memoryUsage = 0;

    protected bool $success = true;

    protected array $data = [];

    public function __construct($scenarioOrSuccess = '', ?array $data = null)
    {
        // Support both constructor signatures for backward compatibility
        if (is_bool($scenarioOrSuccess)) {
            $this->success = $scenarioOrSuccess;
            $this->data = $data ?? [];
            $this->scenario = 'unknown';
        } else {
            $this->scenario = $scenarioOrSuccess;
            $this->data = $data ?? [];
        }
    }

    public function addGenerated(string $model, int $count): void
    {
        $this->generated[$model] = ($this->generated[$model] ?? 0) + $count;
    }

    public function addStatistic(string $key, $value): void
    {
        $this->statistics[$key] = $value;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
        $this->success = false;
    }

    public function setExecutionTime(float $time): void
    {
        $this->executionTime = $time;
    }

    public function setMemoryUsage(int $bytes): void
    {
        $this->memoryUsage = $bytes;
    }

    public function isSuccess(): bool
    {
        return $this->success && empty($this->errors);
    }

    /**
     * Alias for isSuccess() for backward compatibility
     */
    public function isSuccessful(): bool
    {
        return $this->isSuccess();
    }

    public function getGenerated(): array
    {
        return $this->generated;
    }

    public function getStatistics(): array
    {
        return $this->statistics;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getMemoryUsage(): int
    {
        return $this->memoryUsage;
    }

    /**
     * Get data array for tests
     */
    public function getData(): array
    {
        // Start with provided data, then add computed values (but don't override)
        $result = $this->data;

        // Only add computed values if they're not already in data
        if (! isset($result['generated'])) {
            $result['generated'] = $this->generated;
        }
        if (! isset($result['statistics'])) {
            $result['statistics'] = $this->statistics;
        }
        if (! isset($result['errors'])) {
            $result['errors'] = $this->errors;
        }
        if (! isset($result['error']) && ! empty($this->errors)) {
            $result['error'] = $this->errors[0];
        }
        if (! isset($result['records'])) {
            $result['records'] = $this->data['records'] ?? $this->getTotalGenerated();
        }
        if (! isset($result['duration'])) {
            $result['duration'] = $this->data['duration'] ?? $this->executionTime;
        }
        if (! isset($result['memory'])) {
            $result['memory'] = $this->data['memory'] ?? $this->memoryUsage;
        }
        if (! isset($result['records_created'])) {
            $result['records_created'] = $this->data['records_created'] ?? $this->getTotalGenerated();
        }

        return $result;
    }

    public function getTotalGenerated(): int
    {
        return array_sum($this->generated);
    }

    public function getSummary(): array
    {
        return [
            'scenario' => $this->scenario,
            'success' => $this->success,
            'generated' => $this->generated,
            'total_records' => $this->getTotalGenerated(),
            'execution_time' => round($this->executionTime, 2).'s',
            'memory_usage' => $this->formatBytes($this->memoryUsage),
            'statistics' => $this->statistics,
            'errors' => $this->errors,
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

    public function toArray(): array
    {
        return $this->getSummary();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
