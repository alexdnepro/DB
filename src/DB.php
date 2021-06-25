<?php

namespace Power;

use mysqli;
use mysqli_result;

/**
 * Class DB
 * @method static begin_transaction ($flags = 0 , $name = 0 )
 * @method static rollback ($flags = 0 , $name = 0 )
 * @method static commit ($flags = 0 , $name = 0 )
 */
class DB {
    private static $initialized = false;
    private static $connections = [];
    private static $configs = [];
    private static $current_instance = 0;
    /**
     * @var callable|null
     */
    private static $error_handler;
    /**
     * @var bool set it to call error when no data found on query
     */
    public static $fail_on_nodata = false;
	private static $last_query_time = [];
	/**
	 * @var int time to check connection alive from last query
	 */
	private static $ping_idle_time = 60;
    /**
     * @var array contains all queries statistics
     */
    protected static $stats = [];

	/**
	 * @var bool|string Log all sql queries to filename
	 */
	private static $log_sql = false;
	private static $log_sql_debug = false;
	/**
	 * @var bool|string Log all errors to filename
	 */
	private static $error_log = false;

    private function __construct () {}

    /**
     * Switch current instance to needed database
     * @param int $id
     */
    public static function Switch(int $id)
    {
        if ($id < 0 || $id > count(self::$connections) -1)
        {
            self::ShowError('Wrong DB id '.$id);
        }
        self::$current_instance = $id;
    }

    /**
     * Set error handler for any DB errors
     * @param callable $handler function($error_message)
     */
    public static function SetErrorHandler(callable $handler)
    {
        self::$error_handler = $handler;
    }

	public static function SetLogSql(string $file_name, bool $add_debug = false)
	{
		self::$log_sql = $file_name;
		self::$log_sql_debug = $add_debug;
	}

	public static function SetErrorLog(string $file_name)
	{
		self::$error_log = $file_name;
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
        self::$connections[] = null;
        self::$configs[] =
            [
                'mysql_host' => $mysql_host,
                'mysql_user' => $mysql_user,
                'mysql_pass' => $mysql_pass,
                'db_name' => $db_name,
                'charset' => $charset,
                'port' => $port,
                'socket' => $socket
            ];
        self::$initialized = true;
        $id = count(self::$connections) - 1;
        self::$last_query_time[$id] = time();
        return $id;
    }

	/**
	 * @return mysqli|null
	 */
	public static function instance()
    {
        self::ConnectBase();
        return self::$connections[self::$current_instance];
    }

    public static function __callStatic($method, $args)
    {
        return call_user_func_array(array(self::instance(), $method), $args);
    }

    private static function ConnectBase()
    {
        if (!self::$initialized)
        {
            self::ShowError('DB not initialized');
        }
        if (self::$connections[self::$current_instance] !== null) {
	        if (self::$last_query_time[self::$current_instance] + self::$ping_idle_time < time())
	        {
		        if (!self::$connections[self::$current_instance]->ping())
		        {
			        // Trying to reconnect
			        self::$connections[self::$current_instance] = null;
		        }
	        } else {
		        return;
	        }
        }
        self::$connections[self::$current_instance] = new mysqli(
            self::$configs[self::$current_instance]['mysql_host'],
            self::$configs[self::$current_instance]['mysql_user'],
            self::$configs[self::$current_instance]['mysql_pass'],
            self::$configs[self::$current_instance]['db_name'],
            self::$configs[self::$current_instance]['port'],
            self::$configs[self::$current_instance]['socket']);
        if (self::$connections[self::$current_instance]->connect_errno) {
            self::ShowError('Database connect error');
        }
        if (self::$configs[self::$current_instance]['charset'] !== null)
        {
            self::$connections[self::$current_instance]->set_charset(self::$configs[self::$current_instance]['charset']) or self::ShowError(self::$configs[self::$current_instance]->error);
        }
	    self::$last_query_time[self::$current_instance] = time();
    }

    /**
     * @param bool|string $msg
     */
    public static function ShowError($msg = false )
    {
        if (!$msg) {
            $msg = 'Database query error';
        }
        if (self::$error_handler)
        {
            $function = self::$error_handler;
            $function($msg);
        } else {
            error_log($msg);
        }
    }

    /**
     * Conventional function to run a query with placeholders. A mysqli_query wrapper with placeholders support
     *
     * Examples:
     * $db->query("DELETE FROM table WHERE id=?i", $id);
     *
     * @param string $sql - an SQL query with placeholders
     * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
     * @return mysqli_result whatever mysqli_query returns
     */
    public static function query(string $sql, ...$arg)
    {
        return self::rawQuery(self::prepareQuery(func_get_args()));
    }

