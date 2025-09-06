<?php

namespace LaravelMint\Export;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LaravelMint\Performance\StreamProcessor;
use League\Csv\Writer;
use ZipArchive;

class ExportManager
{
    protected array $models = [];
    protected array $fields = [];
    protected array $relations = [];
    protected array $conditions = [];
    protected int $chunkSize = 1000;
    protected bool $compress = false;
    protected StreamProcessor $streamProcessor;
    
    public function __construct()
    {
        $this->streamProcessor = new StreamProcessor();
    }
    
    /**
     * Export data to file
     */
    public function export(string $format, string $outputPath = null): ExportResult
    {
        $outputPath = $outputPath ?? $this->generateOutputPath($format);
        
        $result = match($format) {
            'csv' => $this->exportCsv($outputPath),
            'json' => $this->exportJson($outputPath),
            'excel', 'xlsx' => $this->exportExcel($outputPath),
            'sql' => $this->exportSql($outputPath),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
        
        if ($this->compress) {
            $compressedPath = $this->compressFile($outputPath);
            $result->setOutputPath($compressedPath);
        }
        
        return $result;
    }
    
    /**
     * Export to CSV
     */
    public function exportCsv(string $outputPath): ExportResult
    {
        $result = new ExportResult('csv', $outputPath);
        $startTime = microtime(true);
        
        try {
            $csv = Writer::createFromPath($outputPath, 'w+');
            $headerWritten = false;
            
            foreach ($this->models as $modelClass) {
                $query = $this->buildQuery($modelClass);
                $exported = 0;
                
                $query->chunk($this->chunkSize, function ($records) use ($csv, &$headerWritten, &$exported, $modelClass) {
                    foreach ($records as $record) {
                        $data = $this->extractData($record, $modelClass);
                        
                        if (!$headerWritten) {
                            $csv->insertOne(array_keys($data));
                            $headerWritten = true;
                        }
                        
                        $csv->insertOne(array_values($data));
                        $exported++;
                    }
                });
                
                $result->addExported($modelClass, $exported);
            }
        } catch (\Exception $e) {
            $result->addError('CSV export failed: ' . $e->getMessage());
        }
        
        $result->setExecutionTime(microtime(true) - $startTime);
        $result->setFileSize(file_exists($outputPath) ? filesize($outputPath) : 0);
        
        return $result;
    }
    
    /**
     * Export to JSON
     */
    public function exportJson(string $outputPath): ExportResult
    {
        $result = new ExportResult('json', $outputPath);
        $startTime = microtime(true);
        
        try {
            $handle = fopen($outputPath, 'w');
            fwrite($handle, "{\n");
            $firstModel = true;
            
            foreach ($this->models as $modelClass) {
                if (!$firstModel) {
                    fwrite($handle, ",\n");
                }
                $firstModel = false;
                
                $modelName = class_basename($modelClass);
                fwrite($handle, '  "' . $modelName . '": [');
                
                $query = $this->buildQuery($modelClass);
                $exported = 0;
                $firstRecord = true;
                
                $query->chunk($this->chunkSize, function ($records) use ($handle, &$firstRecord, &$exported, $modelClass) {
                    foreach ($records as $record) {
                        if (!$firstRecord) {
                            fwrite($handle, ",");
                        }
                        $firstRecord = false;
                        
                        $data = $this->extractData($record, $modelClass);
                        fwrite($handle, "\n    " . json_encode($data));
                        $exported++;
                    }
                });
                
                fwrite($handle, "\n  ]");
                $result->addExported($modelClass, $exported);
            }
            
            fwrite($handle, "\n}\n");
            fclose($handle);
        } catch (\Exception $e) {
            $result->addError('JSON export failed: ' . $e->getMessage());
        }
        
        $result->setExecutionTime(microtime(true) - $startTime);
        $result->setFileSize(file_exists($outputPath) ? filesize($outputPath) : 0);
        
        return $result;
    }
    
    /**
     * Export to Excel
     */
    public function exportExcel(string $outputPath): ExportResult
    {
        $result = new ExportResult('excel', $outputPath);
        $startTime = microtime(true);
        
        try {
            if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                throw new \RuntimeException('PhpSpreadsheet is not installed. Run: composer require phpoffice/phpspreadsheet');
            }
            
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheetIndex = 0;
            
            foreach ($this->models as $modelClass) {
                if ($sheetIndex > 0) {
                    $spreadsheet->createSheet();
                }
                
                $sheet = $spreadsheet->setActiveSheetIndex($sheetIndex);
                $sheet->setTitle(class_basename($modelClass));
                
                $query = $this->buildQuery($modelClass);
                $row = 1;
                $headerWritten = false;
                $exported = 0;
                
                $query->chunk($this->chunkSize, function ($records) use ($sheet, &$row, &$headerWritten, &$exported, $modelClass) {
                    foreach ($records as $record) {
                        $data = $this->extractData($record, $modelClass);
                        
                        if (!$headerWritten) {
                            $col = 1;
                            foreach (array_keys($data) as $header) {
                                $sheet->setCellValueByColumnAndRow($col, $row, $header);
                                $col++;
                            }
                            $row++;
                            $headerWritten = true;
                        }
                        
                        $col = 1;
                        foreach (array_values($data) as $value) {
                            $sheet->setCellValueByColumnAndRow($col, $row, $value);
                            $col++;
                        }
                        $row++;
                        $exported++;
                    }
                });
                
                $result->addExported($modelClass, $exported);
                $sheetIndex++;
            }
            
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($outputPath);
        } catch (\Exception $e) {
            $result->addError('Excel export failed: ' . $e->getMessage());
        }
        
        $result->setExecutionTime(microtime(true) - $startTime);
        $result->setFileSize(file_exists($outputPath) ? filesize($outputPath) : 0);
        
        return $result;
    }
    
