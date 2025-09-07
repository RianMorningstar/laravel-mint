<?php

namespace LaravelMint\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaravelMint\Mint;

class SeederIntegration
{
    protected Mint $mint;

    protected array $seeders = [];

    protected array $dependencies = [];

    protected bool $trackSeeded = true;

    protected string $trackingTable = 'mint_seeded_data';

    public function __construct(?Mint $mint = null)
    {
        $this->mint = $mint ?? app('mint');
    }

    /**
     * Generate seeder class from existing data
     */
    public function generateSeeder(string $modelClass, ?string $seederName = null): string
    {
        $seederName = $seederName ?? class_basename($modelClass).'Seeder';
        $modelName = class_basename($modelClass);
        $tableName = (new $modelClass)->getTable();

        // Get sample data
        $data = $modelClass::limit(10)->get()->toArray();

        $template = <<<PHP
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use {$modelClass};
use LaravelMint\Mint;

class {$seederName} extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \$mint = app('mint');
        
        // Generate using Mint patterns
        \$mint->generate({$modelClass}::class, 100, [
            'use_patterns' => true,
            'silent' => false,
        ]);
        
        // Or use specific data
        \$data = {$this->formatDataArray($data)};
        
        foreach (\$data as \$item) {
            {$modelClass}::create(\$item);
        }
    }
}
PHP;

        $path = database_path("seeders/{$seederName}.php");
        file_put_contents($path, $template);

        return $path;
    }

    /**
     * Generate smart seeder with dependencies
     */
    public function generateSmartSeeder(array $models, string $seederName = 'SmartDataSeeder'): string
    {
        $dependencies = $this->analyzeDependencies($models);
        $orderedModels = $this->orderByDependencies($models, $dependencies);

        $useStatements = array_map(fn ($m) => "use {$m};", $orderedModels);
        $seedCalls = $this->generateSeedCalls($orderedModels);

        $template = <<<PHP
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LaravelMint\Mint;
{$this->implodeLines($useStatements)}

class {$seederName} extends Seeder
{
    protected \$mint;
    
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \$this->mint = app('mint');
        
        DB::transaction(function () {
            {$this->implodeLines($seedCalls, 12)}
        });
    }
    
    /**
     * Seed model with Mint
     */
    protected function seedModel(string \$modelClass, int \$count, array \$options = []): void
    {
        echo "Seeding {\$modelClass}...\\n";
        
        \$this->mint->generate(\$modelClass, \$count, array_merge([
            'use_patterns' => true,
            'silent' => false,
        ], \$options));
    }
}
PHP;

        $path = database_path("seeders/{$seederName}.php");
        file_put_contents($path, $template);

        return $path;
    }

    /**
     * Seed with environment-specific data
     */
    public function seedForEnvironment(?string $environment = null): void
    {
        $environment = $environment ?? app()->environment();

        $config = config("mint.seeds.{$environment}", []);

        if (empty($config)) {
            throw new \RuntimeException("No seed configuration for environment: {$environment}");
        }

        DB::beginTransaction();

        try {
            foreach ($config as $modelClass => $settings) {
                $this->seedModel($modelClass, $settings);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Seed single model
     */
    protected function seedModel(string $modelClass, array $settings): void
    {
        $count = $settings['count'] ?? 10;
        $options = $settings['options'] ?? [];

        // Track seeded data if enabled
        if ($this->trackSeeded) {
            $options['after_create'] = function ($model) use ($modelClass) {
                $this->trackSeededRecord($modelClass, $model->id);
            };
        }

        $this->mint->generate($modelClass, $count, $options);
    }

    /**
     * Track seeded record
     */
    protected function trackSeededRecord(string $modelClass, $modelId): void
    {
        if (! $this->isTrackingTableCreated()) {
            $this->createTrackingTable();
        }

        DB::table($this->trackingTable)->insert([
            'model' => $modelClass,
            'model_id' => $modelId,
            'environment' => app()->environment(),
            'created_at' => now(),
        ]);
    }

    /**
     * Rollback seeded data
     */
    public function rollback(?string $environment = null): int
    {
        if (! $this->isTrackingTableCreated()) {
            return 0;
        }

        $environment = $environment ?? app()->environment();

        $records = DB::table($this->trackingTable)
            ->where('environment', $environment)
            ->get();

        $deleted = 0;

        DB::beginTransaction();

        try {
            foreach ($records->groupBy('model') as $modelClass => $modelRecords) {
                if (class_exists($modelClass)) {
                    $ids = $modelRecords->pluck('model_id')->toArray();
                    $deleted += $modelClass::whereIn('id', $ids)->delete();
                }
            }

            // Clear tracking records
            DB::table($this->trackingTable)
                ->where('environment', $environment)
                ->delete();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $deleted;
    }

    /**
     * Analyze model dependencies
     */
    protected function analyzeDependencies(array $models): array
    {
        $dependencies = [];

        foreach ($models as $model) {
            $dependencies[$model] = $this->getModelDependencies($model);
        }

        return $dependencies;
    }

    /**
     * Get model dependencies
     */
    protected function getModelDependencies(string $modelClass): array
    {
        $dependencies = [];
        $model = new $modelClass;

        // Check for foreign keys
        $table = $model->getTable();
        $columns = DB::getSchemaBuilder()->getColumnListing($table);

        foreach ($columns as $column) {
            if (Str::endsWith($column, '_id')) {
                $relationName = Str::camel(Str::replaceLast('_id', '', $column));

                if (method_exists($model, $relationName)) {
                    try {
                        $relation = $model->$relationName();
                        $relatedModel = get_class($relation->getRelated());
                        $dependencies[] = $relatedModel;
                    } catch (\Exception $e) {
                        // Ignore if relation method fails
                    }
                }
            }
        }

        return array_unique($dependencies);
    }

    /**
     * Order models by dependencies
     */
    protected function orderByDependencies(array $models, array $dependencies): array
    {
        $ordered = [];
        $remaining = $models;
        $iterations = 0;
        $maxIterations = count($models) * 2;

        while (! empty($remaining) && $iterations < $maxIterations) {
            foreach ($remaining as $key => $model) {
                $modelDeps = $dependencies[$model] ?? [];

                // Check if all dependencies are already ordered
                $canAdd = true;
                foreach ($modelDeps as $dep) {
                    if (in_array($dep, $remaining) && ! in_array($dep, $ordered)) {
                        $canAdd = false;
                        break;
                    }
                }

                if ($canAdd) {
                    $ordered[] = $model;
                    unset($remaining[$key]);
                }
            }

            $iterations++;
        }

        // Add any remaining (circular dependencies)
        foreach ($remaining as $model) {
            $ordered[] = $model;
        }

        return $ordered;
    }

    /**
     * Generate seed calls for models
     */
    protected function generateSeedCalls(array $models): array
    {
        $calls = [];

        foreach ($models as $model) {
            $modelName = class_basename($model);
            $count = $this->getDefaultCount($model);

            $calls[] = "\$this->seedModel({$model}::class, {$count});";
        }

        return $calls;
    }

    /**
     * Get default count for model
     */
    protected function getDefaultCount(string $modelClass): int
    {
        $defaults = [
            'User' => 100,
            'Product' => 500,
            'Order' => 1000,
            'Category' => 20,
            'Tag' => 50,
        ];

        $basename = class_basename($modelClass);

        return $defaults[$basename] ?? 50;
    }

    /**
     * Check if tracking table exists
     */
    protected function isTrackingTableCreated(): bool
    {
        return DB::getSchemaBuilder()->hasTable($this->trackingTable);
    }

    /**
     * Create tracking table
     */
    protected function createTrackingTable(): void
    {
        DB::getSchemaBuilder()->create($this->trackingTable, function ($table) {
            $table->id();
            $table->string('model');
            $table->unsignedBigInteger('model_id');
            $table->string('environment');
            $table->timestamps();

            $table->index(['model', 'model_id']);
            $table->index('environment');
        });
    }

    /**
     * Format data array for seeder
     */
    protected function formatDataArray(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Convert JSON to PHP array syntax
        $php = str_replace(['{', '}', ':'], ['[', ']', '=>'], $json);
        $php = preg_replace('/"(\w+)" =>/', '$1 =>', $php);

        return $php;
    }

    /**
     * Implode lines with indentation
     */
    protected function implodeLines(array $lines, int $indent = 0): string
    {
        $spaces = str_repeat(' ', $indent);

        return implode("\n{$spaces}", $lines);
    }

    /**
     * Set tracking mode
     */
    public function trackSeeded(bool $track = true): self
    {
        $this->trackSeeded = $track;

        return $this;
    }

    /**
     * Get seeded statistics
     */
    public function getStatistics(?string $environment = null): array
    {
        if (! $this->isTrackingTableCreated()) {
            return [];
        }

        $query = DB::table($this->trackingTable);

        if ($environment) {
            $query->where('environment', $environment);
        }

        $stats = $query
            ->selectRaw('model, environment, COUNT(*) as count')
            ->groupBy('model', 'environment')
            ->get();

        return $stats->groupBy('environment')->map(function ($envStats) {
            return $envStats->pluck('count', 'model')->toArray();
        })->toArray();
    }
}
