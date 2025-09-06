<?php

namespace LaravelMint\Analyzers;

use LaravelMint\Mint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use ReflectionMethod;

class ModelAnalyzer
{
    protected Mint $mint;
    protected array $cache = [];

    public function __construct(Mint $mint)
    {
        $this->mint = $mint;
    }

    public function analyze(string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class {$modelClass} does not exist");
        }

        if (!is_subclass_of($modelClass, Model::class)) {
            throw new \InvalidArgumentException("{$modelClass} is not an Eloquent model");
        }

        $cacheKey = "mint.analysis.{$modelClass}";
        $cacheDuration = $this->mint->getConfig('analysis.cache_duration', 3600);

        if ($this->mint->getConfig('analysis.cache_results') && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $analysis = $this->performAnalysis($modelClass);

        if ($this->mint->getConfig('analysis.cache_results')) {
            Cache::put($cacheKey, $analysis, $cacheDuration);
        }

        return $analysis;
    }

    protected function performAnalysis(string $modelClass): array
    {
        $reflection = new ReflectionClass($modelClass);
        $instance = new $modelClass;

        return [
            'class' => $modelClass,
            'table' => $instance->getTable(),
            'primary_key' => $instance->getKeyName(),
            'key_type' => $instance->getKeyType(),
            'incrementing' => $instance->getIncrementing(),
            'timestamps' => $instance->usesTimestamps(),
            'fillable' => $this->getFillable($instance),
            'guarded' => $this->getGuarded($instance),
            'hidden' => $instance->getHidden(),
            'visible' => $instance->getVisible(),
            'casts' => $instance->getCasts(),
            'attributes' => $instance->getAttributes(),
            'appends' => $this->getAppends($instance),
            'dates' => $this->getDates($instance),
            'relations' => $this->analyzeRelations($reflection, $instance),
            'scopes' => $this->analyzeScopes($reflection),
            'mutators' => $this->analyzeMutators($reflection),
            'accessors' => $this->analyzeAccessors($reflection),
            'validation_rules' => $this->extractValidationRules($modelClass),
            'traits' => $this->analyzeTraits($reflection),
        ];
    }

    protected function getFillable(Model $instance): array
    {
        return $instance->getFillable();
    }

    protected function getGuarded(Model $instance): array
    {
        return $instance->getGuarded();
    }

    protected function getAppends(Model $instance): array
    {
        // Appends might be protected, so we use reflection
        $reflection = new ReflectionClass($instance);
        if ($reflection->hasProperty('appends')) {
            $property = $reflection->getProperty('appends');
            $property->setAccessible(true);
            return $property->getValue($instance) ?? [];
        }
        return [];
    }

    protected function getDates(Model $instance): array
    {
        // In Laravel 9+, dates are part of casts
        $casts = $instance->getCasts();
        $dates = [];
        
        foreach ($casts as $key => $type) {
            if (in_array($type, ['date', 'datetime', 'custom_datetime', 'immutable_date', 'immutable_datetime'])) {
                $dates[] = $key;
            }
        }
        
        if ($instance->usesTimestamps()) {
            $dates[] = $instance->getCreatedAtColumn();
            $dates[] = $instance->getUpdatedAtColumn();
        }
        
        return array_unique($dates);
    }

