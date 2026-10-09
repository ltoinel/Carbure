<?php

namespace ltoinel\lib;

use mysqli;

/**
 * Db.php
 *
 * Database access
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class Db {
    
    // Static connection to the database
    private static $CONN;

    /**
     * Return a database connection.
     *
     * @return mysqli The database connection
     * @throws Exception If the connection fails
     */
    public static function getConnection()
    {
        // We reuse the connection if it exists
        if (self::$CONN != null) {
            return self::$CONN;
        }

        // Otherwise we create a new connection
        $port = Config::has('db_port') ? (int)Config::get('db_port') : 3306;
        self::$CONN = new mysqli(Config::get('db_hostname'), Config::get('db_username'), Config::get('db_password'), Config::get('db_name'), $port);

        if (self::$CONN->connect_error) {
            Logger::error("Database connection failed: " . self::$CONN->connect_error);
            throw new Exception("Database connection failed");
        }

        return self::$CONN;
    }

    /**
     * Execute a query to the SQL database.
     *
     * @param string $sql The SQL query to execute
     * @return mysqli_result|bool The query result
     * @throws Exception If the query fails
     */
    public static function query($sql)
    {
        $conn = self::getConnection();
        $result = $conn->query($sql);

        if ($conn->error) {
            Logger::error("Database query failed: " . $conn->error);
            throw new Exception("Database query failed: " . $conn->error);
        } else {
            Logger::debug("Database query succeeded: $sql");
        }

        return $result;
    }

    /**
     * Execute a prepared statement.
     *
     * @param string $sql    The SQL query with placeholders (?)
     * @param string $types  The types of the parameters (s=string, i=integer, d=double, b=blob)
     * @param mixed  ...$params The parameters to bind
     * @return mysqli_stmt The executed statement
     * @throws Exception If the prepare or execute fails
     */
    public static function execute($sql, $types, ...$params)
    {
        $conn = self::getConnection();
        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            Logger::error("Database prepare failed: " . $conn->error);
            throw new Exception("Database prepare failed: " . $conn->error);
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        // Execute the statement
        $result = $stmt->execute();

        if ($stmt->error) {
            Logger::error("Database execute failed: " . $stmt->error);
            throw new Exception("Database execute failed: " . $stmt->error);
        } else {
            Logger::debug("Database query succeeded: $sql");
        }

        return $stmt;
    }

    /**
     * Execute a prepared statement and return a single row.
     *
     * @param string $sql    The SQL query with placeholders (?)
     * @param string $types  The types of the parameters (s=string, i=integer, d=double, b=blob)
     * @param mixed  ...$params The parameters to bind
     * @return array|null The first row of the result or null if no result
     */
    public static function queryOne($sql,$types, ...$params)
    {
        $stmt = self::execute($sql, $types, ...$params);
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            return null;
        }

        return $result->fetch_assoc();
    }
}
