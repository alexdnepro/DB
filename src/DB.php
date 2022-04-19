<?php

namespace Power;

use mysqli_result;
use stdClass;

/**
 * Class DB
 * @package Power
 * @method static begin_transaction ($flags = 0 , $name = 0 )
 * @method static rollback ($flags = 0 , $name = 0 )
 * @method static commit ($flags = 0 , $name = 0 )
 * @method static prepare (string $query)
 */
class DB {
    private static $initialized = false;
    private static $connections = [];
    private static $current_instance = 0;

    private function __construct () {}

    private static function CheckInit()
    {
        if (!self::$initialized) {
            die('DB not initialized');
        }
    }

    /**
     * Switch current instance to needed database
     * @param int $id
     */
    public static function Switch(int $id)
    {
        self::CheckInit();
        if ($id < 0 || $id > count(self::$connections) -1)
        {
            self::instance()->ShowError('Wrong DB id '.$id);
        }
        self::$current_instance = $id;
    }

    /**
     * Set error handler for any DB errors
     * @param callable|string $handler function($error_message)
     */
    public static function SetErrorHandler($handler)
    {
        self::CheckInit();
        self::instance()->SetErrorHandler($handler);
    }

    public static function SetLogSql(string $file_name, bool $add_debug = false)
	{
        self::CheckInit();
        self::instance()->SetLogSql($file_name, $add_debug);
	}

    public static function SetErrorLog(string $file_name)
	{
        self::CheckInit();
        self::instance()->SetErrorLog($file_name);
	}

    /**
     * @param string $mysql_host Can be either a host name or an IP address
     * @param string $mysql_user The MySQL user name
     * @param string $mysql_pass If not provided or NULL, the MySQL server will attempt to authenticate the user against those user records which have no password only. This allows one username to be used with different permissions (depending on if a password as provided or not)
     * @param string|null $db_name [optional] If provided will specify the default database to be used when performing queries
     * @param string|null $charset [optional] Make set names query after connecting
     * @param int|null $port [optional] Specifies the port number to attempt to connect to the MySQL server
     * @param string|null $socket [optional] Specifies the socket or named pipe that should be used
     * @return int id of created instance
     */
    public static function Init(string $mysql_host, string $mysql_user, string $mysql_pass, string $db_name = null, string $charset = null, int $port = null, string $socket = null): int
    {
        self::$connections[] = new mDB($mysql_host, $mysql_user, $mysql_pass, $db_name, $charset, $port, $socket);
        self::$initialized = true;
        return count(self::$connections) - 1;
    }

    /**
     * @return mDB
     */
	public static function instance(): mDB
    {
        self::CheckInit();
        return self::$connections[self::$current_instance];
    }

    public static function __callStatic($method, $args)
    {
        return call_user_func_array(array(self::instance()->getInstance(), $method), $args);
    }

    /**
     * Conventional function to run a query with placeholders. A mysqli_query wrapper with placeholders support
     *
     * Examples:
     * $db->query("DELETE FROM table WHERE id=?i", $id);
     *
     * @param string $sql - an SQL query with placeholders
     * @param mixed $arg,... unlimited number of arguments to match placeholders in the query
     * @return bool|mysqli_result whatever mysqli_query returns
     */
    public static function query(string $sql, ...$arg)
    {
        return self::instance()->query($sql, ...$arg);
    }

    /**
     * Conventional function to get number of affected rows.
     *
     * @return int whatever mysqli_affected_rows returns
     */
    public static function affectedRows(): int
    {
        return self::instance()->affectedRows();
    }

    /**
     * Conventional function to get last insert id.
     *
     * @return int whatever mysqli_insert_id returns
     */
    public static function insertId(): int
    {
        return self::instance()->insertId();
    }

    /**
     * Helper function to get scalar value right out of query and optional arguments
     *
     * Examples:
     * $name = $db->getOne("SELECT name FROM table WHERE id=1");
     * $name = $db->getOne("SELECT name FROM table WHERE id=?i", $id);
     *
     * @param string $sql - an SQL query with placeholders
     * @param mixed $arg,... unlimited number of arguments to match placeholders in the query
     * @return string|bool either first column of the first row of result set or FALSE if none found
     */
    public static function getOne(string $sql, ...$arg)
    {
        return self::instance()->getOne($sql, ...$arg);
    }

