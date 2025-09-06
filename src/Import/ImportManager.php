<?php

namespace LaravelMint\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LaravelMint\Performance\StreamProcessor;
use League\Csv\Reader;
use League\Csv\Statement;

class ImportManager
{
    protected array $mappings = [];
    protected array $validators = [];
    protected array $transformers = [];
    protected array $errors = [];
    protected bool $validateBeforeImport = true;
    protected bool $useTransactions = true;
    protected int $chunkSize = 1000;
    protected StreamProcessor $streamProcessor;
    
    public function __construct()
    {
        $this->streamProcessor = new StreamProcessor();
    }
    
    /**
     * Import from file
     */
    public function import(string $filepath, string $format = null): ImportResult
    {
        if (!file_exists($filepath)) {
            throw new \InvalidArgumentException("File not found: {$filepath}");
        }
        
        $format = $format ?? $this->detectFormat($filepath);
        
        return match($format) {
            'csv' => $this->importCsv($filepath),
            'json' => $this->importJson($filepath),
            'excel', 'xlsx' => $this->importExcel($filepath),
            'sql' => $this->importSql($filepath),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }
    
    /**
     * Import CSV file
     */
    public function importCsv(string $filepath): ImportResult
    {
        $result = new ImportResult('csv', $filepath);
        $startTime = microtime(true);
        
        try {
            $csv = Reader::createFromPath($filepath, 'r');
            $csv->setHeaderOffset(0);
            
            $headers = $csv->getHeader();
            $result->setHeaders($headers);
            
            // Validate headers against mappings
            if (!$this->validateHeaders($headers)) {
                $result->addError('Invalid headers. Expected: ' . implode(', ', array_keys($this->mappings)));
                return $result;
            }
            
            // Process in chunks
            $stmt = Statement::create()->limit($this->chunkSize);
            $offset = 0;
            $totalProcessed = 0;
            
            while (true) {
                $stmt = $stmt->offset($offset);
                $records = $stmt->process($csv);
                
                if (count($records) === 0) {
                    break;
                }
                
                $this->processChunk($records, $result);
                
                $offset += $this->chunkSize;
                $totalProcessed += count($records);
                $result->setProcessed($totalProcessed);
                
                if (count($records) < $this->chunkSize) {
                    break;
                }
            }
        } catch (\Exception $e) {
            $result->addError('CSV import failed: ' . $e->getMessage());
        }
        
        $result->setExecutionTime(microtime(true) - $startTime);
        return $result;
    }
    
    /**
     * Import JSON file
     */
    public function importJson(string $filepath): ImportResult
    {
        $result = new ImportResult('json', $filepath);
        $startTime = microtime(true);
        
        try {
            // Use streaming for large files
            if (filesize($filepath) > 10 * 1024 * 1024) { // 10MB
                $this->importJsonStream($filepath, $result);
            } else {
                $data = json_decode(file_get_contents($filepath), true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
                }
                
                // Handle different JSON structures
                if (isset($data['data'])) {
                    $data = $data['data'];
                }
                
                if (!is_array($data)) {
                    throw new \RuntimeException('JSON must contain an array of records');
                }
                
                // Process in chunks
                $chunks = array_chunk($data, $this->chunkSize);
                foreach ($chunks as $chunk) {
                    $this->processChunk($chunk, $result);
                }
            }
        } catch (\Exception $e) {
            $result->addError('JSON import failed: ' . $e->getMessage());
        }
        
        $result->setExecutionTime(microtime(true) - $startTime);
        return $result;
    }
    
    /**
     * Stream large JSON files
     */
    protected function importJsonStream(string $filepath, ImportResult $result): void
    {
        $handle = fopen($filepath, 'r');
        $buffer = '';
        $inArray = false;
        $depth = 0;
        $currentObject = '';
        $records = [];
        
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            $buffer .= $chunk;
            
            // Simple JSON array parser
            for ($i = 0; $i < strlen($buffer); $i++) {
                $char = $buffer[$i];
                
                if ($char === '[' && !$inArray) {
                    $inArray = true;
                    continue;
                }
                
                if ($inArray) {
                    if ($char === '{') {
                        $depth++;
                    } elseif ($char === '}') {
                        $depth--;
                        $currentObject .= $char;
                        
                        if ($depth === 0 && !empty(trim($currentObject))) {
                            $record = json_decode($currentObject, true);
                            if ($record) {
                                $records[] = $record;
                                
                                if (count($records) >= $this->chunkSize) {
                                    $this->processChunk($records, $result);
                                    $records = [];
                                }
                            }
                            $currentObject = '';
                            continue;
                        }
                    }
                    
                    if ($depth > 0) {
                        $currentObject .= $char;
                    }
                }
            }
            
            $buffer = '';
        }
        
        // Process remaining records
        if (!empty($records)) {
            $this->processChunk($records, $result);
        }
        
        fclose($handle);
    }
    
