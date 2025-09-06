<?php

namespace LaravelMint\Tests\Helpers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class TestModelFactory
{
    protected static array $createdModels = [];
    
    /**
     * Create a test model class dynamically
     */
    public static function create(string $modelName, array $attributes = [], array $relationships = []): string
    {
        $className = "Test{$modelName}Model";
        $tableName = strtolower($modelName) . 's';
        
        // Create the table
        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($attributes, $relationships) {
                $table->id();
                
                foreach ($attributes as $name => $type) {
                    switch ($type) {
                        case 'string':
                            $table->string($name)->nullable();
                            break;
                        case 'integer':
                            $table->integer($name)->nullable();
                            break;
                        case 'boolean':
                            $table->boolean($name)->default(false);
                            break;
                        case 'text':
                            $table->text($name)->nullable();
                            break;
                        case 'datetime':
                            $table->dateTime($name)->nullable();
                            break;
                        case 'decimal':
                            $table->decimal($name, 10, 2)->nullable();
                            break;
                        case 'json':
                            $table->json($name)->nullable();
                            break;
                    }
                }
                
                // Add foreign keys for relationships
                foreach ($relationships as $relation => $config) {
                    if ($config['type'] === 'belongsTo') {
                        $table->foreignId($config['foreign_key'] ?? "{$relation}_id")->nullable();
                    }
                }
                
                $table->timestamps();
            });
        }
        
        // Create the model class if it doesn't exist
        if (!class_exists($className)) {
            eval("
                class {$className} extends \Illuminate\Database\Eloquent\Model {
                    protected \$table = '{$tableName}';
                    protected \$guarded = [];
                    protected \$casts = " . var_export(self::getCasts($attributes), true) . ";
                    
                    " . self::generateRelationshipMethods($relationships) . "
                }
            ");
        }
        
        self::$createdModels[] = $className;
        
        return $className;
    }
    
    /**
     * Clean up created models and tables
     */
    public static function cleanup(): void
    {
        foreach (self::$createdModels as $className) {
            $instance = new $className;
            Schema::dropIfExists($instance->getTable());
        }
        
        self::$createdModels = [];
    }
    
    /**
     * Get casts array for attributes
     */
    protected static function getCasts(array $attributes): array
    {
        $casts = [];
        
        foreach ($attributes as $name => $type) {
            switch ($type) {
                case 'boolean':
                    $casts[$name] = 'boolean';
                    break;
                case 'integer':
                    $casts[$name] = 'integer';
                    break;
                case 'datetime':
                    $casts[$name] = 'datetime';
                    break;
                case 'decimal':
                    $casts[$name] = 'decimal:2';
                    break;
                case 'json':
                    $casts[$name] = 'array';
                    break;
            }
        }
        
        return $casts;
    }
    
    /**
     * Generate relationship method definitions
     */
    protected static function generateRelationshipMethods(array $relationships): string
    {
        $methods = [];
        
        foreach ($relationships as $relation => $config) {
            $type = $config['type'];
            $related = $config['model'];
            
            switch ($type) {
                case 'hasMany':
                    $methods[] = "
                        public function {$relation}() {
                            return \$this->hasMany('{$related}');
                        }
                    ";
                    break;
                case 'belongsTo':
                    $methods[] = "
                        public function {$relation}() {
                            return \$this->belongsTo('{$related}');
                        }
                    ";
                    break;
                case 'hasOne':
                    $methods[] = "
                        public function {$relation}() {
                            return \$this->hasOne('{$related}');
                        }
                    ";
                    break;
                case 'belongsToMany':
                    $methods[] = "
                        public function {$relation}() {
                            return \$this->belongsToMany('{$related}');
                        }
                    ";
                    break;
            }
        }
        
        return implode("\n", $methods);
    }
    
    /**
     * Create a model with sample data
     */
    public static function createWithData(string $modelName, array $attributes = [], int $count = 1): array
    {
        $className = self::create($modelName, $attributes);
        $instances = [];
        
        for ($i = 0; $i < $count; $i++) {
            $data = [];
            foreach ($attributes as $name => $type) {
                $data[$name] = self::generateSampleData($type, $name);
            }
            
            $instances[] = $className::create($data);
        }
        
        return $instances;
    }
    
    /**
     * Generate sample data based on type
     */
    protected static function generateSampleData(string $type, string $name): mixed
    {
        return match ($type) {
            'string' => fake()->word(),
            'integer' => fake()->numberBetween(1, 100),
            'boolean' => fake()->boolean(),
            'text' => fake()->paragraph(),
            'datetime' => fake()->dateTime(),
            'decimal' => fake()->randomFloat(2, 0, 1000),
            'json' => ['key' => fake()->word()],
            default => null,
        };
    }
}