    /**
     * Export to SQL
     */
    public function exportSql(string $outputPath): ExportResult
    {
        $result = new ExportResult('sql', $outputPath);
        $startTime = microtime(true);
        
        try {
            $handle = fopen($outputPath, 'w');
            
            // Write header
            fwrite($handle, "-- Laravel Mint SQL Export\n");
            fwrite($handle, "-- Generated at: " . now()->toDateTimeString() . "\n\n");
            
            foreach ($this->models as $modelClass) {
                $model = new $modelClass();
                $table = $model->getTable();
                
                fwrite($handle, "-- Table: {$table}\n");
                
                $query = $this->buildQuery($modelClass);
                $exported = 0;
                
                $query->chunk($this->chunkSize, function ($records) use ($handle, $table, &$exported) {
                    foreach ($records as $record) {
                        $columns = array_keys($record->toArray());
                        $values = array_map(function ($value) {
                            if (is_null($value)) {
                                return 'NULL';
                            }
                            if (is_numeric($value)) {
                                return $value;
                            }
                            return "'" . addslashes($value) . "'";
                        }, array_values($record->toArray()));
                        
                        $sql = sprintf(
                            "INSERT INTO `%s` (`%s`) VALUES (%s);\n",
                            $table,
                            implode('`, `', $columns),
                            implode(', ', $values)
                        );
                        
                        fwrite($handle, $sql);
                        $exported++;
                    }
                });
                
                fwrite($handle, "\n");
                $result->addExported($modelClass, $exported);
            }
            
            fclose($handle);
        } catch (\Exception $e) {
            $result->addError('SQL export failed: ' . $e->getMessage());
        }
        
        $result->setExecutionTime(microtime(true) - $startTime);
        $result->setFileSize(file_exists($outputPath) ? filesize($outputPath) : 0);
        
        return $result;
    }
    
    /**
     * Build query for model
     */
    protected function buildQuery(string $modelClass)
    {
        $query = $modelClass::query();
        
        // Apply conditions
        if (isset($this->conditions[$modelClass])) {
            foreach ($this->conditions[$modelClass] as $condition) {
                $query->where($condition['column'], $condition['operator'], $condition['value']);
            }
        }
        
        // Select specific fields
        if (isset($this->fields[$modelClass])) {
            $query->select($this->fields[$modelClass]);
        }
        
        // Include relations
        if (isset($this->relations[$modelClass])) {
            $query->with($this->relations[$modelClass]);
        }
        
        return $query;
    }
    
    /**
     * Extract data from model
     */
    protected function extractData(Model $record, string $modelClass): array
    {
        $data = [];
        
        // Get base attributes
        if (isset($this->fields[$modelClass])) {
            foreach ($this->fields[$modelClass] as $field) {
                $data[$field] = $record->$field;
            }
        } else {
            $data = $record->toArray();
        }
        
        // Add relation data
        if (isset($this->relations[$modelClass])) {
            foreach ($this->relations[$modelClass] as $relation) {
                if ($record->relationLoaded($relation)) {
                    $relationData = $record->$relation;
                    
                    if ($relationData instanceof Model) {
                        $data[$relation] = $relationData->toArray();
                    } elseif ($relationData instanceof \Illuminate\Support\Collection) {
                        $data[$relation] = $relationData->toArray();
                    }
                }
            }
        }
        
        // Flatten nested arrays for CSV/Excel
        return $this->flattenArray($data);
    }
    
