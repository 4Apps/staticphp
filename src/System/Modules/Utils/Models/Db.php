<?php

namespace System\Modules\Utils\Models;

use PDO;
use PDOStatement;
use System\Modules\Core\Models\Config;
use System\Modules\Core\Models\Timers;

/**
 * Database wrapper for pdo.
 */
class Db
{
    /**
     * Holds references to database links.
     *
     * @var PDO[]
     * @access private
     * @static
     */
    private static $dbLinks;

    /**
     * Holds references to db configuration arrays.
     *
     * @var mixed[]
     * @access private
     * @static
     */
    private static $dbConfigs;

    /**
     * Cache for last statment.
     *
     * @var ?PDOStatement
     * @access private
     * @static
     */
    private static ?PDOStatement $lastStatement = null;

    /**
     * Init connection to the database.
     *
     * Connection can be made by passing configuration array to $config parameter or
     * by passing a name of the connection that has been set up in
     * Application/Config/Db.php (see example in System/Config/Db.php).
     *
     * @example Db::init();
     * @example Db::init('second');
     * @example Db::init(
     *              'pgsql1',
     *              [
     *                  'string' => 'pgsql:host=localhost;dbname=',
     *                  'username' => 'username',
     *                  'password' => 'password',
     *                  'charset' => 'UTF8',
     *                  'persistent' => true,
     *                  'wrap_column' => '`', // ` - for mysql, " - for postgresql
     *                  'fetch_mode_objects' => false,
     *                  'debug' => true,
     *              ]
     *          );
     * @access public
     * @static
     * @param  string $name   (default: 'default')
     * @param  array  $config (default: null)
     * @return PDO Returns pdo instance.
     */
    public static function init(string $name = 'default', ?array $config = null): PDO
    {
        // Check if there is such configuration
        if (empty($config)) {
            if (empty(Config::$items['db']['pdo'][$name])) {
                throw new \Exception('Db configuration not found');
            }

            $config = Config::$items['db']['pdo'][$name];
        }

        // Don't make a new connection if there is one connected with the name
        if (!empty(self::$dbLinks[$name])) {
            return self::$dbLinks[$name];
        }

        // Set config
        self::$dbConfigs[$name] = $config;

        // Options
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
            PDO::ATTR_DEFAULT_FETCH_MODE => (!empty($config['fetch_mode_objects']) ? PDO::FETCH_OBJ : PDO::FETCH_ASSOC),
        ];

        if (isset($config['persistent'])) {
            $options[PDO::ATTR_PERSISTENT] = $config['persistent'];
        }

        // Emulated prepares quote parameters client-side rather than sending them
        // separately, and return every column as a string. The driver defaults disagree
        // (mysql emulates, pgsql does not), so set it explicitly instead of inheriting
        // whichever the driver happens to pick.
        $options[PDO::ATTR_EMULATE_PREPARES] = (bool) ($config['emulate_prepares'] ?? false);

        $dsn = $config['string'];

