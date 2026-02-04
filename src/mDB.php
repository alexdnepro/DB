<?php


namespace Power;

use mysqli;
use mysqli_result;
use mysqli_sql_exception;


/**
 * Class mDB
 * @package Power
 * This class is created for non-static code using
 * @method begin_transaction ($flags = 0 , $name = 0 )
 * @method rollback ($flags = 0 , $name = 0 )
 * @method commit ($flags = 0 , $name = 0 )
 * @method prepare (string $query)
 */
class mDB
{
	private $connected = false;
    /** @var array<string,mixed> */
    private $config;
	/** @var mysqli */
	private $instance;
    /** @var int unix timestamp */
	private $last_query_time;
	/**
	 * @var int seconds time to check connection alive from last query
	 */
	private $ping_idle_time = 60;
    /** @var callable|string|null */
	private $error_handler = null;
    /** @var bool set it to call error when no data found on query */
	public $fail_on_nodata = false;

    /** @var array<int,array<string,mixed>> contains all queries statistics */
	protected $stats = [];

    /** @var bool|string Log all sql queries to filename */
	private $log_sql = false;
	private $log_sql_debug = false;
    /** @var bool|string Log all errors to filename */
	private $error_log = false;
    /** @var bool Save query statistics */
	private $save_stats = false;
    /** @var int how many reconnect attempts */
    private $reconnect_attempts = 2;

    /** @var int microseconds sleep between reconnect attempts */
    private $reconnect_backoff_us = 150000;

    /**
     * Call standard methods from mysqli instance
     *
     * @param string $name
     * @param array $arguments
     * @return false|mixed
     */
    public function __call(string $name, array $arguments)
    {
        $this->ConnectBase();
        return $this->getInstance()->{$name}(...$arguments);
    }

	/**
	 * @param bool $value
	 * @return mDB
	 */
	public function SetSaveStats(bool $value = true): mDB
	{
		$this->save_stats = $value;
		return $this;
	}

	public function SetLogSql(string $file_name, bool $add_debug = false): mDB
	{
		$this->log_sql = $file_name;
		$this->log_sql_debug = $add_debug;
		return $this;
	}

	public function SetErrorLog(string $file_name): mDB
	{
		$this->error_log = $file_name;
		return $this;
	}

	/**
	 * Set error handler for any DB errors
	 * @param callable|string $handler function($error_message)
	 */
	public function SetErrorHandler($handler): mDB
	{
		$this->error_handler = $handler;
		return $this;
	}

	/**
	 * @param string $mysql_host Can be either a host name or an IP address
	 * @param string $mysql_user The MySQL user name
	 * @param string $mysql_pass If not provided or NULL, the MySQL server will attempt to authenticate the user against those user records which have no password only. This allows one username to be used with different permissions (depending on if a password as provided or not)
	 * @param string|null $db_name [optional] If provided will specify the default database to be used when performing queries
	 * @param string|null $charset [optional] Make set names query after connecting
	 * @param int|null $port [optional] Specifies the port number to attempt to connect to the MySQL server
	 * @param string|null $socket [optional] Specifies the socket or named pipe that should be used
	 */
	public function __construct(string $mysql_host, string $mysql_user, string $mysql_pass, string $db_name = null, string $charset = null, int $port = null, string $socket = null)
	{
		$this->config =
			[
				'mysql_host' => $mysql_host,
				'mysql_user' => $mysql_user,
				'mysql_pass' => $mysql_pass,
				'db_name' => $db_name,
				'charset' => $charset,
				'port' => $port,
				'socket' => $socket
			];
		$this->last_query_time = time();
	}

	public function getInstance(): mysqli
	{
		$this->ConnectBase();
        if ($this->instance === null) {
            // safety net
            $this->ShowError('Database instance is null', true);
            // create dummy to avoid type errors
            $this->instance = new mysqli();
        }
		return $this->instance;
	}

