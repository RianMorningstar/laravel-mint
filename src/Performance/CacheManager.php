<?php

namespace LaravelMint\Performance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CacheManager
{
    protected string $prefix = 'mint:';
    protected int $defaultTtl = 3600;
    protected array $tags = ['mint'];
    protected bool $enabled = true;
    protected array $statistics = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'deletes' => 0,
    ];
    
    /**
     * Get or compute cached value
     */
    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        if (!$this->enabled) {
            return $callback();
        }
        
        $key = $this->prefix . $key;
        $ttl = $ttl ?? $this->defaultTtl;
        
        if ($this->supportsTags()) {
            return Cache::tags($this->tags)->remember($key, $ttl, function () use ($callback) {
                $this->statistics['misses']++;
                $this->statistics['writes']++;
                return $callback();
            });
        }
        
        // Check if exists for statistics
        if (Cache::has($key)) {
            $this->statistics['hits']++;
            return Cache::get($key);
        }
        
        $this->statistics['misses']++;
        $this->statistics['writes']++;
        
        return Cache::remember($key, $ttl, $callback);
    }
    
    /**
     * Cache model analysis results
     */
    public function cacheModelAnalysis(string $modelClass, array $analysis): void
    {
        $key = "model_analysis:" . md5($modelClass);
        $this->put($key, $analysis, 86400); // Cache for 24 hours
    }
    
    /**
     * Get cached model analysis
     */
    public function getModelAnalysis(string $modelClass): ?array
    {
        $key = "model_analysis:" . md5($modelClass);
        return $this->get($key);
    }
    
    /**
     * Cache pattern results
     */
    public function cachePattern(string $patternKey, $value, array $config): void
    {
        $key = "pattern:" . md5($patternKey . serialize($config));
        $this->put($key, $value, 300); // Cache for 5 minutes
    }
    
    /**
     * Get cached pattern
     */
    public function getPattern(string $patternKey, array $config)
    {
        $key = "pattern:" . md5($patternKey . serialize($config));
        return $this->get($key);
    }
    
    /**
     * Cache query results
     */
    public function cacheQuery(string $sql, array $bindings, $results): void
    {
        $key = "query:" . md5($sql . serialize($bindings));
        $this->put($key, $results, 600); // Cache for 10 minutes
    }
    
    /**
     * Get cached query results
     */
    public function getQuery(string $sql, array $bindings)
    {
        $key = "query:" . md5($sql . serialize($bindings));
        return $this->get($key);
    }
    
    /**
     * Cache generation batch
     */
    public function cacheBatch(string $batchId, array $data): void
    {
        $key = "batch:{$batchId}";
        $this->put($key, $data, 1800); // Cache for 30 minutes
    }
    
    /**
     * Get cached batch
     */
    public function getBatch(string $batchId): ?array
    {
        $key = "batch:{$batchId}";
        return $this->get($key);
    }
    
    /**
     * Store value in cache
     */
    public function put(string $key, $value, ?int $ttl = null): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        $key = $this->prefix . $key;
        $ttl = $ttl ?? $this->defaultTtl;
        
        $this->statistics['writes']++;
        
        if ($this->supportsTags()) {
            Cache::tags($this->tags)->put($key, $value, $ttl);
        } else {
            Cache::put($key, $value, $ttl);
        }
        
        return true;
    }
    
    /**
     * Get value from cache
     */
    public function get(string $key, $default = null)
    {
        if (!$this->enabled) {
            return $default;
        }
        
        $key = $this->prefix . $key;
        
        if ($this->supportsTags()) {
            $value = Cache::tags($this->tags)->get($key, $default);
        } else {
            $value = Cache::get($key, $default);
        }
        
        if ($value !== $default) {
            $this->statistics['hits']++;
        } else {
            $this->statistics['misses']++;
        }
        
        return $value;
    }
    
    /**
     * Check if key exists
     */
    public function has(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        $key = $this->prefix . $key;
        
        if ($this->supportsTags()) {
            return Cache::tags($this->tags)->has($key);
        }
        
        return Cache::has($key);
    }
    
    /**
     * Delete from cache
     */
    public function forget(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }
        
        $key = $this->prefix . $key;
        
        $this->statistics['deletes']++;
        
        if ($this->supportsTags()) {
            return Cache::tags($this->tags)->forget($key);
        }
        
        return Cache::forget($key);
    }
    
    /**
     * Clear all Mint cache
     */
    public function flush(): bool
    {
        if ($this->supportsTags()) {
            Cache::tags($this->tags)->flush();
            return true;
        }
        
        // Without tags, we need to be more careful
        // Only clear keys with our prefix (if using Redis)
        if ($this->isRedisDriver()) {
            $keys = Redis::keys($this->prefix . '*');
            foreach ($keys as $key) {
                Redis::del($key);
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Warm up cache
     */
    public function warmUp(array $models): void
    {
        foreach ($models as $modelClass) {
            // Pre-cache model analysis
            if (class_exists($modelClass)) {
                $analyzer = new \LaravelMint\Analyzers\ModelAnalyzer($modelClass);
                $analysis = $analyzer->analyze();
                $this->cacheModelAnalysis($modelClass, $analysis);
            }
        }
    }
    
    /**
     * Get cache statistics
     */
    public function getStatistics(): array
    {
        $total = $this->statistics['hits'] + $this->statistics['misses'];
        $hitRate = $total > 0 ? ($this->statistics['hits'] / $total) * 100 : 0;
        
        return [
            'hits' => $this->statistics['hits'],
            'misses' => $this->statistics['misses'],
            'writes' => $this->statistics['writes'],
            'deletes' => $this->statistics['deletes'],
            'hit_rate' => round($hitRate, 2) . '%',
            'enabled' => $this->enabled,
        ];
    }
    
    /**
     * Reset statistics
     */
    public function resetStatistics(): void
    {
        $this->statistics = [
            'hits' => 0,
            'misses' => 0,
            'writes' => 0,
            'deletes' => 0,
        ];
    }
    
    /**
     * Enable/disable caching
     */
    public function enable(bool $enabled = true): void
    {
        $this->enabled = $enabled;
    }
    
    /**
     * Set cache prefix
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }
    
    /**
     * Set default TTL
     */
    public function setDefaultTtl(int $seconds): void
    {
        $this->defaultTtl = $seconds;
    }
    
    /**
     * Set cache tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }
    
    /**
     * Check if cache driver supports tags
     */
    protected function supportsTags(): bool
    {
        $driver = config('cache.default');
        return in_array($driver, ['redis', 'memcached']);
    }
    
    /**
     * Check if using Redis driver
     */
    protected function isRedisDriver(): bool
    {
        return config('cache.default') === 'redis';
    }
    
    /**
     * Create cache key from parts
     */
    public function makeKey(...$parts): string
    {
        return implode(':', array_map(function ($part) {
            if (is_array($part)) {
                return md5(serialize($part));
            }
            return (string)$part;
        }, $parts));
    }
    
    /**
     * Memoize function results
     */
    public function memoize(callable $callback, string $key = null, ?int $ttl = null)
    {
        if (!$key) {
            $key = 'memoize:' . md5(serialize($callback));
        }
        
        return $this->remember($key, $callback, $ttl);
    }
    
    /**
     * Cache with lock to prevent stampede
     */
    public function lock(string $key, callable $callback, int $lockTimeout = 10)
    {
        $lockKey = $key . ':lock';
        $lock = Cache::lock($lockKey, $lockTimeout);
        
        if ($lock->get()) {
            try {
                $result = $this->remember($key, $callback);
                return $result;
            } finally {
                $lock->release();
            }
        }
        
        // If can't get lock, wait and try to get from cache
        sleep(1);
        return $this->get($key) ?? $callback();
    }
}