<?php

namespace LaravelMint\Analyzers;

use LaravelMint\Mint;

class SchemaInspector
{
    protected Mint $mint;

    protected array $cache = [];

    public function __construct(Mint $mint)
    {
        $this->mint = $mint;
    }

    public function inspect(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class {$modelClass} does not exist");
        }

        $instance = new $modelClass;
        $table = $instance->getTable();
        $connection = $this->mint->getConnection();

        return [
            'table' => $table,
            'columns' => $this->getColumns($table, $connection),
            'indexes' => $this->getIndexes($table, $connection),
            'foreign_keys' => $this->getForeignKeys($table, $connection),
            'primary_key' => $this->getPrimaryKey($table, $connection),
            'engine' => $this->getTableEngine($table, $connection),
            'collation' => $this->getTableCollation($table, $connection),
            'row_count' => $this->getRowCount($table, $connection),
        ];
    }

    protected function getColumns(string $table, $connection): array
    {
        $columns = [];
        $schemaBuilder = $connection->getSchemaBuilder();
        $columnNames = $schemaBuilder->getColumnListing($table);

        foreach ($columnNames as $columnName) {
            $columns[$columnName] = $this->getColumnDetails($table, $columnName, $connection);
        }

        return $columns;
    }

    protected function getColumnDetails(string $table, string $column, $connection): array
    {
        $schemaBuilder = $connection->getSchemaBuilder();
        $driverName = $connection->getDriverName();

        // Get basic column type
        $columnType = $schemaBuilder->getColumnType($table, $column);

        // Get detailed information based on database driver
        $details = [
            'type' => $columnType,
            'nullable' => true,
            'default' => null,
            'length' => null,
            'unsigned' => false,
            'auto_increment' => false,
            'comment' => null,
            'unique' => false,
        ];

        try {
            if ($driverName === 'mysql') {
                $details = array_merge($details, $this->getMySQLColumnDetails($table, $column, $connection));
            } elseif ($driverName === 'pgsql') {
                $details = array_merge($details, $this->getPostgreSQLColumnDetails($table, $column, $connection));
            } elseif ($driverName === 'sqlite') {
                $details = array_merge($details, $this->getSQLiteColumnDetails($table, $column, $connection));
            }
        } catch (\Exception $e) {
            // Fallback to basic details if driver-specific query fails
        }

        // Check if column is part of a unique index
        $indexes = $this->getIndexes($table, $connection);
        foreach ($indexes as $index) {
            if ($index['unique'] && count($index['columns']) === 1 && in_array($column, $index['columns'])) {
                $details['unique'] = true;
                break;
            }
        }

        // Infer data generation hints
        $details['generation_hints'] = $this->inferGenerationHints($column, $details);

        return $details;
    }

    protected function getMySQLColumnDetails(string $table, string $column, $connection): array
    {
        $database = $connection->getDatabaseName();

        $result = $connection->selectOne('
            SELECT 
                COLUMN_TYPE as column_type,
                IS_NULLABLE as is_nullable,
                COLUMN_DEFAULT as column_default,
                CHARACTER_MAXIMUM_LENGTH as max_length,
                NUMERIC_PRECISION as numeric_precision,
                NUMERIC_SCALE as numeric_scale,
                EXTRA as extra,
                COLUMN_COMMENT as comment
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? 
                AND TABLE_NAME = ? 
                AND COLUMN_NAME = ?
        ', [$database, $table, $column]);

        if (! $result) {
            return [];
        }

        return [
            'nullable' => $result->is_nullable === 'YES',
            'default' => $result->column_default,
            'length' => $result->max_length,
            'precision' => $result->numeric_precision,
            'scale' => $result->numeric_scale,
            'unsigned' => str_contains($result->column_type, 'unsigned'),
            'auto_increment' => str_contains($result->extra, 'auto_increment'),
            'comment' => $result->comment,
            'full_type' => $result->column_type,
        ];
    }

    protected function getPostgreSQLColumnDetails(string $table, string $column, $connection): array
    {
        $result = $connection->selectOne('
            SELECT 
                pg_catalog.format_type(a.atttypid, a.atttypmod) as data_type,
                a.attnotnull as not_null,
                pg_get_expr(d.adbin, d.adrelid) as default_value,
                col_description(pgc.oid, a.attnum) as comment
            FROM pg_catalog.pg_attribute a
            JOIN pg_catalog.pg_class pgc ON pgc.oid = a.attrelid
            LEFT JOIN pg_catalog.pg_attrdef d ON (a.attrelid, a.attnum) = (d.adrelid, d.adnum)
            WHERE pgc.relname = ? 
                AND a.attname = ?
                AND a.attnum > 0 
                AND NOT a.attisdropped
        ', [$table, $column]);

        if (! $result) {
            return [];
        }

        return [
            'nullable' => ! $result->not_null,
            'default' => $result->default_value,
            'comment' => $result->comment,
            'full_type' => $result->data_type,
        ];
    }

    protected function getSQLiteColumnDetails(string $table, string $column, $connection): array
    {
        $tableInfo = $connection->select("PRAGMA table_info({$table})");

        foreach ($tableInfo as $columnInfo) {
            if ($columnInfo->name === $column) {
                return [
                    'nullable' => ! $columnInfo->notnull,
                    'default' => $columnInfo->dflt_value,
                    'primary' => (bool) $columnInfo->pk,
                    'full_type' => $columnInfo->type,
                ];
            }
        }

        return [];
    }

    protected function getIndexes(string $table, $connection): array
    {
        $schemaBuilder = $connection->getSchemaBuilder();
        $indexes = [];

        try {
            $driverName = $connection->getDriverName();

            if ($driverName === 'mysql') {
                $rawIndexes = $connection->select("SHOW INDEX FROM `{$table}`");
                foreach ($rawIndexes as $index) {
                    $indexName = $index->Key_name;
                    if (! isset($indexes[$indexName])) {
                        $indexes[$indexName] = [
                            'name' => $indexName,
                            'columns' => [],
                            'unique' => ! $index->Non_unique,
                            'primary' => $indexName === 'PRIMARY',
                        ];
                    }
                    $indexes[$indexName]['columns'][] = $index->Column_name;
                }
            } elseif ($driverName === 'pgsql') {
                $rawIndexes = $connection->select('
                    SELECT 
                        i.relname AS index_name,
                        a.attname AS column_name,
                        ix.indisunique AS is_unique,
                        ix.indisprimary AS is_primary
                    FROM pg_class t
                    JOIN pg_index ix ON t.oid = ix.indrelid
                    JOIN pg_class i ON i.oid = ix.indexrelid
                    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                    WHERE t.relname = ?
                ', [$table]);

                foreach ($rawIndexes as $index) {
                    $indexName = $index->index_name;
                    if (! isset($indexes[$indexName])) {
                        $indexes[$indexName] = [
                            'name' => $indexName,
                            'columns' => [],
                            'unique' => $index->is_unique,
                            'primary' => $index->is_primary,
                        ];
                    }
                    $indexes[$indexName]['columns'][] = $index->column_name;
                }
            }
        } catch (\Exception $e) {
            // Return empty if index query fails
        }

        return array_values($indexes);
    }

    protected function getForeignKeys(string $table, $connection): array
    {
        $foreignKeys = [];
        $driverName = $connection->getDriverName();

        try {
            if ($driverName === 'mysql') {
                $database = $connection->getDatabaseName();
                $rawForeignKeys = $connection->select('
                    SELECT 
                        CONSTRAINT_NAME as name,
                        COLUMN_NAME as column,
                        REFERENCED_TABLE_NAME as foreign_table,
                        REFERENCED_COLUMN_NAME as foreign_column
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = ? 
                        AND TABLE_NAME = ?
                        AND REFERENCED_TABLE_NAME IS NOT NULL
                ', [$database, $table]);

                foreach ($rawForeignKeys as $fk) {
                    $foreignKeys[] = [
                        'name' => $fk->name,
                        'column' => $fk->column,
                        'foreign_table' => $fk->foreign_table,
                        'foreign_column' => $fk->foreign_column,
                        'on_delete' => 'RESTRICT', // Would need additional query for exact action
                        'on_update' => 'RESTRICT',
                    ];
                }
            } elseif ($driverName === 'pgsql') {
                $rawForeignKeys = $connection->select("
                    SELECT
                        tc.constraint_name as name,
                        kcu.column_name as column,
                        ccu.table_name AS foreign_table,
                        ccu.column_name AS foreign_column,
                        rc.delete_rule as on_delete,
                        rc.update_rule as on_update
                    FROM information_schema.table_constraints AS tc
                    JOIN information_schema.key_column_usage AS kcu
                        ON tc.constraint_name = kcu.constraint_name
                        AND tc.table_schema = kcu.table_schema
                    JOIN information_schema.constraint_column_usage AS ccu
                        ON ccu.constraint_name = tc.constraint_name
                        AND ccu.table_schema = tc.table_schema
                    JOIN information_schema.referential_constraints AS rc
                        ON rc.constraint_name = tc.constraint_name
                    WHERE tc.constraint_type = 'FOREIGN KEY' 
                        AND tc.table_name = ?
                ", [$table]);

                foreach ($rawForeignKeys as $fk) {
                    $foreignKeys[] = [
                        'name' => $fk->name,
                        'column' => $fk->column,
                        'foreign_table' => $fk->foreign_table,
                        'foreign_column' => $fk->foreign_column,
                        'on_delete' => $fk->on_delete,
                        'on_update' => $fk->on_update,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Return empty if foreign key query fails
        }

        return $foreignKeys;
    }

    protected function getPrimaryKey(string $table, $connection): ?array
    {
        $indexes = $this->getIndexes($table, $connection);

        foreach ($indexes as $index) {
            if ($index['primary']) {
                return [
                    'columns' => $index['columns'],
                    'name' => $index['name'],
                ];
            }
        }

        return null;
    }

    protected function getTableEngine(string $table, $connection): ?string
    {
        if ($connection->getDriverName() === 'mysql') {
            $result = $connection->selectOne('SHOW TABLE STATUS WHERE Name = ?', [$table]);

            return $result->Engine ?? null;
        }

        return null;
    }

    protected function getTableCollation(string $table, $connection): ?string
    {
        if ($connection->getDriverName() === 'mysql') {
            $result = $connection->selectOne('SHOW TABLE STATUS WHERE Name = ?', [$table]);

            return $result->Collation ?? null;
        }

        return null;
    }

    protected function getRowCount(string $table, $connection): int
    {
        try {
            return $connection->table($table)->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function inferGenerationHints(string $column, array $details): array
    {
        $hints = [];
        $type = $details['type'] ?? '';
        $columnLower = strtolower($column);

        // Infer from column name
        if (str_contains($columnLower, 'email')) {
            $hints['faker'] = 'email';
        } elseif (str_contains($columnLower, 'phone')) {
            $hints['faker'] = 'phoneNumber';
        } elseif (str_contains($columnLower, 'name')) {
            if (str_contains($columnLower, 'first')) {
                $hints['faker'] = 'firstName';
            } elseif (str_contains($columnLower, 'last')) {
                $hints['faker'] = 'lastName';
            } else {
                $hints['faker'] = 'name';
            }
        } elseif (str_contains($columnLower, 'address')) {
            $hints['faker'] = 'address';
        } elseif (str_contains($columnLower, 'city')) {
            $hints['faker'] = 'city';
        } elseif (str_contains($columnLower, 'country')) {
            $hints['faker'] = 'country';
        } elseif (str_contains($columnLower, 'zip') || str_contains($columnLower, 'postal')) {
            $hints['faker'] = 'postcode';
        } elseif (str_contains($columnLower, 'url') || str_contains($columnLower, 'website')) {
            $hints['faker'] = 'url';
        } elseif (str_contains($columnLower, 'description') || str_contains($columnLower, 'bio')) {
            $hints['faker'] = 'paragraph';
        } elseif (str_contains($columnLower, 'title')) {
            $hints['faker'] = 'sentence';
        } elseif (str_contains($columnLower, 'price') || str_contains($columnLower, 'amount')) {
            $hints['faker'] = 'randomFloat';
            $hints['params'] = [2, 0, 99999];
        } elseif (str_contains($columnLower, 'uuid')) {
            $hints['faker'] = 'uuid';
        }

        // Infer from column type
        if (str_contains($type, 'int')) {
            $hints['type'] = 'integer';
            if ($details['unsigned'] ?? false) {
                $hints['min'] = 0;
            }
        } elseif (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            $hints['type'] = 'float';
            $hints['precision'] = $details['precision'] ?? 10;
            $hints['scale'] = $details['scale'] ?? 2;
        } elseif (str_contains($type, 'bool')) {
            $hints['type'] = 'boolean';
        } elseif (str_contains($type, 'date')) {
            $hints['type'] = 'date';
            if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
                $hints['type'] = 'datetime';
            }
        } elseif (str_contains($type, 'text')) {
            $hints['type'] = 'text';
            $hints['faker'] = $hints['faker'] ?? 'paragraph';
        } elseif (str_contains($type, 'json')) {
            $hints['type'] = 'json';
        }

        // Length constraints
        if ($details['length'] ?? null) {
            $hints['max_length'] = $details['length'];
        }

        return $hints;
    }
}
