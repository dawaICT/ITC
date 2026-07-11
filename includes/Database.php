<?php
class Database {
    private static $instance = null;
    private $connection = null;
    private $statement = null;
    private $connected = false;

    private function __construct() {
        try {
            $dsn = sprintf(
                "mysql:host=%s;port=%d;dbname=%s;charset=%s",
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => DB_PERSISTENT,
                PDO::ATTR_TIMEOUT => DB_TIMEOUT
            ];

            // Add SSL options if enabled
            if (DB_SSL && DB_SSL_CA && DB_SSL_CERT && DB_SSL_KEY) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                $options[PDO::MYSQL_ATTR_SSL_CERT] = DB_SSL_CERT;
                $options[PDO::MYSQL_ATTR_SSL_KEY] = DB_SSL_KEY;
            }

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            $this->connected = true;

            if (DB_DEBUG) {
                error_log("Database connection established successfully");
            }
        } catch (PDOException $e) {
            $this->handleError($e);
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        if (!$this->connected) {
            throw new Exception("Database connection not established");
        }
        return $this->connection;
    }

    public function query($sql, $params = []) {
        try {
            $this->statement = $this->connection->prepare($sql);
            $this->statement->execute($params);
            return $this->statement;
        } catch (PDOException $e) {
            $this->handleError($e);
        }
    }

    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert($table, $data) {
        $fields = array_keys($data);
        $values = array_values($data);
        $placeholders = str_repeat('?,', count($fields) - 1) . '?';
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(',', $fields),
            $placeholders
        );

        $this->query($sql, $values);
        return $this->connection->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = []) {
        $fields = array_keys($data);
        $values = array_values($data);
        $set = implode('=?,', $fields) . '=?';
        
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            $set,
            $where
        );

        $params = array_merge($values, $whereParams);
        return $this->query($sql, $params)->rowCount();
    }

    public function delete($table, $where, $params = []) {
        $sql = sprintf("DELETE FROM %s WHERE %s", $table, $where);
        return $this->query($sql, $params)->rowCount();
    }

    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollBack() {
        return $this->connection->rollBack();
    }

    private function handleError($e) {
        if (DB_DEBUG) {
            error_log("Database Error: " . $e->getMessage());
            throw new Exception("Database Error: " . $e->getMessage());
        } else {
            error_log("Database Error: " . $e->getMessage());
            throw new Exception("A database error occurred. Please try again later.");
        }
    }

    public function __destruct() {
        $this->statement = null;
        $this->connection = null;
        $this->connected = false;
    }
} 