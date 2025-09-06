<?php

namespace LaravelMint\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Queue;
use LaravelMint\Mint;
use LaravelMint\Import\ImportManager;
use LaravelMint\Export\ExportManager;
use LaravelMint\Jobs\GenerateDataJob;
use LaravelMint\Http\Middleware\VerifyApiKey;

class MintApiController extends Controller
{
    protected Mint $mint;
    
    public function __construct(Mint $mint)
    {
        $this->mint = $mint;
        $this->middleware(VerifyApiKey::class);
        $this->middleware('throttle:60,1');
    }
    
    /**
     * Generate data via API
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model' => 'required|string',
            'count' => 'required|integer|min:1|max:10000',
            'options' => 'array',
            'async' => 'boolean',
            'webhook_url' => 'url',
        ]);
        
        // Check if model exists
        if (!class_exists($validated['model'])) {
            return response()->json([
                'error' => 'Model not found',
                'model' => $validated['model'],
            ], 404);
        }
        
        // Async generation for large datasets
        if ($request->get('async', false) || $validated['count'] > 1000) {
            return $this->generateAsync($validated);
        }
        
        // Synchronous generation
        try {
            $startTime = microtime(true);
            
            $result = $this->mint->generate(
                $validated['model'],
                $validated['count'],
                $validated['options'] ?? []
            );
            
            return response()->json([
                'success' => true,
                'model' => $validated['model'],
                'count' => $validated['count'],
                'execution_time' => round(microtime(true) - $startTime, 2) . 's',
                'data' => $request->get('include_data', false) ? $result : null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Generation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Generate data asynchronously
     */
    protected function generateAsync(array $validated): JsonResponse
    {
        $jobId = uniqid('mint_job_');
        
        // Dispatch job
        GenerateDataJob::dispatch(
            $validated['model'],
            $validated['count'],
            $validated['options'] ?? [],
            $validated['webhook_url'] ?? null
        )->onQueue('mint');
        
        // Store job metadata
        cache()->put("mint_job_{$jobId}", [
            'status' => 'queued',
            'model' => $validated['model'],
            'count' => $validated['count'],
            'created_at' => now(),
        ], 3600);
        
        return response()->json([
            'success' => true,
            'job_id' => $jobId,
            'status' => 'queued',
            'check_url' => route('mint.api.status', ['jobId' => $jobId]),
        ], 202);
    }
    
    /**
     * Check job status
     */
    public function status(string $jobId): JsonResponse
    {
        $job = cache()->get("mint_job_{$jobId}");
        
        if (!$job) {
            return response()->json([
                'error' => 'Job not found',
                'job_id' => $jobId,
            ], 404);
        }
        
        return response()->json($job);
    }
    