    /**
     * Flatten nested arrays
     */
    protected function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            $newKey = $prefix ? "{$prefix}.{$key}" : $key;
            
            if (is_array($value) && !empty($value)) {
                if ($this->isAssociative($value)) {
                    $result = array_merge($result, $this->flattenArray($value, $newKey));
                } else {
                    $result[$newKey] = json_encode($value);
                }
            } else {
                $result[$newKey] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Check if array is associative
     */
    protected function isAssociative(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
    
    /**
     * Compress file
     */
    protected function compressFile(string $filepath): string
    {
        $zipPath = $filepath . '.zip';
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
            $zip->addFile($filepath, basename($filepath));
            $zip->close();
            
            // Remove original file
            unlink($filepath);
            
            return $zipPath;
        }
        
        return $filepath;
    }
    
    /**
     * Generate output path
     */
    protected function generateOutputPath(string $format): string
    {
        $timestamp = now()->format('Y-m-d_His');
        $filename = "mint_export_{$timestamp}.{$format}";
        
        return storage_path("app/exports/{$filename}");
    }
    
    /**
     * Add model to export
     */
    public function model(string $modelClass, array $fields = null, array $relations = null): self
    {
        $this->models[] = $modelClass;
        
        if ($fields) {
            $this->fields[$modelClass] = $fields;
        }
        
        if ($relations) {
            $this->relations[$modelClass] = $relations;
        }
        
        return $this;
    }
    
    /**
     * Add condition
     */
    public function where(string $modelClass, string $column, $operator, $value = null): self
    {
        if (!isset($this->conditions[$modelClass])) {
            $this->conditions[$modelClass] = [];
        }
        
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->conditions[$modelClass][] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];
        
        return $this;
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
     * Enable compression
     */
    public function compress(bool $compress = true): self
    {
        $this->compress = $compress;
        return $this;
    }
    
    /**
     * Create from template
     */
    public static function fromTemplate(string $template): self
    {
        $manager = new self();
        
        $templates = config('mint.export_templates', []);
        
        if (!isset($templates[$template])) {
            throw new \InvalidArgumentException("Export template not found: {$template}");
        }
        
        $config = $templates[$template];
        
        if (isset($config['models'])) {
            foreach ($config['models'] as $model => $settings) {
                $manager->model(
                    $model,
                    $settings['fields'] ?? null,
                    $settings['relations'] ?? null
                );
                
                if (isset($settings['conditions'])) {
                    foreach ($settings['conditions'] as $condition) {
                        $manager->where($model, ...$condition);
                    }
                }
            }
        }
        
        if (isset($config['chunk_size'])) {
            $manager->chunkSize($config['chunk_size']);
        }
        
        if (isset($config['compress'])) {
            $manager->compress($config['compress']);
        }
        
        return $manager;
    }
}

class ExportResult
{
    protected string $format;
    protected string $outputPath;
    protected array $exported = [];
    protected array $errors = [];
    protected float $executionTime = 0;
    protected int $fileSize = 0;
    
    public function __construct(string $format, string $outputPath)
    {
        $this->format = $format;
        $this->outputPath = $outputPath;
    }
    
    public function addExported(string $model, int $count): void
    {
        $this->exported[$model] = $count;
    }
    
    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }
    
    public function setExecutionTime(float $time): void
    {
        $this->executionTime = $time;
    }
    
    public function setFileSize(int $size): void
    {
        $this->fileSize = $size;
    }
    
    public function setOutputPath(string $path): void
    {
        $this->outputPath = $path;
    }
    
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }
    
    public function isSuccess(): bool
    {
        return empty($this->errors);
    }
    
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'output_path' => $this->outputPath,
            'exported' => $this->exported,
            'total_exported' => array_sum($this->exported),
            'file_size' => $this->formatBytes($this->fileSize),
            'execution_time' => round($this->executionTime, 2) . 's',
            'errors' => $this->errors,
            'success' => $this->isSuccess(),
        ];
    }
    
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}