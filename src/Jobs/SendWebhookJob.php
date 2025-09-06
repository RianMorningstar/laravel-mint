<?php

namespace LaravelMint\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected string $url;
    protected string $method;
    protected array $payload;
    protected array $headers;
    
    public $tries = 3;
    public $backoff = [60, 120, 300]; // Exponential backoff
    
    /**
     * Create a new job instance.
     */
    public function __construct(string $url, string $method, array $payload, array $headers = [])
    {
        $this->url = $url;
        $this->method = $method;
        $this->payload = $payload;
        $this->headers = $headers;
    }
    
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $response = Http::withHeaders($this->headers)
                ->timeout(30)
                ->$method($this->url, $this->payload);
            
            if (!$response->successful()) {
                Log::warning('Webhook response not successful', [
                    'url' => $this->url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                
                // Retry for 5xx errors
                if ($response->serverError()) {
                    throw new \Exception("Server error: {$response->status()}");
                }
            }
            
            Log::info('Webhook delivered successfully', [
                'url' => $this->url,
                'event' => $this->payload['event'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Webhook delivery failed', [
                'url' => $this->url,
                'event' => $this->payload['event'] ?? null,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);
            
            // Retry if attempts remaining
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }
    
    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Webhook job permanently failed', [
            'url' => $this->url,
            'event' => $this->payload['event'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
    
    /**
     * Get job tags
     */
    public function tags(): array
    {
        return ['webhook', 'event:' . ($this->payload['event'] ?? 'unknown')];
    }
}