<?php

namespace Power;

class DBCacheQuery
{

    private $fields;
    private $table_name;
    private $insert_ignore;
    private $sql_cache = '';
    private $query_size_limit;

    public function __construct(string $table_name, array $field_names, bool $insert_ignore = false, int $query_size_limit = 250000)
    {
        $this->fields = $field_names;
        $this->table_name = $table_name;
        $this->insert_ignore = $insert_ignore;
        $this->query_size_limit = $query_size_limit;
    }

    public function Flush()
    {
        if ($this->sql_cache !== '') {
            DB::query('?p;', $this->sql_cache);
        }
        $this->sql_cache = '';
    }

    public function Add(array $values)
    {
        if (strlen($this->sql_cache) >= $this->query_size_limit) {
            $this->Flush();
        }
        $v = '';
        foreach ($values as $val) {
            if ($v !== '') {
                $v .= ',';
            }
            $v .= DB::escapeParam($val);
        }
        if ($this->sql_cache === '') {
            $f = '`' . implode('`,`', $this->fields) . '`';
            $this->sql_cache = 'INSERT ' . ($this->insert_ignore ? 'IGNORE ' : '') . 'INTO `' . $this->table_name . '` (' . $f . ') VALUES (' . $v . ')';
        } else {
            $this->sql_cache .= ', (' . $v . ')';
        }
    }

}