    /**
     * Main connect/reconnect routine.
     */
    private function ConnectBase()
    {
        // Fast path: connected and recently used
        if ($this->connected && $this->instance instanceof mysqli) {
            if (($this->last_query_time + $this->ping_idle_time) >= time()) {
                return;
            }

            // If idle for long, check that connection is alive
            try {
                // ping can throw if MYSQLI_REPORT_STRICT is enabled
                if (@$this->instance->ping()) {
                    return;
                }
            } catch (\Throwable $e) {
                // fallthrough to reconnect
            }

            $this->connected = false;
            $this->safeClose();
        }

        // Reconnect attempts
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->reconnect_attempts; $attempt++) {
            try {
                $this->instance = $this->createMysqli();
                $this->connected = true;
                $this->last_query_time = time();
                return;
            } catch (\Throwable $e) {
                $lastError = $e;

                // backoff before next attempt
                if ($attempt < $this->reconnect_attempts) {
                    usleep($this->reconnect_backoff_us);
                }
            }
        }

        $msg = 'Database connect error';
        if ($lastError) {
            $msg .= ': ' . $lastError->getMessage();
        }
        $this->ShowError($msg, true);
    }

    private function createMysqli(): mysqli
    {
        // If socket is configured, and it doesn't exist — это частая причина "No such file or directory"
        if (!empty($this->config['socket']) && is_string($this->config['socket'])) {
            if (!file_exists($this->config['socket'])) {
                // Not fatal by itself (mysql could be TCP), но очень полезно в логах
                $this->log_error('Socket not found: ' . $this->config['socket']);
            }
        }

        // Avoid global side effects: set report mode locally and restore
        $oldMode = mysqli_report(MYSQLI_REPORT_OFF);

        try {
            $mysqli = new mysqli(
                (string)$this->config['mysql_host'],
                (string)$this->config['mysql_user'],
                (string)$this->config['mysql_pass'],
                $this->config['db_name'],
                $this->config['port'],
                $this->config['socket']
            );

            if ($mysqli->connect_errno) {
                throw new \RuntimeException($mysqli->connect_error ?: 'Unknown connect error', $mysqli->connect_errno);
            }

            if (!empty($this->config['charset'])) {
                if (!$mysqli->set_charset((string)$this->config['charset'])) {
                    throw new \RuntimeException($mysqli->error ?: 'Failed to set charset');
                }
            }

            return $mysqli;
        } finally {
            mysqli_report($oldMode);
        }
    }

    private function safeClose()
    {
        try {
            if ($this->instance instanceof mysqli) {
                @$this->instance->close();
            }
        } catch (\Throwable $e) {
            // ignore
        } finally {
            $this->instance = null;
            $this->connected = false;
        }
    }

	/**
	 * @param bool|string $msg
	 */
	public function ShowError($msg = false, bool $add_error_log = false )
	{
		if ($add_error_log)
		{
			$this->log_error((string)$msg);
		}
		if (!$msg) {
			$msg = 'Database query error';
		}
		if ($this->error_handler)
		{
            $handler = $this->error_handler;
            $handler($msg);
            return;
		}
        error_log((string)$msg);
	}

	/**
	 * Conventional function to run a query with placeholders. A mysqli_query wrapper with placeholders support
	 *
	 * Examples:
	 * $db->query("DELETE FROM table WHERE id=?i", $id);
	 *
	 * @param string $sql - an SQL query with placeholders
	 * @param mixed  $arg,... unlimited number of arguments to match placeholders in the query
	 * @return bool|mysqli_result whatever mysqli_query returns
	 */
	public function query(string $sql, ...$arg)
	{
		return $this->rawQuery($this->prepareQuery(func_get_args()));
	}

	/**
	 * Conventional function to fetch single row.
	 *
	 * @param bool|mysqli_result $result - mysqli result
	 * @param int $mode - optional fetch mode
	 * @return array|bool whatever mysqli_fetch_array returns
	 */
	public function fetch($result, int $mode = MYSQLI_ASSOC)
	{
		return mysqli_fetch_array($result, $mode);
	}

	/**
	 * Conventional function to get number of affected rows.
	 *
	 * @return int whatever mysqli_affected_rows returns
	 */
	public function affectedRows(): int
	{
        $this->ConnectBase();
		return mysqli_affected_rows ($this->instance);
	}

	/**
	 * Conventional function to get last insert id.
	 *
	 * @return int whatever mysqli_insert_id returns
	 */
	public function insertId(): int
	{
        $this->ConnectBase();
		return mysqli_insert_id($this->instance);
	}

	public function num_rows($result)
	{
		return mysqli_num_rows($result);
	}

	public function free($result)
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
	 * @return string|bool either first column of the first row of resultset or FALSE if none found
	 */
	public function getOne(string $sql, ...$arg)
	{
		$query = $this->prepareQuery(func_get_args());
		if ($res = $this->rawQuery($query))
		{
			$num_rows = $this->num_rows($res);
			if ($this->fail_on_nodata && !$num_rows) {
				$this->ShowError('Query result empty');
			}
			if (!$num_rows) {
				return false;
			}
			$row = $this->fetch($res);
			if (is_array($row)) {
				return reset($row);
			}
			$this->free($res);
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
	 * @return array|bool either associative array contains first row of resultset or FALSE if none found
	 */
	public function getRow(string $sql, ...$arg)
	{
		$query = $this->prepareQuery(func_get_args());
		if ($res = $this->rawQuery($query)) {
			$num_rows = $this->num_rows($res);
			if ($this->fail_on_nodata && !$num_rows) {
				$this->ShowError('Query result empty');
			}
			if (!$num_rows) {
				return false;
			}
			$ret = $this->fetch($res);
			$this->free($res);
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
	public function getCol(string $sql, ...$arg): array
	{
		$ret   = array();
		$query = $this->prepareQuery(func_get_args());
		if ( $res = $this->rawQuery($query) )
		{
			while($row = $this->fetch($res))
			{
				$ret[] = reset($row);
			}
			$this->free($res);
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
	public function getAll(string $sql, ...$arg): array
	{
		$ret   = array();
		$query = $this->prepareQuery(func_get_args());
		if ( $res = $this->rawQuery($query) )
		{
			while($row = $this->fetch($res))
			{
				$ret[] = $row;
			}
			$this->free($res);
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
	public function getInd(string $ind, string $sql, ...$arg): array
	{
		$args  = func_get_args();
		$index = array_shift($args);
		$query = $this->prepareQuery($args);

		$ret = array();
		if ( $res = $this->rawQuery($query) )
		{
			while($row = $this->fetch($res))
			{
				$ret[$row[$index]] = $row;
			}
			$this->free($res);
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
	public function getIndCol(string $ind, string $sql, ...$arg): array
	{
		$args  = func_get_args();
		$index = array_shift($args);
		$query = $this->prepareQuery($args);

		$ret = array();
		if ( $res = $this->rawQuery($query) )
		{
			while($row = $this->fetch($res))
			{
				$key = $row[$index];
				unset($row[$index]);
				$ret[$key] = reset($row);
			}
			$this->free($res);
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
	public function parse(string $sql, ...$arg): string
	{
		return $this->prepareQuery(func_get_args());
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
	 * @param  string|bool $default - optional variable to set if no match found. Default to false.
	 * @return string|bool    - either sanitized value or FALSE
	 */
	public function whiteList(string $input, array $allowed, $default=false)
	{
		$found = array_search($input, $allowed, false);
		return ($found === false) ? $default : $allowed[$found];
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
    public function insert(string $table_name, array $data, string $db_name = '')
    {
        if ($db_name !== '')
        {
            return $this->query('INSERT INTO ?n.?n SET ?u', $db_name, $table_name, $data);
        }
        return $this->query('INSERT INTO ?n SET ?u', $table_name, $data);
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
     * $db->update('users', $data, 'id>5');
     *
     * @param string $table_name
     * @param array $data
     * @param string $where
     * @param string $db_name
     * @return bool|mysqli_result
     */
    public function update(string $table_name, array $data, string $where = '', string $db_name = '')
    {
        if ($db_name !== '')
        {
            return $this->query('UPDATE ?n.?n SET ?u'.($where !== '' ? ' WHERE '.$where : ''), $db_name, $table_name, $data);
        }
        return $this->query('UPDATE ?n SET ?u'.($where !== '' ? ' WHERE '.$where : ''), $table_name, $data);
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
	public function filterArray(array $input, array $allowed): array
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
	public function lastQuery()
	{
		if (!$this->save_stats)
		{
			return null;
		}
		$last = end($this->stats);
		return $last['query'] ?? null;
	}

	/**
	 * Function to get all query statistics.
	 *
	 * @return array contains all executed queries with timings and errors
	 */
	public function getStats(): array
	{
		return $this->stats;
	}

	/**
	 * protected function which actually runs a query against Mysql server.
	 * also logs some stats like profiling info and error message
	 *
	 * @param string $query - a regular SQL query
	 * @return bool|mysqli_result resource or FALSE on error
	 */
	protected function rawQuery(string $query)
	{
        if ($query === '') {
            return false;
        }
		$this->ConnectBase();
		$this->last_query_time = time();
		if ($this->log_sql !== false) {
			$this->log_sql($query);
		}
		$start = 0.0;
		if ($this->save_stats) {
			$start = microtime(TRUE);
		}
		$res = mysqli_query($this->getInstance(), $query);

        // If server dropped, attempt one reconnect and retry once
        if (!$res) {
            $errno = $this->getInstance()->errno;
            // 2006: MySQL server has gone away, 2013: Lost connection during query
            if ($errno === 2006 || $errno === 2013) {
                $this->connected = false;
                $this->safeClose();
                $this->ConnectBase();
                $res = mysqli_query($this->getInstance(), $query);
            }
        }

		if ($this->save_stats)
		{
			$timer = microtime(TRUE) - $start;

			$this->stats[] = array(
				'query' => $query,
				'start' => $start,
				'timer' => $timer,
				'rows' => 0
			);
			end($this->stats);
			$key = key($this->stats);
			if (!$res) {
                $this->stats[$key]['error'] = mysqli_error($this->getInstance());
			} else {
                $this->stats[$key]['rows'] = $this->getInstance()->affected_rows;
			}
			$this->cutStats();
		}
		if (!$res) {
            $err = $this->GetError();
			$this->log_error($query);
			$this->ShowError($err ? ('Database query error: ' . $err) : 'Database query error');
		}
		return $res;
	}

	public function prepareQuery($args):string
	{
		$this->ConnectBase();
		$query = '';
		$raw   = array_shift($args);
		$array = preg_split('~(\?[nsiuap])~u',$raw,-1,PREG_SPLIT_DELIM_CAPTURE);
		$arguments_num  = count($args);
		$placeholders_num  = (int)floor(count($array) / 2);
		if ( $placeholders_num !== $arguments_num )
		{
			$this->ShowError("Number of args ($arguments_num) doesn't match number of placeholders ($placeholders_num) in [$raw]", true);
			return '';
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
					$part = $this->escapeIdent($value);
					break;
				case '?s':
					$part = $this->escapeString($value);
					break;
				case '?i':
					$part = $this->escapeInt($value);
					break;
				case '?a':
					$part = $this->createIN($value);
					break;
				case '?u':
					$part = $this->createSET($value);
					break;
				case '?p':
					$part = $value;
					break;
			}
			$query .= $part;
		}
		return $query;
	}

	protected function escapeInt($value)
	{
		if ($value === NULL)
		{
			return 'NULL';
		}
		if(!is_numeric($value))
		{
			$this->ShowError( "Integer (?i) placeholder expects numeric value, ".gettype($value)." given", true);
			return '';
		}
		if (is_float($value))
		{
			$value = number_format($value, 16, '.', ''); // may lose precision on big numbers
		}
        if (strpos($value, 'e') !== false)
        {
            // Если найден символ 'e' экранируем как строку для избежания багов
            return $this->escapeString($value);
        }
		return $value;
	}

	public function escapeString($value):string
	{
		if ($value === NULL)
		{
			return 'NULL';
		}
		$this->ConnectBase();
		return	"'".mysqli_real_escape_string($this->getInstance(), $value)."'";
	}

	protected function escapeIdent($value): string
	{
		if ($value)
		{
			return '`' .str_replace('`', '``',$value). '`';
		}
		$this->ShowError('Empty value for identifier (?n) placeholder', true);
		return false;
	}

	protected function createIN($data):string
	{
		if (!is_array($data))
		{
			$this->ShowError('Value for IN (?a) placeholder should be array', true);
		}
		if (!$data)
		{
			return 'NULL';
		}
        $parts = [];
        foreach ($data as $value) {
            if (is_int($value) || is_float($value)) {
                $parts[] = $this->escapeInt($value);
            } else {
                $parts[] = $this->escapeString($value);
            }
        }

        return implode(',', $parts);
	}

	protected function createSET($data):string
	{
		if (!is_array($data))
		{
			$this->ShowError( 'SET (?u) placeholder expects array, ' .gettype($data). ' given', true);
		}
		if (!$data)
		{
			$this->ShowError('Empty array for SET (?u) placeholder', true);
		}
        $parts = [];
        foreach ($data as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $str = $this->escapeInt($value);
            } elseif (is_object($value) && isset($value->sql)) {
                $str = (string)$value->sql; // DB::pure
            } else {
                $str = $this->escapeString($value);
            }
            $parts[] = $this->escapeIdent($key) . '=' . $str;
        }

        return implode(',', $parts);
	}

	/**
	 * On a long run we can eat up too much memory with mere statistics
	 * Let's keep it at reasonable size, leaving only last 100 entries.
	 */
	protected function cutStats()
	{
		if ( $this->save_stats && count($this->stats) > 100 )
		{
			reset($this->stats);
			$first = key($this->stats);
			unset($this->stats[$first]);
		}
	}

	/**
	 * Returns current error or false
	 * @return string|false
	 */
	public function GetError()
	{
        if (!($this->instance instanceof mysqli)) {
            return false;
        }
        if ($this->instance->connect_errno > 0) {
            return $this->instance->connect_error;
        }
        if ($this->instance->errno > 0) {
            return $this->instance->error;
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

	private static function GetBacktraceInfo($max_id = 2): string
	{
        $bt = debug_backtrace();
        $l = '';
        foreach ($bt as $i => $val) {
            if ($i < 2) {
                continue;
            }
            if ($i > $max_id) {
                break;
            }
            if (isset($val['file'])) {
                $l .= sprintf(
                        'File: %s, Line: %s, Function: %s, Args: %s',
                        $val['file'],
                        $val['line'] ?? '',
                        $val['function'] ?? '',
                        self::ParseArgs($val['args'] ?? [])
                    ) . "\n";
            }
        }
        return $l;
	}

	private function log_sql($sql)
	{
        if ($this->log_sql === false) {
            return;
        }
		$msg = self::GetDate().' '.$sql.PHP_EOL;
		if ($this->log_sql_debug)
		{
			$msg .= self::GetBacktraceInfo().PHP_EOL;
		}
		@file_put_contents((string)$this->log_sql, $msg, FILE_APPEND);
	}

	private function log_error($sql)
	{
        $err = (string)$this->GetError();
        $msg = self::GetDate() . ' ' . $err . PHP_EOL .
            'Query: ' . $sql . PHP_EOL .
            self::GetBacktraceInfo(10) . PHP_EOL;

        if ($this->error_log === false) {
            error_log($msg);
            return;
        }

        @file_put_contents((string)$this->error_log, $msg, FILE_APPEND);
	}
}