    /**
     * Helper function to get single row right out of query and optional arguments
     *
     * Examples:
     * $data = $db->getRow("SELECT * FROM table WHERE id=1");
     * $data = $db->getRow("SELECT * FROM table WHERE id=?i", $id);
     *
     * @param string $sql - an SQL query with placeholders
     * @param mixed $arg,... unlimited number of arguments to match placeholders in the query
     * @return array|bool either associative array contains first row of result set or FALSE if none found
     */
    public static function getRow(string $sql, ...$arg)
    {
        return self::instance()->getRow($sql, ...$arg);
    }

    /**
     * Helper function to get single column right out of query and optional arguments
     *
     * Examples:
     * $ids = $db->getCol("SELECT id FROM table WHERE cat=1");
     * $ids = $db->getCol("SELECT id FROM tags WHERE tagname = ?s", $tag);
     *
     * @param string $sql - an SQL query with placeholders
     * @param mixed $arg,... unlimited number of arguments to match placeholders in the query
     * @return array enumerated array of first fields of all rows of resultset or empty array if none found
     */
    public static function getCol(string $sql, ...$arg): array
    {
        return self::instance()->getCol($sql, ...$arg);
    }

    /**
     * Helper function to get all the rows of resultset right out of query and optional arguments
     *
     * Examples:
     * $data = $db->getAll("SELECT * FROM table");
     * $data = $db->getAll("SELECT * FROM table LIMIT ?i,?i", $start, $rows);
     *
     * @param string $sql - an SQL query with placeholders
     * @param mixed $arg,... unlimited number of arguments to match placeholders in the query
     * @return array enumerated 2d array contains the resultset. Empty if no rows found.
     */
    public static function getAll(string $sql, ...$arg): array
    {
        return self::instance()->getAll($sql, ...$arg);
    }

    /**
     * Helper function to get all the rows of resultset into indexed array right out of query and optional arguments
     *
     * Examples:
     * $data = $db->getInd("id", "SELECT * FROM table");
     * $data = $db->getInd("id", "SELECT * FROM table LIMIT ?i,?i", $start, $rows);
     *
     * @param string $ind - name of the field which value is used to index resulting array
     * @param string $sql - an SQL query with placeholders
     * @param mixed $arg,... unlimited number of arguments to match placeholders in the query
     * @return array - associative 2d array contains the resultset. Empty if no rows found.
     */
    public static function getInd(string $ind, string $sql, ...$arg): array
    {
        return self::instance()->getInd($ind, $sql, ...$arg);
    }

    /**
     * Helper function to get a dictionary-style array right out of query and optional arguments
     *
     * Examples:
     * $data = $db->getIndCol("name", "SELECT name, id FROM cities");
     *
     * @param string $ind - name of the field which value is used to index resulting array
     * @param string $sql - an SQL query with placeholders
     * @param mixed $arg,... unlimited number of arguments to match placeholders in the query
     * @return array - associative array contains key=value pairs out of resultset. Empty if no rows found.
     */
    public static function getIndCol(string $ind, string $sql, ...$arg): array
    {
        return self::instance()->getIndCol($ind, $sql, ...$arg);
    }

    /**
     * Function to parse placeholders either in the full query or a query part
     * unlike native prepared statements, allows ANY query part to be parsed
     *
     * useful for debug
     * and EXTREMELY useful for conditional query building
     * like adding various query parts using loops, conditions, etc.
     * already parsed parts have to be added via ?p placeholder
     *
     * Examples:
     * $query = $db->parse("SELECT * FROM table WHERE foo=?s AND bar=?s", $foo, $bar);
     * echo $query;
     *
     * if ($foo) {
     *     $qpart = $db->parse(" AND foo=?s", $foo);
     * }
     * $data = $db->getAll("SELECT * FROM table WHERE bar=?s ?p", $bar, $qpart);
     *
     * @param string $sql - whatever expression contains placeholders
     * @param mixed $arg,... unlimited number of arguments to match placeholders in the expression
     * @return string - initial expression with placeholders substituted with data.
     */
    public static function parse(string $sql, ...$arg): string
    {
        return self::instance()->parse($sql, ...$arg);
    }

