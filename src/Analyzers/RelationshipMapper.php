<?php

namespace LaravelMint\Analyzers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use LaravelMint\Mint;
use ReflectionClass;
use ReflectionMethod;

class RelationshipMapper
{
    protected Mint $mint;

    protected array $visitedModels = [];

    protected array $relationshipMap = [];

    protected array $dependencyGraph = [];

    public function __construct(Mint $mint)
    {
        $this->mint = $mint;
    }

    public function map(string $modelClass, int $depth = 0): array
    {
        // Reset visited models if this is the top-level call
        if ($depth === 0) {
            $this->visitedModels = [];
        }

        if (! class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class {$modelClass} does not exist");
        }

        $maxDepth = $this->mint->getConfig('analysis.max_depth', 10);

        if ($depth >= $maxDepth) {
            return [
                'warning' => "Maximum relationship depth ({$maxDepth}) reached",
                'relationships' => [],
            ];
        }

        if (in_array($modelClass, $this->visitedModels)) {
            return [
                'circular_reference' => true,
                'model' => $modelClass,
                'relationships' => [],
                'dependencies' => [
                    'required' => [],
                    'dependent' => [],
                    'optional' => [],
                ],
                'generation_order' => [
                    'priority' => 0,
                    'can_parallelize' => false,
                    'strategy' => 'circular',
                    'warning' => 'Circular dependency detected',
                ],
                'depth' => $depth,
            ];
        }

        $this->visitedModels[] = $modelClass;

        $relationships = $this->discoverRelationships($modelClass);
        $dependencies = $this->analyzeDependencies($modelClass, $relationships);
        $order = $this->calculateGenerationOrder($modelClass, $relationships);

        $result = [
            'model' => $modelClass,
            'relationships' => $relationships,
            'dependencies' => $dependencies,
            'generation_order' => $order,
            'depth' => $depth,
        ];

        // Recursively map related models
        foreach ($relationships as $relationName => $relationData) {
            if (isset($relationData['related_model']) &&
                ! in_array($relationData['related_model'], $this->visitedModels)) {
                $result['relationships'][$relationName]['nested'] = $this->map(
                    $relationData['related_model'],
                    $depth + 1
                );
            }
        }

        return $result;
    }

    protected function discoverRelationships(string $modelClass): array
    {
        $reflection = new ReflectionClass($modelClass);
        $instance = new $modelClass;
        $relationships = [];

        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            // Skip non-relation methods
            if ($this->shouldSkipMethod($method, $reflection)) {
                continue;
            }

            try {
                // Try to determine if this is a relationship method
                $relationData = $this->analyzeMethod($instance, $method);

                if ($relationData !== null) {
                    $relationships[$method->getName()] = $relationData;
                }
            } catch (\Exception $e) {
                // Skip methods that throw exceptions
                continue;
            }
        }

