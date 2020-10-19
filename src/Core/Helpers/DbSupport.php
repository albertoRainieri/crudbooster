<?php


namespace Crocodic\CrudBooster\Core\Helpers;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait DbSupport
{
    public static function getListTable()
    {
        $database = config('database.connections.'.config('database.default').'.database');
        try {
            $tables = DB::select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.Tables WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = '".$database."'");
        } catch (\Exception $e) {
            $tables = [];
        }

        return $tables;
    }

    /**
     * @param $table
     * @return mixed|null
     */
    public static function findPrimaryKey($table)
    {
        $pk = DB::getDoctrineSchemaManager()->listTableDetails($table)->getPrimaryKey();
        if(!$pk) {
            return null;
        }
        return $pk->getColumns()[0];
    }

    public static function insert($table, $data = [])
    {
        if (! $data['created_at']) {
            if (Schema::hasColumn($table, 'created_at')) {
                $data['created_at'] = date('Y-m-d H:i:s');
            }
        }

        if (DB::table($table)->insert($data)) {
            return $data['id'];
        } else {
            return false;
        }
    }

    /**
     * @param $table
     * @param $id
     * @return \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Query\Builder|object|null
     */
    public static function first($table, $id)
    {
        if (is_array($id)) {
            return DB::table($table)->where($id)->first();
        } else {
            $pk = static::findPrimaryKey($table);
            return DB::table($table)->where($pk, $id)->first();
        }
    }

    /**
     * @param $table
     * @param $field
     * @return bool|\Illuminate\Contracts\Cache\Repository|mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Exception
     */
    public static function isColumnNULL($table, $field)
    {
        if (cache()->has('field_isNull_'.$table.'_'.$field)) {
            return cache()->get('field_isNull_'.$table.'_'.$field);
        }

        try {
            //MySQL & SQL Server
            $isNULL = DB::select(DB::raw("select IS_NULLABLE from INFORMATION_SCHEMA.COLUMNS where TABLE_NAME='$table' and COLUMN_NAME = '$field'"))[0]->IS_NULLABLE;
            $isNULL = ($isNULL == 'YES') ? true : false;
            cache()->forever('field_isNull_'.$table.'_'.$field, $isNULL);
        } catch (\Exception $e) {
            $isNULL = false;
            cache()->forever('field_isNull_'.$table.'_'.$field, $isNULL);
        }

        return $isNULL;
    }

    /**
     * @param $table
     * @param $column
     * @return string
     */
    public static function getColumnDataType($table, $column)
    {
        try {
            //MySQL & SQL Server
            $type = DB::select(DB::raw("select DATA_TYPE from INFORMATION_SCHEMA.COLUMNS where TABLE_NAME='$table' and COLUMN_NAME = '$column'"))[0]->DATA_TYPE;
        } catch (\Exception $e) {
            $type = 'varchar';
        }

        return $type;
    }

    /**
     * @param $parentTable
     * @param $childTable
     * @return string
     */
    public static function getForeignKey($parentTable, $childTable)
    {
        if (Schema::hasColumn($childTable, 'id_'.$parentTable)) {
            return 'id_'.$parentTable;
        } else {
            return $parentTable.'_id';
        }
    }

    /**
     * @param $column
     * @return false|string|null
     */
    public static function getTableForeignKey($column)
    {
        $table = null;
        if (substr($column, 0, 3) == 'id_') {
            $table = substr($column, 3);
        } elseif (substr($column, -3) == '_id') {
            $table = substr($column, 0, (strlen($column) - 3));
        }

        return $table;
    }

    /**
     * @param $column
     * @return bool|\Illuminate\Contracts\Cache\Repository|mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Exception
     */
    public static function isForeignKey($column)
    {
        if (substr($column, 0, 3) == 'id_') {
            $table = substr($column, 3);
        } elseif (substr($column, -3) == '_id') {
            $table = substr($column, 0, (strlen($column) - 3));
        } else {
            $table = null;
        }

        if (cache()->has('isForeignKey_'.$column)) {
            return cache()->get('isForeignKey_'.$column);
        } else {
            if ($table) {
                $hasTable = Schema::hasTable($table);
                if ($hasTable) {
                    cache()->forever('isForeignKey_'.$column, true);

                    return true;
                } else {
                    cache()->forever('isForeignKey_'.$column, false);

                    return false;
                }
            } else {
                return false;
            }
        }
    }

}