    /**
     * Conventional function to fetch single row.
     *
     * @param bool|mysqli_result $result - mysqli result
     * @param int $mode - optional fetch mode
     * @return array|false whatever mysqli_fetch_array returns
     */
    public static function fetch($result, int $mode = MYSQLI_ASSOC)
    {
        return mysqli_fetch_array($result, $mode);
    }

    /**
     * Conventional function to get number of affected rows.
     *
     * @return int whatever mysqli_affected_rows returns
     */
    public static function affectedRows(): int
    {
        return mysqli_affected_rows (self::instance());
    }

    /**
     * Conventional function to get last insert id.
     *
     * @return int whatever mysqli_insert_id returns
     */
    public static function insertId(): int
    {
        return mysqli_insert_id(self::instance());
    }

    public static function num_rows($result)
    {
        return mysqli_num_rows($result);
    }

    public static function free($result)
    {
        mysqli_free_result($result);
    }

    /**
     * Helper function to get scalar value right out of query and optional arguments
     *
     * Examples:
     * $name = $db->getOne("SELECT name FROM table WHERE id=1");
     * $name = $db->getOne("SELECT name FROM table WHERE id=?i", $id);
     *
     * @param string $sql - an SQL query with placeholders
     * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
     * @return string|FALSE either first column of the first row of result set or FALSE if none found
     */
    public static function getOne(string $sql, ...$arg)
    {
        $query = self::prepareQuery(func_get_args());
        if ($res = self::rawQuery($query))
        {
            $num_rows = self::num_rows($res);
            if (self::$fail_on_nodata && !$num_rows) {
                self::ShowError('Query result empty');
            }
            if (!$num_rows) {
                return false;
            }
            $row = self::fetch($res);
            if (is_array($row)) {
                return reset($row);
            }
            self::free($res);
        }
        return false;
    }

    /**
     * Helper function to get single row right out of query and optional arguments
     *
     * Examples:
     * $data = $db->getRow("SELECT * FROM table WHERE id=1");
     * $data = $db->getRow("SELECT * FROM table WHERE id=?i", $id);
     *
     * @param string $sql - an SQL query with placeholders
     * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
     * @return array|FALSE either associative array contains first row of result set or FALSE if none found
     */
    public static function getRow(string $sql, ...$arg)
    {
        $query = self::prepareQuery(func_get_args());
        if ($res = self::rawQuery($query)) {
            $num_rows = self::num_rows($res);
            if (self::$fail_on_nodata && !$num_rows) {
                self::ShowError('Query result empty');
            }
            if (!$num_rows) {
                return false;
            }
            $ret = self::fetch($res);
            self::free($res);
            return $ret;
        }
        return false;
    }

    /**
     * Helper function to get single column right out of query and optional arguments
     *
     * Examples:
     * $ids = $db->getCol("SELECT id FROM table WHERE cat=1");
     * $ids = $db->getCol("SELECT id FROM tags WHERE tagname = ?s", $tag);
     *
     * @param string $sql - an SQL query with placeholders
     * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
     * @return array enumerated array of first fields of all rows of resultset or empty array if none found
     */
    public static function getCol(string $sql, ...$arg): array
    {
        $ret   = array();
        $query = self::prepareQuery(func_get_args());
        if ( $res = self::rawQuery($query) )
        {
            while($row = self::fetch($res))
            {
                $ret[] = reset($row);
            }
            self::free($res);
        }
        return $ret;
    }