        return $relationships;
    }

    protected function shouldSkipMethod(ReflectionMethod $method, ReflectionClass $reflection): bool
    {
        return $method->class !== $reflection->getName() ||
               $method->getNumberOfParameters() > 0 ||
               $method->isStatic() ||
               $method->isAbstract() ||
               in_array($method->getName(), $this->getIgnoredMethods());
    }

    protected function analyzeMethod(Model $instance, ReflectionMethod $method): ?array
    {
        $returnType = $method->getReturnType();

        if (! $returnType || $returnType->isBuiltin()) {
            return null;
        }

        $typeName = $returnType->getName();

        if (! $this->isRelationClass($typeName)) {
            return null;
        }

        // Get the actual relation instance to extract more details
        try {
            $relation = $method->invoke($instance);

            if (! $relation instanceof Relation) {
                return null;
            }

            return $this->extractRelationshipData($relation, $method->getName());
        } catch (\Exception $e) {
            // If we can't invoke the method, try to extract from return type
            return [
                'type' => $this->getRelationType($typeName),
                'method' => $method->getName(),
                'return_type' => $typeName,
            ];
        }
    }

    protected function extractRelationshipData(Relation $relation, string $methodName): array
    {
        $relationType = $this->getRelationTypeFromInstance($relation);
        $data = [
            'type' => $relationType,
            'method' => $methodName,
            'related_model' => get_class($relation->getRelated()),
            'local_key' => null,
            'foreign_key' => null,
            'pivot_table' => null,
        ];

        // Extract keys based on relation type
        switch ($relationType) {
            case 'belongsTo':
                $data['foreign_key'] = $relation->getForeignKeyName();
                $data['owner_key'] = $relation->getOwnerKeyName();
                $data['required'] = true; // BelongsTo typically means this is required
                break;

            case 'hasOne':
            case 'hasMany':
                $data['foreign_key'] = $relation->getForeignKeyName();
                $data['local_key'] = $relation->getLocalKeyName();
                $data['required'] = false;
                break;

            case 'belongsToMany':
                $data['pivot_table'] = $relation->getTable();
                $data['foreign_pivot_key'] = $relation->getForeignPivotKeyName();
                $data['related_pivot_key'] = $relation->getRelatedPivotKeyName();
                $data['required'] = false;
                break;

            case 'hasManyThrough':
            case 'hasOneThrough':
                $data['through_model'] = get_class($relation->getParent());
                $data['first_key'] = $relation->getFirstKeyName();
                $data['second_key'] = $relation->getSecondLocalKeyName();
                $data['required'] = false;
                break;

            case 'morphTo':
                $data['morph_type'] = $relation->getMorphType();
                $data['foreign_key'] = $relation->getForeignKeyName();
                $data['polymorphic'] = true;
                $data['required'] = false;
                break;

            case 'morphOne':
            case 'morphMany':
                $data['morph_name'] = $relation->getMorphClass();
                $data['morph_type'] = $relation->getMorphType();
                $data['foreign_key'] = $relation->getForeignKeyName();
                $data['polymorphic'] = true;
                $data['required'] = false;
                break;

            case 'morphToMany':
            case 'morphedByMany':
                $data['pivot_table'] = $relation->getTable();
                $data['morph_name'] = $relation->getMorphClass();
                $data['morph_type'] = $relation->getMorphType();
                $data['polymorphic'] = true;
                $data['required'] = false;
                break;
        }

        // Extract cardinality hints
        $data['cardinality'] = $this->inferCardinality($relationType);

        return $data;
    }

    protected function analyzeDependencies(string $modelClass, array $relationships): array
    {
        $dependencies = [
            'required' => [], // Models that must exist before this one
            'optional' => [], // Models that can exist but aren't required
            'dependent' => [], // Models that depend on this one
        ];

        foreach ($relationships as $relationName => $relationData) {
            if (! isset($relationData['related_model'])) {
                continue;
            }

            $relatedModel = $relationData['related_model'];
            $relationType = $relationData['type'];

            // Determine dependency type based on relationship
            if ($relationType === 'belongsTo') {
                // This model depends on the related model
                $dependencies['required'][] = [
                    'model' => $relatedModel,
                    'relation' => $relationName,
                    'type' => $relationType,
                ];
            } elseif (in_array($relationType, ['hasOne', 'hasMany', 'morphOne', 'morphMany'])) {
                // The related model depends on this model
                $dependencies['dependent'][] = [
                    'model' => $relatedModel,
                    'relation' => $relationName,
                    'type' => $relationType,
                ];
            } else {
                // Many-to-many and polymorphic relationships are optional
                $dependencies['optional'][] = [
                    'model' => $relatedModel,
                    'relation' => $relationName,
                    'type' => $relationType,
                ];
            }
        }

        return $dependencies;
    }

    protected function calculateGenerationOrder(string $modelClass, array $relationships): array
    {
        $order = [
            'priority' => 0, // Lower numbers should be generated first
            'can_parallelize' => true,
            'strategy' => 'simple',
        ];

        $hasBelongsTo = false;
        $hasMany = false;
        $hasManyToMany = false;

        foreach ($relationships as $relationData) {
            $type = $relationData['type'] ?? '';

            if ($type === 'belongsTo') {
                $hasBelongsTo = true;
                $order['priority'] += 10; // Increase priority for each dependency
            } elseif (in_array($type, ['hasMany', 'morphMany'])) {
                $hasMany = true;
            } elseif (in_array($type, ['belongsToMany', 'morphToMany', 'morphedByMany'])) {
                $hasManyToMany = true;
            }
        }

        // Determine generation strategy
        if ($hasBelongsTo && $hasMany) {
            $order['strategy'] = 'complex';
            $order['can_parallelize'] = false;
        } elseif ($hasManyToMany) {
            $order['strategy'] = 'pivot';
            $order['priority'] += 5;
        }

        // Check for circular dependencies
        if ($this->hasCircularDependency($modelClass, $relationships)) {
            $order['strategy'] = 'circular';
            $order['can_parallelize'] = false;
            $order['warning'] = 'Circular dependency detected';
        }

        return $order;
    }

    protected function hasCircularDependency(string $modelClass, array $relationships): bool
    {
        // Simple check for self-referencing relationships
        foreach ($relationships as $relationData) {
            if (isset($relationData['related_model']) &&
                $relationData['related_model'] === $modelClass) {
                return true;
            }
        }

        // TODO: Implement more sophisticated circular dependency detection
        return false;
    }

    protected function inferCardinality(string $relationType): array
    {
        $cardinalities = [
            'belongsTo' => ['min' => 1, 'max' => 1],
            'hasOne' => ['min' => 0, 'max' => 1],
            'hasMany' => ['min' => 0, 'max' => null],
            'belongsToMany' => ['min' => 0, 'max' => null],
            'hasManyThrough' => ['min' => 0, 'max' => null],
            'hasOneThrough' => ['min' => 0, 'max' => 1],
            'morphTo' => ['min' => 0, 'max' => 1],
            'morphOne' => ['min' => 0, 'max' => 1],
            'morphMany' => ['min' => 0, 'max' => null],
            'morphToMany' => ['min' => 0, 'max' => null],
            'morphedByMany' => ['min' => 0, 'max' => null],
        ];

        return $cardinalities[$relationType] ?? ['min' => 0, 'max' => null];
    }

    protected function getRelationTypeFromInstance(Relation $relation): string
    {
        $className = get_class($relation);
        $baseName = class_basename($className);

        // Convert class name to snake_case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', lcfirst($baseName)));
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
            'getAttribute',
            'setAttribute',
            'getAttributes',
            'setAttributes',
            'save',
            'update',
            'delete',
            'forceDelete',
            'restore',
            'fresh',
            'refresh',
            'replicate',
            'is',
            'isNot',
            'exists',
            'wasRecentlyCreated',
        ];
    }

    public function buildDependencyGraph(array $models): array
    {
        $graph = [];

        foreach ($models as $model) {
            $this->visitedModels = [];
            $mapping = $this->map($model);
            $graph[$model] = $mapping;
        }

        return $this->topologicalSort($graph);
    }

    protected function topologicalSort(array $graph): array
    {
        $sorted = [];
        $visited = [];
        $visiting = [];

        foreach (array_keys($graph) as $node) {
            if (! isset($visited[$node])) {
                $this->topologicalSortVisit($node, $graph, $visited, $visiting, $sorted);
            }
        }

        return array_reverse($sorted);
    }

    protected function topologicalSortVisit(
        string $node,
        array &$graph,
        array &$visited,
        array &$visiting,
        array &$sorted
    ): void {
        $visiting[$node] = true;

        if (isset($graph[$node]['dependencies']['required'])) {
            foreach ($graph[$node]['dependencies']['required'] as $dependency) {
                $depModel = $dependency['model'];

                if (isset($visiting[$depModel])) {
                    // Circular dependency detected
                    continue;
                }

                if (! isset($visited[$depModel])) {
                    $this->topologicalSortVisit($depModel, $graph, $visited, $visiting, $sorted);
                }
            }
        }

        unset($visiting[$node]);
        $visited[$node] = true;
        $sorted[] = $node;
    }
}
