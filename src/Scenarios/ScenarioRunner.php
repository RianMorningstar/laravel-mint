<?php

namespace LaravelMint\Scenarios;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LaravelMint\Mint;

class ScenarioRunner
{
    protected Mint $mint;

    protected array $scenarios = [];

    protected array $results = [];

    protected bool $transactional = true;

    protected bool $dryRun = false;

    protected $progressCallback = null;

    public function __construct(?Mint $mint = null)
    {
        $this->mint = $mint ?? app('mint');
    }

    /**
     * Register a scenario
     */
    public function register(string $name, $scenario): self
    {
        if (is_string($scenario) && class_exists($scenario)) {
            $scenario = new $scenario($this->mint);
        }

        if (! $scenario instanceof ScenarioInterface) {
            throw new \InvalidArgumentException('Scenario must implement ScenarioInterface');
        }

        $this->scenarios[$name] = $scenario;

        return $this;
    }

    /**
     * Register multiple scenarios
     */
    public function registerMany(array $scenarios): self
    {
        foreach ($scenarios as $name => $scenario) {
            $this->register($name, $scenario);
        }

        return $this;
    }

    /**
     * Discover and register scenarios from a directory
     */
    public function discover(string $directory): self
    {
        if (! is_dir($directory)) {
            throw new \InvalidArgumentException("Directory does not exist: {$directory}");
        }

        $files = glob($directory.'/*Scenario.php');

        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file);