    /**
     * function to implement whitelisting feature
     * sometimes we can't allow a non-validated user-supplied data to the query even through placeholder
     * especially if it comes down to SQL OPERATORS
     *
     * Example:
     *
     * $order = $db->whiteList($_GET['order'], array('name','price'));
     * $dir   = $db->whiteList($_GET['dir'],   array('ASC','DESC'));
     * if (!$order || !dir) {
     *     throw new http404(); //non-expected values should cause 404 or similar response
     * }
     * $sql  = "SELECT * FROM table ORDER BY ?p ?p LIMIT ?i,?i"
     * $data = $db->getArr($sql, $order, $dir, $start, $per_page);
     *
     * @param string $input - field name to test
     * @param array $allowed - an array with allowed variants
     * @param string $default - optional variable to set if no match found. Default to false.
     * @return string|bool    - either sanitized value or FALSE
     */
    public static function whiteList(string $input, array $allowed, $default=false)
    {
        return self::instance()->whiteList($input, $allowed, $default);
    }

    /**
     * function to filter out arrays, for the whitelisting purposes
     * useful to pass entire super global to the INSERT or UPDATE query
     * OUGHT to be used for this purpose,
     * as there could be fields to which user should have no access to.
     *
     * Example:
     * $allowed = array('title','url','body','rating','term','type');
     * $data    = $db->filterArray($_POST,$allowed);
     * $sql     = "INSERT INTO ?n SET ?u";
     * $db->query($sql,$table,$data);
     *
     * @param array $input - source array
     * @param array $allowed - an array with allowed field names
     * @return array filtered out source array
     */
    public static function filterArray(array $input, array $allowed): array
    {
        return self::instance()->filterArray($input, $allowed);
    }

    /**
     * Function to get last executed query.
     *
     * @return string|NULL either last executed query or NULL if were none
     */
    public static function lastQuery()
    {
        return self::instance()->lastQuery();
    }

	/**
	 * Function to save query statistics
	 *
	 * @param bool $value
	 * @return void
	 */
	public static function SetSaveStats(bool $value = true)
	{
		self::instance()->SetSaveStats($value);
	}

    /**
     * Function to get all query statistics.
     *
     * @return array contains all executed queries with timings and errors
     */
    public static function getStats(): array
    {
        return self::instance()->getStats();
    }

    public static function escapeString($value):string
    {
        return self::instance()->escapeString($value);
    }

    public static function GetDate(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Insert data array into table
     *
     * Example:
     *
     * $data = [
     *      'date' => DB::pure('now()'),
     *      'name' => 'Test',
     *      'value' => 123
     * ];
     * $db->insert('users', $data);
     *
     * @param string $table_name
     * @param array $data
     * @param string $db_name
     * @return bool|mysqli_result
     */
    public static function insert(string $table_name, array $data, string $db_name = '')
    {
        return self::instance()->insert($table_name, $data, $db_name);
    }

    /**
     * Update data array in table
     *
     * Example:
     *
     * $data = [
     *      'date' => DB::pure('now()'),
     *      'name' => 'Test',
     *      'value' => 123
     * ];
     * $db->update('users', $data, DB::cond('id', '>', 3));
     *
     * @param string $table_name
     * @param array $data
     * @param string $where
     * @param string $db_name
     * @return bool|mysqli_result
     */
    public static function update(string $table_name, array $data, string $where = '', string $db_name = '')
    {
        return self::instance()->update($table_name, $data, $where, $db_name);
    }

    /**
     * This function used on data arrays to provide clean sql string without escaping as string
     *
     * @param string $sql
     * @return stdClass
     */
    public static function pure(string $sql): stdClass
    {
        $res = new stdClass();
        $res->sql = $sql;
        return $res;
    }

    /**
     * DONT'T USE - function in development
     * Make condition for where/having and other parameters
     *
     * Example:
     *
     * $where = [
     *      DB::cond('id', '>', 3),
     *      DB::cond('name', 'LIKE', 'Alex%')
     * ];
     *
     * @param string $field
     * @param string $operator
     * @param $value
     * @return string
     */
    public static function cond(string $field, string $operator, $value): string
    {
        $placeholder = is_numeric($value) ? '?i' : '?s';
        return self::instance()->parse('?n '.$operator.' '.$placeholder, $field, $value);
    }

}