    /**
     * Import data via API
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:51200', // 50MB max
            'format' => 'string|in:csv,json,excel,sql',
            'mappings' => 'array',
            'rules' => 'array',
        ]);
        
        try {
            $manager = new ImportManager();
            
            // Configure mappings
            if (isset($validated['mappings'])) {
                foreach ($validated['mappings'] as $model => $mapping) {
                    $manager->mapping($model, $mapping);
                }
            }
            
            // Configure validation rules
            if (isset($validated['rules'])) {
                $manager->rules($validated['rules']);
            }
            
            // Store uploaded file
            $path = $request->file('file')->store('imports');
            $fullPath = storage_path('app/' . $path);
            
            // Import
            $result = $manager->import($fullPath, $validated['format'] ?? null);
            
            // Clean up
            unlink($fullPath);
            
            return response()->json($result->toArray());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Import failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Export data via API
     */
    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'models' => 'required|array',
            'models.*' => 'string',
            'format' => 'required|string|in:csv,json,excel,sql',
            'compress' => 'boolean',
            'fields' => 'array',
            'conditions' => 'array',
        ]);
        
        try {
            $manager = new ExportManager();
            
            // Configure models
            foreach ($validated['models'] as $model) {
                if (!class_exists($model)) {
                    return response()->json([
                        'error' => 'Model not found',
                        'model' => $model,
                    ], 404);
                }
                
                $fields = $validated['fields'][$model] ?? null;
                $manager->model($model, $fields);
                
                // Add conditions
                if (isset($validated['conditions'][$model])) {
                    foreach ($validated['conditions'][$model] as $condition) {
                        $manager->where($model, ...$condition);
                    }
                }
            }
            
            // Configure compression
            if ($request->get('compress', false)) {
                $manager->compress();
            }
            
            // Export
            $result = $manager->export($validated['format']);
            
            if ($result->isSuccess()) {
                $url = asset('storage/exports/' . basename($result->getOutputPath()));
                
                return response()->json([
                    'success' => true,
                    'download_url' => $url,
                    'details' => $result->toArray(),
                ]);
            }
            
            return response()->json([
                'error' => 'Export failed',
                'details' => $result->toArray(),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Export failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * List available models
     */
    public function models(): JsonResponse
    {
        $models = [];
        $modelsPath = app_path('Models');
        
        if (is_dir($modelsPath)) {
            $files = glob($modelsPath . '/*.php');
            
            foreach ($files as $file) {
                $className = 'App\\Models\\' . basename($file, '.php');
                
                if (class_exists($className)) {
                    $model = new $className();
                    
                    $models[] = [
                        'class' => $className,
                        'name' => class_basename($className),
                        'table' => $model->getTable(),
                        'fillable' => $model->getFillable(),
                        'guarded' => $model->getGuarded(),
                    ];
                }
            }
        }
        
        return response()->json([
            'models' => $models,
            'count' => count($models),
        ]);
    }
    
    /**
     * Get available patterns
     */
    public function patterns(): JsonResponse
    {
        $patterns = config('mint.patterns', []);
        
        return response()->json([
            'patterns' => $patterns,
            'distributions' => [
                'normal' => ['mean', 'stddev'],
                'pareto' => ['xmin', 'alpha'],
                'poisson' => ['lambda'],
                'exponential' => ['lambda'],
            ],
            'temporal' => [
                'linear' => ['start', 'end'],
                'seasonal' => ['peaks', 'amplitude'],
                'business_hours' => ['start_hour', 'end_hour', 'weekends'],
            ],
        ]);
    }
    
    /**
     * Get scenarios
     */
    public function scenarios(): JsonResponse
    {
        $runner = app(\LaravelMint\Scenarios\ScenarioRunner::class);
        
        // Register built-in scenarios
        $runner->registerMany([
            'ecommerce' => \LaravelMint\Scenarios\Presets\EcommerceScenario::class,
            'saas' => \LaravelMint\Scenarios\Presets\SaaSScenario::class,
        ]);
        
        $scenarios = $runner->list();
        
        return response()->json([
            'scenarios' => $scenarios,
            'count' => count($scenarios),
        ]);
    }
    
    /**
     * Run scenario
     */
    public function runScenario(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scenario' => 'required|string',
            'options' => 'array',
            'dry_run' => 'boolean',
        ]);
        
        try {
            $runner = app(\LaravelMint\Scenarios\ScenarioRunner::class);
            
            // Register scenarios
            $runner->registerMany([
                'ecommerce' => \LaravelMint\Scenarios\Presets\EcommerceScenario::class,
                'saas' => \LaravelMint\Scenarios\Presets\SaaSScenario::class,
            ]);
            
            // Configure runner
            if ($request->get('dry_run', false)) {
                $runner->dryRun(true);
            }
            
            // Run scenario
            $result = $runner->run(
                $validated['scenario'],
                $validated['options'] ?? []
            );
            
            return response()->json([
                'success' => $result->isSuccess(),
                'scenario' => $validated['scenario'],
                'summary' => $result->getSummary(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Scenario failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Get statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = [];
        
        // Get model counts
        $models = $this->models()->getData()->models;
        
        foreach ($models as $model) {
            $modelClass = $model->class;
            
            try {
                $stats['models'][$model->name] = [
                    'count' => $modelClass::count(),
                    'latest' => $modelClass::latest()->first()?->created_at,
                ];
            } catch (\Exception $e) {
                $stats['models'][$model->name] = [
                    'count' => 0,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        // Get cache statistics
        $cacheManager = new \LaravelMint\Performance\CacheManager();
        $stats['cache'] = $cacheManager->getStatistics();
        
        // Get memory statistics
        $memoryMonitor = new \LaravelMint\Performance\MemoryMonitor();
        $stats['memory'] = $memoryMonitor->getStatistics();
        
        return response()->json($stats);
    }
    
    /**
     * Health check
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}