        // The charset belongs in the DSN. Setting it afterwards with "SET NAMES" changes
        // the connection but not what PDO believes the connection is, so with emulated
        // prepares its client-side quoting keeps using the DSN charset - which is the
        // multi-byte injection hole. It is also one round trip on every connect, and with
        // persistent connections that lands on requests that reuse a pooled handle.
        if (!empty($config['charset'])) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $config['charset']) !== 1) {
                throw new \InvalidArgumentException("Invalid charset: \"{$config['charset']}\"");
            }

            if (str_starts_with($dsn, 'mysql:') && stripos($dsn, 'charset=') === false) {
                $dsn .= (str_ends_with($dsn, ';') ? '' : ';') . 'charset=' . $config['charset'];
            }
        }

        // Open new connection to DB
        self::$dbLinks[$name] = new PDO($dsn, $config['username'], $config['password'], $options);

        return self::$dbLinks[$name];
    }

    /**
     * Get an open connection by name.
     *
     * Writing `$link = &self::$dbLinks[$name]` auto-vivified a null entry for an unknown
     * name, so a typo produced "call to a member function on null" somewhere further down
     * instead of naming the problem - and left the bogus key behind.
     *
     * @access private
     * @static
     * @param  string $name    Connection name
     * @param  bool   $connect Open the connection if it is configured but not yet open
     * @return PDO
     */
    private static function link(string $name, bool $connect = false): PDO
    {
        if (isset(self::$dbLinks[$name])) {
            return self::$dbLinks[$name];
        }

        // Connect on first use, so a request that never touches the database does not pay
        // for a connect (or, with persistent connections, a pool checkout)
        if ($connect === true && !empty(Config::$items['db']['pdo'][$name])) {
            return self::init($name);
        }

        $known = implode(', ', array_keys(self::$dbLinks ?? []));

        throw new \Exception(
            "No connection to database \"{$name}\""
            . (empty($known) ? '' : " (open connections: {$known})")
        );
    }

    /**
     * Make a query.
     *
     * Should be used for insert and update queries, but also can be used as iterator for select queries.
     *
     * @example Db::query('INSERT INTO posts (title) VALUES (?)', ['New post title'], 'pgsql1');
     * @example $query = Db::query('SELECT * FROM posts', null, 'pgsql1');<br />
     *          foreach ($query as $item)<br />
     *          {<br />
     *              // Do something with the $item<br />
     *          }
     * @access public
     * @static
     * @param  string       $query
     * @param  array      $data  (default: [])
     * @param  string       $name  (default: 'default')
     * @return PDOStatement Returns statement created by query.
     */
    public static function query(string $query, array $data = [], string $name = 'default'): PDOStatement
    {
        if (empty($query)) {
            throw new \Exception('Empty query passed');
        }

        $db_link = self::link($name, true);

        // Do request
        if (!empty(self::$dbConfigs[$name]['debug'])) {
            Timers::startTimer();
        }

        self::$lastStatement = $db_link->prepare($query);
        self::$lastStatement->execute((array) $data);

        if (!empty(self::$dbConfigs[$name]['debug'])) {
            $log = $query;
            if (!empty($data)) {
                $log_data = array_map(
                    function ($item) {
                        return (is_integer($item) == true ? $item : "'" . $item . "'");
                    },
                    (array)$data
                );

                $replace = '?';
                $q_count = substr_count($query, $replace);
                for ($i = 0; $i < $q_count; ++$i) {
                    $pos = strpos($log, $replace);
                    if ($pos !== false) {
                        $log = substr_replace($log, $log_data[$i], $pos, strlen($replace));
                    }
                }
            }

            Timers::stopTimer($log);
        }

        // Return last statement
        return self::$lastStatement;
    }

    /**
     * Fetch one row of query. Useful if you need only one record returned.
     *
     * @example Db::fetch('SELECT * FROM posts WHERE id = ?', [$post_id], 'pgsql1');
     * @access public
     * @static
     * @param  string $query Query
     * @param  array  $data  (default: [])
     * @param  string $name  (default: 'default')
     * @return mixed  Returns array or object of the one record from database.
     */
    public static function fetch(string $query, array $data = [], string $name = 'default'): mixed
    {
        return self::query($query, $data, $name)->fetch();
    }

    /**
     * Fetch all rows.
     *
     * @access public
     * @static
     * @param  string $query Query
     * @param  array  $data  (default: [])
     * @param  string $name  (default: 'default')
     * @return array  Returns array of arrays or objects containing all rows returned by database.
     */
    public static function fetchAll(string $query, array $data = [], string $name = 'default'): array
    {
        return self::query($query, $data, $name)->fetchAll();
    }

    /**
     * Operators accepted in a condition key, e.g. ['age >' => 18].
     *
     * Conditions are built by string concatenation, so the operator can never come from
     * untrusted input - anything outside this list is rejected.
     *
     * @var string[]
     * @access private
     * @static
     */
    private static array $allowedOperators = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE',
        'IS', 'IS NOT', 'IN', 'NOT IN',
    ];

    /**
     * Validate an identifier and wrap it in the connection's quoting character.
     *
     * Identifiers cannot be passed as query parameters, so they are concatenated into the
     * query - which makes this the only thing standing between a caller-supplied column
     * name and SQL injection. Anything that is not a plain identifier (optionally
     * table-qualified) is rejected rather than escaped.
     *
     * @access private
     * @static
     * @param  string $key  Column name, optionally qualified as "table.column"
     * @param  string $name Connection name
     * @return string Returns the wrapped identifier
     */
    private static function wrapColumn(string $key, string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $key) !== 1) {
            throw new \InvalidArgumentException("Invalid column name: \"{$key}\"");
        }

        $wrap = self::$dbConfigs[$name]['wrap_column'] ?? '';
        if (strpos($key, '.') === false) {
            return $wrap . $key . $wrap;
        }

        // Wrap each part of a qualified name separately, so "t.col" becomes `t`.`col`
        [$table, $column] = explode('.', $key, 2);

        return $wrap . $table . $wrap . '.' . $wrap . $column . $wrap;
    }

    /**
     * Split a condition key into its column name and comparison operator.
     *
     * @access private
     * @static
     * @param  string $key Condition key, e.g. "age >" or "name NOT LIKE"
     * @return array  Returns [column, operator]
     */
    private static function splitCondition(string $key): array
    {
        $expl = preg_split('/\s+/', trim($key));
        $column = array_shift($expl);
        $operator = (empty($expl) ? '=' : strtoupper(implode(' ', $expl)));

        if (in_array($operator, self::$allowedOperators, true) === false) {
            throw new \InvalidArgumentException("Unsupported comparison operator: \"{$operator}\"");
        }

        return [$column, $operator];
    }

    /**
     * Build a WHERE clause from an associative array of conditions.
     *
     * Every value is bound as a query parameter, including array values, which expand to
     * an IN / NOT IN list of placeholders. Prefixing a key with "!" negates the condition.
     *
     * Passing a string instead of an array appends it to the query verbatim. That is a raw
     * SQL escape hatch with no escaping whatsoever - never build it from request data.
     *
     * @access private
     * @static
     * @param  mixed  $where  Conditions array, or a raw condition string
     * @param  string $name   Connection name
     * @param  array  $params Parameters collected so far, appended to in place
     * @return string Returns the WHERE clause, or an empty string when there are no conditions
     */
    private static function buildWhere(mixed $where, string $name, array &$params): string
    {
        if (is_array($where) === false) {
            return (empty($where) ? '' : "WHERE {$where}");
        }

        $cond = [];
        foreach ($where as $key => $value) {
            [$key, $operator] = self::splitCondition((string) $key);

            $negated = (isset($key[0]) && $key[0] === '!');
            if ($negated === true) {
                $key = substr($key, 1);
            }

            $column = self::wrapColumn($key, $name);

            if (is_array($value)) {
                $operator = ($negated === true ? 'NOT IN' : 'IN');

                // An empty list has no valid SQL representation, so collapse it to a
                // constant with the same truth value instead of emitting "IN ()"
                if (empty($value)) {
                    $cond[] = ($negated === true ? '1 = 1' : '1 = 0');
                    continue;
                }

                $cond[] = $column . " {$operator} (" . implode(', ', array_fill(0, count($value), '?')) . ')';
                foreach ($value as $item) {
                    $params[] = $item;
                }

                continue;
            }

            // Note: for scalar values "!" only strips the prefix and leaves the operator
            // alone, matching the behaviour this method replaced. Write ['id !=' => $x]
            // rather than ['!id' => $x] when you want a negated scalar comparison.
            $cond[] = $column . " {$operator} ?";
            $params[] = $value;
        }

        return (empty($cond) ? '' : 'WHERE ' . implode(' AND ', $cond));
    }

    /**
     * Make insert sql string and exeute it from associative array of data..
     *
     * Prefixing a key with "!" writes its value into the query verbatim instead of binding
     * it, so that SQL expressions can be used. Never build such a value from request data.
     *
     * @example Db::insert('posts', ['title' => 'Different title', '!active' => 1]);
     *          will make and execute query: INSERT INTO posts (title, active) VALUES ('Different title', 1).
     * @access public
     * @static
     * @param  string        $table Table
     * @param  array         $data  Data
     * @param  string        $name  (default: 'default')
     * @return PDOStatement Returns statement created by query.
     */
    public static function insert(
        string $table,
        array $data,
        string $name = 'default',
        ?string $returning = null
    ): mixed {
        $keys = [];
        $values = [];
        $params = [];
        foreach ((array) $data as $key => $value) {
            // Little dirty hack for boolean values
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            if (isset($key[0]) && $key[0] === '!') {
                $keys[] = self::wrapColumn(substr($key, 1), $name);
                $values[] = $value;
            } else {
                $keys[] = self::wrapColumn((string) $key, $name);
                $values[] = '?';
                $params[] = $value;
            }
        }

        // Compile KEYS and VALUES
        $keys = implode(', ', $keys);
        $values = implode(', ', $values);

        // Run Query
        $query = self::query("INSERT INTO {$table} ({$keys}) VALUES ({$values}) {$returning}", $params, $name);

        return (empty($returning) ? $query : $query->fetch());
    }

    /**
     * Make update sql string and exeute it from associative array of data.
     *
     * @example Db::update('posts', ['title' => 'Different title', '!active' => 1], ['id' => $post_id]);
     *          will make and execute query: UPDATE posts SET title = 'Different title', active = 1 WHERE id = 2.
     * @access public
     * @static
     * @param  string $table Table
     * @param  array  $data  Data
     * @param  array  $where Conditions
     * @param  string $name  (default: 'default')
     * @return PDOStatement Returns statement created by query.
     */
    public static function update(string $table, array $data, array $where, string $name = 'default'): PDOStatement
    {
        // Make SET
        $set = [];
        $params = [];
        foreach ((array) $data as $key => $value) {
            // Little dirty hack for boolean values
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            if (isset($key[0]) && $key[0] === '!') {
                $set[] = self::wrapColumn(substr($key, 1), $name) . " = {$value}";
            } else {
                $set[] = self::wrapColumn((string) $key, $name) . ' = ?';
                $params[] = $value;
            }
        }

        // Make WHERE
        $cond = self::buildWhere($where, $name, $params);

        // Compile SET
        $set = implode(', ', $set);

        // Run Query
        return self::query("UPDATE {$table} SET {$set} {$cond};", $params, $name);
    }

    /**
     * Make delete sql string and execute it from associative array of data.
     *
     * @example models\Db::delete('posts', ['id' => $post_id]);
     *          will make and run query: DELETE FROM posts WHERE id = 2.
     * @access public
     * @static
     * @param  string       $table
     * @param  mixed        $where
     * @param  string       $name  (default: 'default')
     * @return \PDOStatement Returns statement created by query.
     */
    public static function delete($table, $where, $name = 'default')
    {
        // A missing or empty condition would delete every row in the table
        if (empty($where)) {
            throw new \InvalidArgumentException(
                'Db::delete() requires a condition. Pass an explicit "1 = 1" to truncate a table.'
            );
        }

        // Make WHERE
        $params = [];
        $cond = self::buildWhere($where, $name, $params);

        // Run Query
        return self::query("DELETE FROM {$table} {$cond};", $params, $name);
    }

    /**
     * Initiates a database transaction on a database link by $name.
     *
     * Turns off autocommit mode. While autocommit mode is turned off,
     * changes made to the database via the PDO object instance are not
     * committed until you end the transaction by calling Db::commit().
     * Calling Db::rollBack() will roll back all changes to the database
     * and return the connection to autocommit mode.
     *
     * @see Db::commit()
     * @access public
     * @static
     * @param string $name (default: 'default')
     * @return bool Returns true on success or false on failure.
     */
    public static function beginTransaction(string $name = 'default'): bool
    {
        $db_link = self::link($name);
        return $db_link->beginTransaction();
    }


    /**
     * Check wheather current context is in transaction
     * @access public
     * @static
     * @param string $name (default: 'default')
     * @return bool Returns true on success or false on failure.
     */
    public static function inTransaction(string $name = 'default'): bool
    {
        $db_link = self::link($name);
        return $db_link->inTransaction();
    }


    /**
     * Commit a transaction on a database link by $name.
     *
     * @access public
     * @static
     * @param string $name (default: 'default')
     * @return bool Returns true on success or false on failure.
     */
    public static function commit(string $name = 'default'): bool
    {
        $db_link = self::link($name);
        return $db_link->commit();
    }

    /**
     * Rolls back a transaction on a database link by $name.
     *
     * @access public
     * @static
     * @param string $name (default: 'default')
     * @return bool Returns true on success or false on failure.
     */
    public static function rollBack(string $name = 'default'): bool
    {
        $db_link = self::link($name);
        return $db_link->rollBack();
    }

    /**
     * Get PDO object connection link to the database by $name.
     *
     * @access public
     * @static
     * @param  string $name (default: 'default')
     * @return PDO    Returns php's PDO object.
     */
    public static function &dbLink(string $name = 'default'): PDO
    {
        return self::$dbLinks[$name];
    }

    /**
     * Get last statement that was run on database through this (Db) class.
     *
     * @access public
     * @static
     * @return ?PDOStatement Returns statement created by query.
     */
    public static function &lastStatement(): ?PDOStatement
    {
        return self::$lastStatement;
    }

    /**
     * Get last query that was run on database through this (Db) class.
     *
     * @access public
     * @static
     * @return ?string Returns string of the query.
     */
    public static function lastQuery(): ?string
    {
        return empty(self::$lastStatement) ? null : self::$lastStatement->queryString;
    }

    /**
     * Get the last insert id created by database.
     *
     * Id can be returned by pdo in-built method by setting $sql to false or by querying database.
     * If $sequence_name is provided, it will aptempt to only get last value for that sequence.
     *
     * @access public
     * @static
     * @param  string $sequence_name (default: '')
     * @param  bool   $sql           (default: false)
     * @param  string $name          (default: 'default')
     * @return ?int   Returns last insert id on success or null on failure.
     */
    public static function lastInsertId(string $sequence_name = '', bool $sql = false, string $name = 'default'): ?int
    {
        if (empty($sql)) {
            return self::$dbLinks[$name]->lastInsertId($sequence_name);
        } else {
            if (empty($sequence_name)) {
                $res = self::fetch('SELECT LAST_INSERT_ID() as id', [], $name);
            } else {
                $res = self::fetch('SELECT currval(?) as id', [$sequence_name], $name);
            }

            $res = (array) $res;

            return (empty($res['id']) ? null : (int) $res['id']);
        }
    }


    /**
     * Close connection specified by $name
     *
     * @access public
     * @static
     * @param  string   $name          (default: 'default')
     * @return null
     */
    public static function close(string $name = 'default'): void
    {
        self::$dbLinks[$name] = null;
        unset(self::$dbLinks[$name]);
    }
}