    protected function analyzeRelations(ReflectionClass $reflection, Model $instance): array
    {
        $relations = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            // Skip non-relation methods
            if ($method->class !== $reflection->getName() || 
                $method->getNumberOfParameters() > 0 ||
                $method->isStatic() ||
                $method->isAbstract() ||
                in_array($method->getName(), $this->getIgnoredMethods())) {
                continue;
            }

            try {
                $returnType = $method->getReturnType();
                
                // Check if method returns a relationship
                if ($returnType && !$returnType->isBuiltin()) {
                    $typeName = $returnType->getName();
                    if ($this->isRelationClass($typeName)) {
                        $relations[$method->getName()] = [
                            'type' => $this->getRelationType($typeName),
                            'class' => $typeName,
                            'method' => $method->getName(),
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Skip methods that throw exceptions
                continue;
            }
        }

        return $relations;
    }

    protected function analyzeScopes(ReflectionClass $reflection): array
    {
        if (!$this->mint->getConfig('analysis.detect_scopes')) {
            return [];
        }

        $scopes = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $name = $method->getName();
            if (str_starts_with($name, 'scope') && strlen($name) > 5) {
                $scopeName = lcfirst(substr($name, 5));
                $scopes[$scopeName] = [
                    'method' => $name,
                    'parameters' => $method->getNumberOfParameters() - 1, // Exclude $query parameter
                ];
            }
        }

        return $scopes;
    }

    protected function analyzeMutators(ReflectionClass $reflection): array
    {
        $mutators = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $name = $method->getName();
            
            // Laravel 9+ attribute mutators
            if (preg_match('/^set([A-Z][a-zA-Z]*)Attribute$/', $name, $matches)) {
                $attribute = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $matches[1]));
                $mutators[$attribute] = $name;
            }
        }

        return $mutators;
    }

    protected function analyzeAccessors(ReflectionClass $reflection): array
    {
        $accessors = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $name = $method->getName();
            
            // Laravel 9+ attribute accessors
            if (preg_match('/^get([A-Z][a-zA-Z]*)Attribute$/', $name, $matches)) {
                $attribute = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $matches[1]));
                $accessors[$attribute] = $name;
            }
        }

        return $accessors;
    }

    protected function extractValidationRules(string $modelClass): array
    {
        if (!$this->mint->getConfig('analysis.detect_validations')) {
            return [];
        }

        // Check if model has a rules method or property
        $reflection = new ReflectionClass($modelClass);
        
        if ($reflection->hasProperty('rules')) {
            $property = $reflection->getProperty('rules');
            $property->setAccessible(true);
            $instance = new $modelClass;
            return $property->getValue($instance) ?? [];
        }

        if ($reflection->hasMethod('rules')) {
            $instance = new $modelClass;
            return $instance->rules();
        }

        return [];
    }

    protected function analyzeTraits(ReflectionClass $reflection): array
    {
        $traits = [];
        $classTraits = $reflection->getTraitNames();
        
        foreach ($classTraits as $trait) {
            $traits[] = [
                'name' => $trait,
                'short_name' => class_basename($trait),
            ];
        }
        
        return $traits;
    }

    protected function isRelationClass(string $className): bool
    {
        $relationClasses = [
            'Illuminate\Database\Eloquent\Relations\HasOne',
            'Illuminate\Database\Eloquent\Relations\HasMany',
            'Illuminate\Database\Eloquent\Relations\BelongsTo',
            'Illuminate\Database\Eloquent\Relations\BelongsToMany',
            'Illuminate\Database\Eloquent\Relations\HasOneThrough',
            'Illuminate\Database\Eloquent\Relations\HasManyThrough',
            'Illuminate\Database\Eloquent\Relations\MorphTo',
            'Illuminate\Database\Eloquent\Relations\MorphOne',
            'Illuminate\Database\Eloquent\Relations\MorphMany',
            'Illuminate\Database\Eloquent\Relations\MorphToMany',
            'Illuminate\Database\Eloquent\Relations\MorphedByMany',
        ];

        foreach ($relationClasses as $relationClass) {
            if (is_a($className, $relationClass, true)) {
                return true;
            }
        }

        return false;
    }

    protected function getRelationType(string $className): string
    {
        $map = [
            'HasOne' => 'hasOne',
            'HasMany' => 'hasMany',
            'BelongsTo' => 'belongsTo',
            'BelongsToMany' => 'belongsToMany',
            'HasOneThrough' => 'hasOneThrough',
            'HasManyThrough' => 'hasManyThrough',
            'MorphTo' => 'morphTo',
            'MorphOne' => 'morphOne',
            'MorphMany' => 'morphMany',
            'MorphToMany' => 'morphToMany',
            'MorphedByMany' => 'morphedByMany',
        ];

        foreach ($map as $key => $type) {
            if (str_contains($className, $key)) {
                return $type;
            }
        }

        return 'unknown';
    }

    protected function getIgnoredMethods(): array
    {
        return [
            '__construct',
            '__destruct',
            '__call',
            '__callStatic',
            '__get',
            '__set',
            '__isset',
            '__unset',
            '__sleep',
            '__wakeup',
            '__toString',
            '__invoke',
            '__set_state',
            '__clone',
            '__debugInfo',
        ];
    }
}