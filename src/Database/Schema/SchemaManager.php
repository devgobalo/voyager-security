<?php

namespace TCG\Voyager\Database\Schema;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table as DoctrineTable;
use Illuminate\Support\Facades\DB;
use TCG\Voyager\Database\Types\Type;
use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Platforms\SqlitePlatform;

abstract class SchemaManager
{
    protected static ?DoctrineConnection $doctrine = null;

    public static function __callStatic($method, $args)
    {
        return static::manager()->$method(...$args);
    }

    protected static function doctrine(): DoctrineConnection
    {
        if (static::$doctrine) {
            return static::$doctrine;
        }

        $laravelConnection = DB::connection();

        $config = [
            'dbname'   => $laravelConnection->getDatabaseName(),
            'user'     => $laravelConnection->getConfig('username'),
            'password' => $laravelConnection->getConfig('password'),
            'host'     => $laravelConnection->getConfig('host'),
            'driver'   => 'pdo_mysql',
            'charset'  => 'utf8mb4',
        ];

        static::$doctrine = DriverManager::getConnection($config);

        return static::$doctrine;
    }

    public static function getDatabasePlatform()
    {
        return static::doctrine()->getDatabasePlatform();
    }

    public static function manager(): AbstractSchemaManager
    {
        return static::doctrine()->createSchemaManager();
    }

    public static function tableExists($table)
    {
        if (!is_array($table)) {
            $table = [$table];
        }

        return static::manager()->tablesExist($table);
    }

    public static function listTables()
    {
        $tables = [];

        foreach (static::manager()->listTableNames() as $tableName) {
            $tables[$tableName] = static::listTableDetails($tableName);
        }

        return $tables;
    }

    public static function listTableDetails($tableName)
    {
        $schemaManager = static::manager();
        $platform = static::getDatabasePlatform();
    
        $columns = $schemaManager->listTableColumns($tableName);
    
        $foreignKeys = [];
        if (!$platform instanceof SqlitePlatform) {
            $foreignKeys = $schemaManager->listTableForeignKeys($tableName);
        }
    
        $indexes = $schemaManager->listTableIndexes($tableName);
    
        return new Table($tableName, $columns, $indexes, [], $foreignKeys, []);
    }

    public static function describeTable($tableName)
    {
        Type::registerCustomPlatformTypes();

        $table = static::listTableDetails($tableName);

        return collect($table->columns)->map(function ($column) use ($table) {
            $columnArr = Column::toArray($column);

            $columnArr['field'] = $columnArr['name'];
            $columnArr['type'] = $columnArr['type']['name'];

            $columnArr['indexes'] = [];
            $columnArr['key'] = null;
            if ($columnArr['indexes'] = $table->getColumnsIndexes($columnArr['name'], true)) {
                foreach ($columnArr['indexes'] as $name => $index) {
                    $columnArr['indexes'][$name] = Index::toArray($index);
                }

                $indexType = array_values($columnArr['indexes'])[0]['type'];
                $columnArr['key'] = substr($indexType, 0, 3);
            }

            return $columnArr;
        });
    }

    public static function listTableColumnNames($tableName)
    {
        Type::registerCustomPlatformTypes();

        $columnNames = [];

        foreach (static::manager()->listTableColumns($tableName) as $column) {
            $columnNames[] = $column->getName();
        }

        return $columnNames;
    }

    public static function createTable($table)
    {
        if (!($table instanceof DoctrineTable)) {
            $table = Table::make($table);
        }

        static::manager()->createTable($table);
    }

    public static function getDoctrineTable($table)
    {
        $table = trim($table);

        if (!static::tableExists($table)) {
            throw SchemaException::tableDoesNotExist($table);
        }

        return static::manager()->listTableDetails($table);
    }

    public static function getDoctrineColumn($table, $column)
    {
        return static::getDoctrineTable($table)->getColumn($column);
    }
}
