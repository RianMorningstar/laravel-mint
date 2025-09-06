<?php

namespace LaravelMint\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaravelMint\Mint;
use LaravelMint\Integration\WebhookManager;

class GenerateDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected string $modelClass;
    protected int $count;
    protected array $options;
    protected ?string $webhookUrl;
    protected string $jobId;
    
    /**
     * Create a new job instance.
     */
    public function __construct(string $modelClass, int $count, array $options = [], ?string $webhookUrl = null)
    {
        $this->modelClass = $modelClass;
        $this->count = $count;
        $this->options = $options;
        $this->webhookUrl = $webhookUrl;
        $this->jobId = uniqid('mint_job_');
    }
    
    /**
     * Execute the job.
     */
    public function handle(Mint $mint, WebhookManager $webhookManager): void
    {
        // Update job status
        $this->updateStatus('processing');
        
        try {
            $startTime = microtime(true);
            
            // Generate data
            $result = $mint->generate($this->modelClass, $this->count, $this->options);
            
            $executionTime = microtime(true) - $startTime;
            
            // Update job status
            $this->updateStatus('completed', [
                'result' => $result,
                'execution_time' => $executionTime,
            ]);
            
            // Send webhook if configured
            if ($this->webhookUrl) {
                $webhookManager->sendImmediate($this->webhookUrl, 'POST', [
                    'event' => 'generation_complete',
                    'job_id' => $this->jobId,
                    'model' => $this->modelClass,
                    'count' => $this->count,
                    'execution_time' => round($executionTime, 2) . 's',
                    'timestamp' => now()->toIso8601String(),
                ]);
            }
            
            // Trigger general webhook
            $webhookManager->trigger('generation_complete', [
                'job_id' => $this->jobId,
                'model' => $this->modelClass,
                'count' => $this->count,
                'execution_time' => round($executionTime, 2) . 's',
            ]);
        } catch (\Exception $e) {
            $this->updateStatus('failed', [
                'error' => $e->getMessage(),
            ]);
            
            // Send failure webhook
            if ($this->webhookUrl) {
                $webhookManager->sendImmediate($this->webhookUrl, 'POST', [
                    'event' => 'generation_failed',
                    'job_id' => $this->jobId,
                    'model' => $this->modelClass,
                    'count' => $this->count,
                    'error' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                ]);
            }
            
            throw $e;
        }
    }
    
    /**
     * Update job status in cache
     */
    protected function updateStatus(string $status, array $data = []): void
    {
        cache()->put("mint_job_{$this->jobId}", array_merge([
            'status' => $status,
            'model' => $this->modelClass,
            'count' => $this->count,
            'updated_at' => now(),
        ], $data), 3600);
    }
    
    /**
     * Get job tags
     */
    public function tags(): array
    {
        return ['mint', 'model:' . class_basename($this->modelClass)];
    }
}