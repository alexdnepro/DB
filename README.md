Simple static class for working with MySql databases
* **PHP version:** 7.0+
* **Composer:** `composer require power/db`
* This code was taken as a basis https://github.com/colshrapnel/safemysql
#### Work with one database
```php
use \Power\DB;

DB::Init('localhost', 'users', 'passwd', 'dbname', 'utf8mb4');

// Get one row with some id
$id = 5;
DB::getRow('SELECT * FROM `users` WHERE `id`=?i', $id);

// Get all rows
$login = 'tester';
DB::getAll('SELECT * FROM `logs` WHERE `login`=?s', $login);

// Get one value
DB::getOne('SELECT count(*) FROM `users`');

// Insert some data
$table_name = 'logs';
$data = [
    'create_date' => ['now()'],  // when the value is an array it will not be escaped, useful for functions or someone else code
    'login' => 'tester',
    'userid' => 5
];
DB::query('INSERT INTO ?n SET ?u', $table_name, $data);
// Get inserted id from last query
echo DB::insertId();
```

#### Work with several databases
```php
use \Power\DB;

$db1 = DB::Init('localhost', 'users', 'passwd', 'dbname', 'utf8mb4');
$db2 = DB::Init('localhost', 'users1', 'passwd1', 'dbname1', 'utf8mb4');

// After initializing, first DB is selected for work
// Get associated array with id field as key
DB::getIndCol('id', 'SELECT `id`,`name` FROM `users`');
// Switching to second database
DB::Switch($db2);
// Get all the rows into indexed array
DB::getInd('id', 'SELECT * FROM `users`');
// Get queries statistics
print_r(DB::getStats());
```

#### Using placeholders
```
?n - table or field name
?s - string
?i - number
?a - array for IN, example IN (?a)
?u - array for SET
?p - insert prepared sql without escaping
```

#### Make custom error handler
```php
use \Power\DB;

function ErrorHandler($message)
{
    die($message);
}
DB::SetErrorHandler('ErrorHandler');
```

#### Save error and query logs to file
```php
use \Power\DB;

DB::Init('localhost', 'user', 'passwd', 'dbname', 'utf8mb4');
DB::SetErrorLog(__DIR__.'/mysql_error.log');
DB::SetLogSql(__DIR__.'/mysql_sql.log', false);
```