            if ($className && class_exists($className)) {
                $scenario = new $className($this->mint);

                if ($scenario instanceof ScenarioInterface) {
                    $name = $scenario->getName();
                    $this->register($name, $scenario);
                }
            }
        }

        return $this;
    }

    /**
     * Get class name from file
     */
    protected function getClassNameFromFile(string $file): ?string
    {
        $contents = file_get_contents($file);

        if (preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch)) {
            $namespace = $namespaceMatch[1];

            if (preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
                $className = $classMatch[1];

                return $namespace.'\\'.$className;
            }
        }

        return null;
    }

    /**
     * Set whether to use transactions
     */
    public function withTransactions(bool $use = true): self
    {
        $this->transactional = $use;

        return $this;
    }

    /**
     * Set dry run mode
     */
    public function dryRun(bool $enable = true): self
    {
        $this->dryRun = $enable;

        return $this;
    }

    /**
     * Set progress callback
     */
    public function onProgress(callable $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    /**
     * Run a specific scenario
     */
    public function run(string $name, array $options = []): ScenarioResult
    {
        if (! isset($this->scenarios[$name])) {
            throw new \InvalidArgumentException("Scenario not found: {$name}");
        }

        $scenario = $this->scenarios[$name];

        // Validate scenario
        $validator = new ScenarioValidator;
        $validation = $validator->validate($scenario, $options);

        if (! $validation->isValid()) {
            $result = new ScenarioResult($name);
            foreach ($validation->getErrors() as $error) {
                $result->addError($error);
            }

            return $result;
        }

        // Report progress
        $this->reportProgress($name, 'Starting scenario');

        // Run in dry run mode if enabled
        if ($this->dryRun) {
            return $this->runDryRun($scenario, $options);
        }

        // Run the scenario
        if ($this->transactional) {
            return $this->runInTransaction($scenario, $options);
        }

        return $this->runDirect($scenario, $options);
    }

    /**
     * Run multiple scenarios
     */
    public function runMany(array $scenarios, array $options = []): array
    {
        $results = [];

        foreach ($scenarios as $name => $scenarioOptions) {
            if (is_string($scenarioOptions)) {
                $name = $scenarioOptions;
                $scenarioOptions = [];
            }

            $mergedOptions = array_merge($options, $scenarioOptions);
            $results[$name] = $this->run($name, $mergedOptions);
        }

        $this->results = $results;

        return $results;
    }

    /**
     * Run all registered scenarios
     */
    public function runAll(array $options = []): array
    {
        return $this->runMany(array_keys($this->scenarios), $options);
    }

    /**
     * Run scenario in transaction
     */
    protected function runInTransaction(ScenarioInterface $scenario, array $options): ScenarioResult
    {
        try {
            DB::beginTransaction();

            $result = $scenario->run($options);

            if ($result->isSuccess()) {
                DB::commit();
                $this->reportProgress($scenario->getName(), 'Scenario completed successfully');
            } else {
                DB::rollBack();
                $this->reportProgress($scenario->getName(), 'Scenario failed, rolling back');
            }

            return $result;
        } catch (\Exception $e) {
            DB::rollBack();

            $result = new ScenarioResult($scenario->getName());
            $result->addError('Exception: '.$e->getMessage());

            Log::error('Scenario failed', [
                'scenario' => $scenario->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $result;
        }
    }

    /**
     * Run scenario directly
     */
    protected function runDirect(ScenarioInterface $scenario, array $options): ScenarioResult
    {
        try {
            $result = $scenario->run($options);

            if ($result->isSuccess()) {
                $this->reportProgress($scenario->getName(), 'Scenario completed successfully');
            } else {
                $this->reportProgress($scenario->getName(), 'Scenario completed with errors');
            }

            return $result;
        } catch (\Exception $e) {
            $result = new ScenarioResult($scenario->getName());
            $result->addError('Exception: '.$e->getMessage());

            Log::error('Scenario failed', [
                'scenario' => $scenario->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $result;
        }
    }

    /**
     * Run scenario in dry run mode
     */
    protected function runDryRun(ScenarioInterface $scenario, array $options): ScenarioResult
    {
        $result = new ScenarioResult($scenario->getName());

        // Validate without running
        if (! $scenario->validate()) {
            foreach ($scenario->getValidationErrors() as $error) {
                $result->addError($error);
            }

            return $result;
        }

        // Get estimates
        $estimates = $scenario->estimate();

        $result->addStatistic('dry_run', true);
        $result->addStatistic('estimated_records', $estimates['total_records'] ?? 0);
        $result->addStatistic('estimated_time', $estimates['estimated_time'] ?? 'Unknown');
        $result->addStatistic('estimated_memory', $estimates['estimated_memory'] ?? 'Unknown');

        $this->reportProgress($scenario->getName(), 'Dry run completed');

        return $result;
    }

    /**
     * Report progress
     */
    protected function reportProgress(string $scenario, string $message): void
    {
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $scenario, $message);
        }
    }

    /**
     * Get registered scenarios
     */
    public function getScenarios(): array
    {
        return $this->scenarios;
    }

    /**
     * Get scenario by name
     */
    public function getScenario(string $name): ?ScenarioInterface
    {
        return $this->scenarios[$name] ?? null;
    }

    /**
     * List available scenarios
     */
    public function list(): array
    {
        $list = [];

        foreach ($this->scenarios as $name => $scenario) {
            $list[$name] = [
                'name' => $scenario->getName(),
                'description' => $scenario->getDescription(),
                'required_models' => $scenario->getRequiredModels(),
                'optional_models' => $scenario->getOptionalModels(),
                'parameters' => $scenario->getParameters(),
            ];
        }

        return $list;
    }

    /**
     * Get last results
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get summary of all results
     */
    public function getSummary(): array
    {
        $summary = [
            'total_scenarios' => count($this->results),
            'successful' => 0,
            'failed' => 0,
            'total_records' => 0,
            'total_time' => 0,
            'errors' => [],
        ];

        foreach ($this->results as $name => $result) {
            if ($result->isSuccess()) {
                $summary['successful']++;
            } else {
                $summary['failed']++;
                $summary['errors'][$name] = $result->getErrors();
            }

            $summary['total_records'] += $result->getTotalGenerated();
            $summary['total_time'] += $result->getExecutionTime();
        }

        $summary['total_time'] = round($summary['total_time'], 2).'s';

        return $summary;
    }
}
