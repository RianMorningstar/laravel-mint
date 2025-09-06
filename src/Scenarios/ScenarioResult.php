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

    public function __construct(string $scenario)
    {
        $this->scenario = $scenario;
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
            'execution_time' => round($this->executionTime, 2) . 's',
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
        
        return round($bytes, 2) . ' ' . $units[$i];
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