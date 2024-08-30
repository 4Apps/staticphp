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

        // Open new connection to DB
        self::$dbLinks[$name] = new PDO($config['string'], $config['username'], $config['password'], $options);

        // Set encoding - mysql only
        if (!empty($config['charset']) && self::$dbLinks[$name]->getAttribute(PDO::ATTR_DRIVER_NAME) == 'mysql') {
            self::$dbLinks[$name]->exec('SET NAMES ' . $config['charset'] . ';');
        }

        return self::$dbLinks[$name];
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

        $db_link = &self::$dbLinks[$name];
        if (empty($db_link)) {
            throw new \Exception('No connection to database');
        }

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
     * Make insert sql string and exeute it from associative array of data..
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
        string $returning = null
    ): mixed {
        $keys = [];
        $values = [];
        $params = [];
        foreach ((array) $data as $key => $value) {
            // Little dirty hack for boolean values
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            if ($key[0] == '!') {
                $keys[] = (
                    self::$dbConfigs[$name]['wrap_column']
                    . substr($key, 1)
                    . self::$dbConfigs[$name]['wrap_column']
                );
                $values[] = $value;
            } else {
                $keys[] = (
                    self::$dbConfigs[$name]['wrap_column']
                    . $key
                    . self::$dbConfigs[$name]['wrap_column']
                );
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

            if ($key[0] == '!') {
                $set[] = (
                    self::$dbConfigs[$name]['wrap_column']
                    . substr($key, 1)
                    . self::$dbConfigs[$name]['wrap_column']
                    . " = {$value}"
                );
            } else {
                $set[] = (
                    self::$dbConfigs[$name]['wrap_column']
                    . $key
                    . self::$dbConfigs[$name]['wrap_column']
                    . ' = ?'
                );
                $params[] = $value;
            }
        }

        // Make WHERE
        $cond = '';
        if (\is_array($where)) {
            $cond = [];

            foreach ($where as $key => $value) {
                $c = '=';
                $expl = explode(' ', $key);
                if (count($expl) > 1) {
                    $key = $expl[0];
                    $c = $expl[1];
                }

                if ($key[0] == '!') {
                    if (is_array($value)) {
                        $c = 'NOT IN';
                        $value = '(' . implode(',', $value) . ')';
                        $cond[] = (
                            self::$dbConfigs[$name]['wrap_column']
                            . substr($key, 1)
                            . self::$dbConfigs[$name]['wrap_column']
                            . " {$c} {$value}"
                        );
                    } else {
                        $cond[] = (
                            self::$dbConfigs[$name]['wrap_column']
                            . substr($key, 1)
                            . self::$dbConfigs[$name]['wrap_column']
                            . " {$c} ?"
                        );
                        $params[] = $value;
                    }
                } else {
                    if (is_array($value)) {
                        $c = 'IN';
                        $value = '(' . implode(',', $value) . ')';
                        $cond[] = (
                            self::$dbConfigs[$name]['wrap_column']
                            . $key
                            . self::$dbConfigs[$name]['wrap_column']
                            . " {$c} {$value}"
                        );
                    } else {
                        $cond[] = (
                            self::$dbConfigs[$name]['wrap_column']
                            . $key
                            . self::$dbConfigs[$name]['wrap_column']
                            . " {$c} ?"
                        );
                        $params[] = $value;
                    }
                }
            }

            if (!empty($cond)) {
                $cond = 'WHERE ' . implode(' AND ', $cond);
            }
        } else {
            $cond = "WHERE {$where}";
        }

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
        // Make WHERE
        $cond = '';
        $params = [];
        if (\is_array($where)) {
            $cond = [];

            foreach ($where as $key => $value) {
                $c = '=';
                $expl = explode(' ', $key);
                if (count($expl) > 1) {
                    $key = $expl[0];
                    $c = $expl[1];
                }

                if ($key[0] == '!') {
                    if (is_array($value)) {
                        $c = 'NOT IN';
                        $value = '(' . implode(',', $value) . ')';
                        $cond[] = (self::$dbConfigs[$name]['wrap_column']
                            . substr($key, 1)
                            . self::$dbConfigs[$name]['wrap_column']
                            . " {$c} {$value}"
                        );
                    } else {
                        $cond[] = (self::$dbConfigs[$name]['wrap_column']
                            . substr($key, 1)
                            . self::$dbConfigs[$name]['wrap_column']
                            . " {$c} ?"
                        );
                        $params[] = $value;
                    }
                } else {
                    if (is_array($value)) {
                        $c = 'IN';
                        $value = '(' . implode(',', $value) . ')';
                        $cond[] = (self::$dbConfigs[$name]['wrap_column']
                            . $key
                            . self::$dbConfigs[$name]['wrap_column']
                            . " {$c} {$value}");
                    } else {
                        $cond[] = (self::$dbConfigs[$name]['wrap_column']
                            . $key
                            . self::$dbConfigs[$name]['wrap_column']
                            . " {$c} ?"
                        );
                        $params[] = $value;
                    }
                }
            }

            if (!empty($cond)) {
                $cond = 'WHERE ' . implode(' AND ', $cond);
            }
        } else {
            $cond = "WHERE {$where}";
        }

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
        $db_link = &self::$dbLinks[$name];
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
        $db_link = &self::$dbLinks[$name];
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
        $db_link = &self::$dbLinks[$name];
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
        $db_link = &self::$dbLinks[$name];
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
                $res = self::query('SELECT LAST_INSERT_ID() as id');
            } else {
                $res = self::query('SELECT currval(?) as id', [$sequence_name]);
            }

            return (empty($res['id']) ? null : $res['id']);
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
