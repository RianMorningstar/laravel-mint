<?php

namespace LaravelMint\Integration;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use LaravelMint\Jobs\SendWebhookJob;

class WebhookManager
{
    protected array $webhooks = [];
    protected int $timeout = 30;
    protected int $retries = 3;
    protected int $retryDelay = 60;
    protected bool $verifySSL = true;
    protected ?string $secret = null;
    
    /**
     * Register webhook
     */
    public function register(string $event, string $url, array $options = []): void
    {
        if (!isset($this->webhooks[$event])) {
            $this->webhooks[$event] = [];
        }
        
        $this->webhooks[$event][] = [
            'url' => $url,
            'headers' => $options['headers'] ?? [],
            'method' => $options['method'] ?? 'POST',
            'retry' => $options['retry'] ?? true,
            'transform' => $options['transform'] ?? null,
        ];
    }
    
    /**
     * Trigger webhook for event
     */
    public function trigger(string $event, array $data): void
    {
        if (!isset($this->webhooks[$event])) {
            return;
        }
        
        foreach ($this->webhooks[$event] as $webhook) {
            $this->send($webhook, $event, $data);
        }
    }
    
    /**
     * Send webhook
     */
    protected function send(array $webhook, string $event, array $data): void
    {
        // Transform data if transformer provided
        if ($webhook['transform'] && is_callable($webhook['transform'])) {
            $data = call_user_func($webhook['transform'], $data);
        }
        
        // Prepare payload
        $payload = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ];
        
        // Add signature if secret is set
        $headers = $webhook['headers'];
        if ($this->secret) {
            $signature = $this->generateSignature($payload);
            $headers['X-Mint-Signature'] = $signature;
        }
        
        // Send immediately or queue
        if ($webhook['retry']) {
            $this->queueWebhook($webhook['url'], $webhook['method'], $payload, $headers);
        } else {
            $this->sendImmediate($webhook['url'], $webhook['method'], $payload, $headers);
        }
    }
    
    /**
     * Send webhook immediately
     */
    public function sendImmediate(string $url, string $method, array $payload, array $headers = []): bool
    {
        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->withOptions([
                    'verify' => $this->verifySSL,
                ])
                ->$method($url, $payload);
            
            if ($response->successful()) {
                Log::info('Webhook sent successfully', [
                    'url' => $url,
                    'event' => $payload['event'] ?? null,
                    'status' => $response->status(),
                ]);
                
                return true;
            }
            
            Log::warning('Webhook failed', [
                'url' => $url,
                'event' => $payload['event'] ?? null,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            
            return false;
        } catch (\Exception $e) {
            Log::error('Webhook exception', [
                'url' => $url,
                'event' => $payload['event'] ?? null,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    /**
     * Queue webhook for delivery
     */
    protected function queueWebhook(string $url, string $method, array $payload, array $headers): void
    {
        SendWebhookJob::dispatch($url, $method, $payload, $headers)
            ->onQueue('webhooks')
            ->delay(0);
    }
    
    /**
     * Generate HMAC signature
     */
    protected function generateSignature(array $payload): string
    {
        $json = json_encode($payload);
        return 'sha256=' . hash_hmac('sha256', $json, $this->secret);
    }
    
    /**
     * Verify webhook signature
     */
    public function verifySignature(string $signature, array $payload, string $secret = null): bool
    {
        $secret = $secret ?? $this->secret;
        
        if (!$secret) {
            return true; // No secret configured, skip verification
        }
        
        $expectedSignature = $this->generateSignature($payload);
        
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Register CI/CD webhooks
     */
    public function registerCICD(array $config): void
    {
        // GitHub Actions
        if (isset($config['github'])) {
            $this->register('generation_complete', $config['github']['webhook_url'], [
                'headers' => [
                    'X-GitHub-Event' => 'repository_dispatch',
                    'Authorization' => 'token ' . $config['github']['token'],
                ],
                'transform' => function ($data) use ($config) {
                    return [
                        'event_type' => 'mint_generation_complete',
                        'client_payload' => $data,
                    ];
                },
            ]);
        }
        
        // GitLab CI
        if (isset($config['gitlab'])) {
            $this->register('generation_complete', $config['gitlab']['webhook_url'], [
                'headers' => [
                    'PRIVATE-TOKEN' => $config['gitlab']['token'],
                ],
                'transform' => function ($data) {
                    return [
                        'ref' => $config['gitlab']['ref'] ?? 'main',
                        'variables' => [
                            'MINT_EVENT' => 'generation_complete',
                            'MINT_DATA' => json_encode($data),
                        ],
                    ];
                },
            ]);
        }
        
        // Jenkins
        if (isset($config['jenkins'])) {
            $this->register('generation_complete', $config['jenkins']['webhook_url'], [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($config['jenkins']['user'] . ':' . $config['jenkins']['token']),
                ],
                'transform' => function ($data) use ($config) {
                    return [
                        'parameter' => [
                            ['name' => 'MINT_EVENT', 'value' => 'generation_complete'],
                            ['name' => 'MINT_DATA', 'value' => json_encode($data)],
                        ],
                    ];
                },
            ]);
        }
        
        // CircleCI
        if (isset($config['circleci'])) {
            $this->register('generation_complete', $config['circleci']['webhook_url'], [
                'headers' => [
                    'Circle-Token' => $config['circleci']['token'],
                    'Content-Type' => 'application/json',
                ],
                'transform' => function ($data) use ($config) {
                    return [
                        'branch' => $config['circleci']['branch'] ?? 'main',
                        'parameters' => [
                            'mint_event' => 'generation_complete',
                            'mint_data' => $data,
                        ],
                    ];
                },
            ]);
        }
    }
    
    /**
     * Set webhook configuration
     */
    public function configure(array $config): void
    {
        if (isset($config['timeout'])) {
            $this->timeout = $config['timeout'];
        }
        
        if (isset($config['retries'])) {
            $this->retries = $config['retries'];
        }
        
        if (isset($config['retry_delay'])) {
            $this->retryDelay = $config['retry_delay'];
        }
        
        if (isset($config['verify_ssl'])) {
            $this->verifySSL = $config['verify_ssl'];
        }
        
        if (isset($config['secret'])) {
            $this->secret = $config['secret'];
        }
        
        if (isset($config['webhooks'])) {
            foreach ($config['webhooks'] as $event => $webhooks) {
                foreach ($webhooks as $webhook) {
                    $this->register($event, $webhook['url'], $webhook);
                }
            }
        }
        
        if (isset($config['cicd'])) {
            $this->registerCICD($config['cicd']);
        }
    }
    
    /**
     * Get registered webhooks
     */
    public function getWebhooks(string $event = null): array
    {
        if ($event) {
            return $this->webhooks[$event] ?? [];
        }
        
        return $this->webhooks;
    }
    
    /**
     * Clear webhooks
     */
    public function clear(string $event = null): void
    {
        if ($event) {
            unset($this->webhooks[$event]);
        } else {
            $this->webhooks = [];
        }
    }
    
    /**
     * Test webhook
     */
    public function test(string $url, array $testData = []): bool
    {
        $payload = [
            'event' => 'test',
            'timestamp' => now()->toIso8601String(),
            'data' => array_merge([
                'message' => 'This is a test webhook from Laravel Mint',
            ], $testData),
        ];
        
        return $this->sendImmediate($url, 'POST', $payload);
    }
}