    /**
     * Import Excel file
     */
    public function importExcel(string $filepath): ImportResult
    {
        $result = new ImportResult('excel', $filepath);
        $startTime = microtime(true);
        
        try {
            // Note: Requires phpoffice/phpspreadsheet package
            if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                throw new \RuntimeException('PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet');
            }
            
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filepath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            if (empty($rows)) {
                throw new \RuntimeException('Excel file is empty');
            }
            
            // First row as headers
            $headers = array_shift($rows);
            $result->setHeaders($headers);
            
            // Process data
            $data = [];
            foreach ($rows as $row) {
                $record = array_combine($headers, $row);
                $data[] = $record;
                
                if (count($data) >= $this->chunkSize) {
                    $this->processChunk($data, $result);
                    $data = [];
                }
            }
            
            // Process remaining
            if (!empty($data)) {
                $this->processChunk($data, $result);
            }
        } catch (\Exception $e) {
            $result->addError('Excel import failed: ' . $e->getMessage());
        }
        
        $result->setExecutionTime(microtime(true) - $startTime);
        return $result;
    }
    
    /**
     * Import SQL file
     */
    public function importSql(string $filepath): ImportResult
    {
        $result = new ImportResult('sql', $filepath);
        $startTime = microtime(true);
        
        try {
            $sql = file_get_contents($filepath);
            
            // Split by semicolon (simple approach)
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            
            DB::beginTransaction();
            
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    DB::statement($statement);
                    $result->incrementProcessed();
                }
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $result->addError('SQL import failed: ' . $e->getMessage());
        }
        
        $result->setExecutionTime(microtime(true) - $startTime);
        return $result;
    }
    
    /**
     * Process a chunk of records
     */
    protected function processChunk($records, ImportResult $result): void
    {
        if ($this->useTransactions) {
            DB::beginTransaction();
        }
        
        try {
            foreach ($records as $record) {
                // Apply transformations
                $transformed = $this->transform($record);
                
                // Validate
                if ($this->validateBeforeImport) {
                    $validation = $this->validate($transformed);
                    if ($validation->fails()) {
                        $result->addValidationError($record, $validation->errors()->all());
                        continue;
                    }
                }
                
                // Import based on mapping
                $this->importRecord($transformed, $result);
            }
            
            if ($this->useTransactions) {
                DB::commit();
            }
        } catch (\Exception $e) {
            if ($this->useTransactions) {
                DB::rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Import single record
     */
    protected function importRecord(array $record, ImportResult $result): void
    {
        foreach ($this->mappings as $modelClass => $mapping) {
            $data = [];
            
            foreach ($mapping as $field => $source) {
                if (is_callable($source)) {
                    $data[$field] = $source($record);
                } elseif (isset($record[$source])) {
                    $data[$field] = $record[$source];
                }
            }
            
            if (!empty($data)) {
                try {
                    $modelClass::create($data);
                    $result->incrementImported($modelClass);
                } catch (\Exception $e) {
                    $result->addError("Failed to import to {$modelClass}: " . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * Set field mappings
     */
    public function mapping(string $modelClass, array $mapping): self
    {
        $this->mappings[$modelClass] = $mapping;
        return $this;
    }
    
    /**
     * Set validation rules
     */
    public function validate(array $record): \Illuminate\Contracts\Validation\Validator
    {
        $rules = [];
        $messages = [];
        
        foreach ($this->validators as $field => $rule) {
            if (is_array($rule)) {
                $rules[$field] = $rule['rules'] ?? [];
                if (isset($rule['messages'])) {
                    foreach ($rule['messages'] as $key => $message) {
                        $messages["{$field}.{$key}"] = $message;
                    }
                }
            } else {
                $rules[$field] = $rule;
            }
        }
        
        return Validator::make($record, $rules, $messages);
    }
    
    /**
     * Set validation rules
     */
    public function rules(array $rules): self
    {
        $this->validators = $rules;
        return $this;
    }
    
    /**
     * Add transformer
     */
    public function transform(array $record): array
    {
        foreach ($this->transformers as $field => $transformer) {
            if (isset($record[$field])) {
                $record[$field] = $transformer($record[$field], $record);
            }
        }
        
        return $record;
    }
    
    /**
     * Set transformers
     */
    public function transformers(array $transformers): self
    {
        $this->transformers = $transformers;
        return $this;
    }
    
    /**
     * Validate headers
     */
    protected function validateHeaders(array $headers): bool
    {
        if (empty($this->mappings)) {
            return true; // No mappings defined, accept any headers
        }
        
        // Check if required fields are present
        foreach ($this->mappings as $mapping) {
            foreach ($mapping as $source) {
                if (!is_callable($source) && !in_array($source, $headers)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Detect file format
     */
    protected function detectFormat(string $filepath): string
    {
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        
        return match($extension) {
            'csv' => 'csv',
            'json' => 'json',
            'xlsx', 'xls' => 'excel',
            'sql' => 'sql',
            default => throw new \InvalidArgumentException("Cannot detect format for extension: {$extension}"),
        };
    }
    
    /**
     * Set chunk size
     */
    public function chunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }
    
    /**
     * Set transaction mode
     */
    public function withTransactions(bool $use = true): self
    {
        $this->useTransactions = $use;
        return $this;
    }
    
    /**
     * Set validation mode
     */
    public function withValidation(bool $validate = true): self
    {
        $this->validateBeforeImport = $validate;
        return $this;
    }
    
    /**
     * Create from template
     */
    public static function fromTemplate(string $template): self
    {
        $manager = new self();
        
        // Load predefined templates
        $templates = config('mint.import_templates', []);
        
        if (!isset($templates[$template])) {
            throw new \InvalidArgumentException("Import template not found: {$template}");
        }
        
        $config = $templates[$template];
        
        if (isset($config['mappings'])) {
            foreach ($config['mappings'] as $model => $mapping) {
                $manager->mapping($model, $mapping);
            }
        }
        
        if (isset($config['rules'])) {
            $manager->rules($config['rules']);
        }
        
        if (isset($config['transformers'])) {
            $manager->transformers($config['transformers']);
        }
        
        return $manager;
    }
}

class ImportResult
{
    protected string $format;
    protected string $source;
    protected array $headers = [];
    protected int $processed = 0;
    protected array $imported = [];
    protected array $errors = [];
    protected array $validationErrors = [];
    protected float $executionTime = 0;
    
    public function __construct(string $format, string $source)
    {
        $this->format = $format;
        $this->source = $source;
    }
    
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }
    
    public function setProcessed(int $count): void
    {
        $this->processed = $count;
    }
    
    public function incrementProcessed(): void
    {
        $this->processed++;
    }
    
    public function incrementImported(string $model): void
    {
        if (!isset($this->imported[$model])) {
            $this->imported[$model] = 0;
        }
        $this->imported[$model]++;
    }
    
    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }
    
    public function addValidationError(array $record, array $errors): void
    {
        $this->validationErrors[] = [
            'record' => $record,
            'errors' => $errors,
        ];
    }
    
    public function setExecutionTime(float $time): void
    {
        $this->executionTime = $time;
    }
    
    public function isSuccess(): bool
    {
        return empty($this->errors) && empty($this->validationErrors);
    }
    
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'source' => basename($this->source),
            'processed' => $this->processed,
            'imported' => $this->imported,
            'total_imported' => array_sum($this->imported),
            'errors' => count($this->errors),
            'validation_errors' => count($this->validationErrors),
            'execution_time' => round($this->executionTime, 2) . 's',
            'success' => $this->isSuccess(),
        ];
    }
}