    /**
     * Helper function to get all the rows of resultset right out of query and optional arguments
     *
     * Examples:
     * $data = $db->getAll("SELECT * FROM table");
     * $data = $db->getAll("SELECT * FROM table LIMIT ?i,?i", $start, $rows);
     *
     * @param string $sql - an SQL query with placeholders
     * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
     * @return array enumerated 2d array contains the resultset. Empty if no rows found.
     */
    public static function getAll(string $sql, ...$arg): array
    {
        $ret   = array();
        $query = self::prepareQuery(func_get_args());
        if ( $res = self::rawQuery($query) )
        {
            while($row = self::fetch($res))
            {
                $ret[] = $row;
            }
            self::free($res);
        }
        return $ret;
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
     * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
     * @return array - associative 2d array contains the resultset. Empty if no rows found.
     */
    public static function getInd(string $ind, string $sql, ...$arg): array
    {
        $args  = func_get_args();
        $index = array_shift($args);
        $query = self::prepareQuery($args);

        $ret = array();
        if ( $res = self::rawQuery($query) )
        {
            while($row = self::fetch($res))
            {
                $ret[$row[$index]] = $row;
            }
            self::free($res);
        }
        return $ret;
    }

    /**
     * Helper function to get a dictionary-style array right out of query and optional arguments
     *
     * Examples:
     * $data = $db->getIndCol("name", "SELECT name, id FROM cities");
     *
     * @param string $ind - name of the field which value is used to index resulting array
     * @param string $sql - an SQL query with placeholders
     * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
     * @return array - associative array contains key=value pairs out of resultset. Empty if no rows found.
     */
    public static function getIndCol(string $ind, string $sql, ...$arg): array
    {
        $args  = func_get_args();
        $index = array_shift($args);
        $query = self::prepareQuery($args);

        $ret = array();
        if ( $res = self::rawQuery($query) )
        {
            while($row = self::fetch($res))
            {
                $key = $row[$index];
                unset($row[$index]);
                $ret[$key] = reset($row);
            }
            self::free($res);
        }
        return $ret;
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
     * @param mixed  $arg,... unlimited number of arguments to match placeholders in the expression
     * @return string - initial expression with placeholders substituted with data.
     */
    public static function parse(string $sql, ...$arg): string
    {
        return self::prepareQuery(func_get_args());
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
     * @param string $input   - field name to test
     * @param  array  $allowed - an array with allowed variants
     * @param  string $default - optional variable to set if no match found. Default to false.
     * @return string|FALSE    - either sanitized value or FALSE
     */
    public static function whiteList(string $input, array $allowed, $default=false)
    {
        $found = array_search($input, $allowed, false);
        return ($found === false) ? $default : $allowed[$found];
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
     * @param  array $input   - source array
     * @param  array $allowed - an array with allowed field names
     * @return array filtered out source array
     */
    public static function filterArray(array $input, array $allowed): array
    {
        foreach(array_keys($input) as $key )
        {
            if ( !in_array($key, $allowed, false) )
            {
                unset($input[$key]);
            }
        }
        return $input;
    }

    /**
     * Function to get last executed query.
     *
     * @return string|NULL either last executed query or NULL if were none
     */
    public static function lastQuery()
    {
        $last = end(self::$stats);
        return $last['query'];
    }

    /**
     * Function to get all query statistics.
     *
     * @return array contains all executed queries with timings and errors
     */
    public static function getStats(): array
    {
        return self::$stats;
    }

    /**
     * protected function which actually runs a query against Mysql server.
     * also logs some stats like profiling info and error message
     *
     * @param string $query - a regular SQL query
     * @return bool|mysqli_result resource or FALSE on error
     */
    protected static function rawQuery(string $query)
    {
        self::ConnectBase();
	    self::$last_query_time[self::$current_instance] = time();
        if (self::$log_sql) {
            self::log_sql($query);
        }
        $start = microtime(TRUE);
        $res   = mysqli_query(self::instance(), $query);
        $timer = microtime(TRUE) - $start;

        self::$stats[] = array(
            'query' => $query,
            'start' => $start,
            'timer' => $timer,
            'rows' => 0
        );
        end(self::$stats);
        $key = key(self::$stats);
        if (!$res)
        {
            $error = mysqli_error(self::instance());
            self::$stats[$key]['error'] = $error;
            self::cutStats();

            self::log_error($query);
            self::ShowError();
        } else
        {
            self::$stats[$key]['rows'] = self::instance()->affected_rows;
        }
        self::cutStats();
        return $res;
    }

    public static function prepareQuery($args):string
    {
        self::ConnectBase();
        $query = '';
        $raw   = array_shift($args);
        $array = preg_split('~(\?[nsiuap])~u',$raw,null,PREG_SPLIT_DELIM_CAPTURE);
        $arguments_num  = count($args);
        $placeholders_num  = (int)floor(count($array) / 2);
        if ( $placeholders_num !== $arguments_num )
        {
            self::ShowError("Number of args ($arguments_num) doesn't match number of placeholders ($placeholders_num) in [$raw]");
            die();
        }

        foreach ($array as $i => $part)
        {
            if ( ($i % 2) === 0 )
            {
                $query .= $part;
                continue;
            }

            $value = array_shift($args);
            switch ($part)
            {
                case '?n':
                    $part = self::escapeIdent($value);
                    break;
                case '?s':
                    $part = self::escapeString($value);
                    break;
                case '?i':
                    $part = self::escapeInt($value);
                    break;
                case '?a':
                    $part = self::createIN($value);
                    break;
                case '?u':
                    $part = self::createSET($value);
                    break;
                case '?p':
                    $part = $value;
                    break;
            }
            $query .= $part;
        }
        return $query;
    }

    protected static function escapeInt($value)
    {
        if ($value === NULL)
        {
            return 'NULL';
        }
        if(!is_numeric($value))
        {
            self::ShowError( "Integer (?i) placeholder expects numeric value, ".gettype($value)." given");
            die();
        }
        if (is_float($value))
        {
            $value = number_format($value, 0, '.', ''); // may lose precision on big numbers
        }
        return $value;
    }

    public static function escapeString($value):string
    {
        if ($value === NULL)
        {
            return 'NULL';
        }
        self::ConnectBase();
        return	"'".mysqli_real_escape_string(self::instance(),$value)."'";
    }

    protected static function escapeIdent($value): string
    {
        if ($value)
        {
            return '`' .str_replace('`', '``',$value). '`';
        }
        self::ShowError('Empty value for identifier (?n) placeholder');
        return false;
    }

    protected static function createIN($data):string
    {
        if (!is_array($data))
        {
            self::ShowError('Value for IN (?a) placeholder should be array');
        }
        if (!$data)
        {
            return 'NULL';
        }
        $query = $comma = '';
        foreach ($data as $value)
        {
            $query .= $comma.self::escapeString($value);
            $comma  = ',';
        }
        return $query;
    }

    protected static function createSET($data):string
    {
        if (!is_array($data))
        {
            self::ShowError( 'SET (?u) placeholder expects array, ' .gettype($data). ' given');
        }
        if (!$data)
        {
            self::ShowError('Empty array for SET (?u) placeholder');
        }
        $query = $comma = '';
        foreach ($data as $key => $value)
        {
            if (is_array($value)) {
                $str = $value[0];
            } else {
                $str = self::escapeString($value);
            }
            $query .= $comma.self::escapeIdent($key).'='.$str;
            $comma  = ',';
        }
        return $query;
    }

    /**
     * On a long run we can eat up too much memory with mere statistics
     * Let's keep it at reasonable size, leaving only last 100 entries.
     */
    protected static function cutStats()
    {
        if ( count(self::$stats) > 500 )
        {
            reset(self::$stats);
            $first = key(self::$stats);
            unset(self::$stats[$first]);
        }
    }

    public static function GetError()
    {
        if (self::instance()->connect_errno > 0) {
            return self::instance()->connect_error;
        }
        if (self::instance()->errno > 0) {
            return self::instance()->error;
        }
        return false;
    }

    public static function GetDate(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function ParseArgs($args): string
    {
        $res = array();
        foreach ($args as $i => $val)
        {
            if (is_array($val)) {
                $v = $i.': '.self::ParseArgs($val);
            } else {
                $v = $i.': '.print_r($val, true);
            }
            $res[] = $v;
        }
        return '{'.implode(',',$res).'}';
    }

    private static function GetBacktraceInfo($max_id = 2, $skip_id = 2): string
    {
        $bt = debug_backtrace();
        $l = '';
        foreach ($bt as $i => $val)
        {
            if ($i < $skip_id) {
                continue;
            }
            if ($i > $max_id) {
                break;
            }
            if (isset($val['file'])) {
                $l .= sprintf('File: %s, Line: %s, Function: %s, Args: %s', $val['file'], $val['line'], $val['function'], self::ParseArgs($val['args'])) . "\n";
            }
        }
        return $l;
    }

    private static function log_sql($sql)
    {
        $dh = fopen (self::$log_sql, 'ab+');
        if ($dh)
        {
            fwrite($dh, self::GetDate().' '.$sql."\n");
            if (self::$log_sql_debug)
            {
                fwrite($dh, self::GetBacktraceInfo(2));
                fwrite($dh, "\n");
            }
            fclose($dh);
        }
    }

    private static function log_error($sql)
    {
        $msg = self::GetDate().' '.self::GetError()."\nQuery: ".$sql."\n".self::GetBacktraceInfo(10)."\n";
        if (self::$error_log === false)
        {
            error_log($msg);
            return;
        }
        $dh = fopen (self::$error_log, 'ab+');
        if ($dh)
        {
            fwrite($dh, $msg);
            fclose($dh);
        }
    }
}
