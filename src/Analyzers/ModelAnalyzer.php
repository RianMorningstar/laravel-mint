<?php

namespace LaravelMint\Analyzers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use LaravelMint\Mint;
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
        if (! class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class {$modelClass} does not exist");
        }

        if (! is_subclass_of($modelClass, Model::class)) {
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

        // Get schema information
        $schemaInspector = new SchemaInspector($this->mint);
        $schemaInfo = $schemaInspector->inspect($modelClass);

        // Create 'attributes' with field information
        $attributes = [];
        if (isset($schemaInfo['columns']) && ! empty($schemaInfo['columns'])) {
            foreach ($schemaInfo['columns'] as $column => $details) {
                $attributes[$column] = [
                    'type' => $details['type'] ?? 'string',
                    'nullable' => $details['nullable'] ?? false,
                    'default' => $details['default'] ?? null,
                    'unique' => $details['unique'] ?? false,
                ];
            }
        } elseif (method_exists($instance, 'getSchemaColumns')) {
            // Fallback to model's getSchemaColumns method (for test models)
            $schemaColumns = $instance->getSchemaColumns();
            foreach ($schemaColumns as $column => $type) {
                // Get schema details from the actual database if possible
                $columnDetails = $this->getColumnDetailsDirectly($instance->getTable(), $column);
                $attributes[$column] = [
                    'type' => $type,
                    'nullable' => $columnDetails['nullable'] ?? false,
                    'default' => $columnDetails['default'] ?? null,
                    'unique' => $columnDetails['unique'] ?? false,
                ];
            }
        }

        // Also create 'fields' for compatibility
        $fields = [];
        foreach ($attributes as $name => $details) {
            $fields[$name] = $details;
        }

        return [
            'model' => $modelClass,
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
            'attributes' => $attributes,
            'fields' => $fields,
            'appends' => $this->getAppends($instance),
            'dates' => $this->getDates($instance),
            'relationships' => $this->analyzeRelations($reflection, $instance),
            'relations' => $this->analyzeRelations($reflection, $instance),
            'scopes' => $this->analyzeScopes($reflection),
            'mutators' => $this->analyzeMutators($reflection),
            'accessors' => $this->analyzeAccessors($reflection),
            'validation_rules' => $this->extractValidationRules($modelClass),
            'validation_suggestions' => $this->suggestValidationRules($attributes),
            'traits' => $this->analyzeTraits($reflection),
            'indexes' => $schemaInfo['indexes'] ?? [],
            'record_count' => $this->getRecordCount($modelClass),
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
                // Try to call the method and check if it returns a relation
                $result = $method->invoke($instance);

                if (is_object($result)) {
                    $resultClass = get_class($result);
                    if ($this->isRelationClass($resultClass)) {
                        // Get the related model from the relation
                        $relatedModel = null;
                        if (method_exists($result, 'getRelated')) {
                            $relatedModel = get_class($result->getRelated());
                        }

                        $relations[$method->getName()] = [
                            'type' => $this->getRelationType($resultClass),
                            'class' => $resultClass,
                            'method' => $method->getName(),
                            'model' => $relatedModel,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Also try checking return type annotation if method invocation fails
                try {
                    $returnType = $method->getReturnType();
                    if ($returnType && ! $returnType->isBuiltin()) {
                        $typeName = $returnType->getName();
                        if ($this->isRelationClass($typeName)) {
                            $relations[$method->getName()] = [
                                'type' => $this->getRelationType($typeName),
                                'class' => $typeName,
                                'method' => $method->getName(),
                            ];
                        }
                    }
                } catch (\Exception $e2) {
                    // Skip methods that throw exceptions
                    continue;
                }
            }
        }

        return $relations;
    }

    protected function analyzeScopes(ReflectionClass $reflection): array
    {
        if (! $this->mint->getConfig('analysis.detect_scopes')) {
            return [];
        }

        $scopes = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $name = $method->getName();
            if (str_starts_with($name, 'scope') && strlen($name) > 5) {
                $scopeName = lcfirst(substr($name, 5));
                $scopes[] = $scopeName;
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
                $mutators[] = $attribute;
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
                $accessors[] = $attribute;
            }
        }

        return $accessors;
    }

    protected function extractValidationRules(string $modelClass): array
    {
        if (! $this->mint->getConfig('analysis.detect_validations')) {
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
        // Order matters - check longer strings first to avoid false matches
        $map = [
            'BelongsToMany' => 'belongsToMany',  // Must come before BelongsTo
            'HasOneThrough' => 'hasOneThrough',  // Must come before HasOne
            'HasManyThrough' => 'hasManyThrough', // Must come before HasMany
            'MorphToMany' => 'morphToMany',      // Must come before MorphTo
            'MorphedByMany' => 'morphedByMany',
            'HasOne' => 'hasOne',
            'HasMany' => 'hasMany',
            'BelongsTo' => 'belongsTo',
            'MorphTo' => 'morphTo',
            'MorphOne' => 'morphOne',
            'MorphMany' => 'morphMany',
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

    protected function getRecordCount(string $modelClass): int
    {
        try {
            return $modelClass::count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function getColumnDetailsDirectly(string $table, string $column): array
    {
        try {
            $connection = $this->mint->getConnection();
            $schemaInspector = new SchemaInspector($this->mint);
            $schemaInfo = $schemaInspector->inspectTable($table);

            if (isset($schemaInfo['columns'][$column])) {
                return $schemaInfo['columns'][$column];
            }
        } catch (\Exception $e) {
            // Fallback to defaults
        }

        return [
            'nullable' => false,
            'default' => null,
            'unique' => false,
        ];
    }

    protected function suggestValidationRules(array $attributes): array
    {
        $suggestions = [];

        foreach ($attributes as $fieldName => $details) {
            $rules = [];
            $type = $details['type'] ?? 'string';

            // Required/nullable
            if (! ($details['nullable'] ?? true)) {
                $rules[] = 'required';
            } else {
                $rules[] = 'nullable';
            }

            // Type-based rules
            if (str_contains($type, 'int')) {
                $rules[] = 'integer';
            } elseif (str_contains($type, 'bool')) {
                $rules[] = 'boolean';
            } elseif (str_contains($type, 'date')) {
                $rules[] = 'date';
            } elseif (str_contains($type, 'json')) {
                $rules[] = 'json';
            } elseif (str_contains($type, 'decimal') || str_contains($type, 'float')) {
                $rules[] = 'numeric';
            }

            // Field name-based rules
            $fieldLower = strtolower($fieldName);
            if (str_contains($fieldLower, 'email')) {
                $rules[] = 'email';
            } elseif (str_contains($fieldLower, 'url') || str_contains($fieldLower, 'website')) {
                $rules[] = 'url';
            } elseif (str_contains($fieldLower, 'phone')) {
                $rules[] = 'regex:/^[0-9\-\+\(\)\ ]+$/';
            }

            // Unique constraint
            if ($details['unique'] ?? false) {
                $rules[] = 'unique';
            }

            // Length constraint
            if (isset($details['length'])) {
                $rules[] = 'max:'.$details['length'];
            }

            $suggestions[$fieldName] = $rules;
        }

        return $suggestions;
    }
}
