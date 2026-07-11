<?php
/**
 * Enhanced Database Connection Class for WUC Portal
 * Implements singleton pattern with PDO for secure, efficient database operations
 * Supports transactions, prepared statements, and connection pooling
 */

class DatabaseConnection {
    private static $instance = null;
    private $connection = null;
    private $transactionLevel = 0;
    
    // Database configuration
    private $config = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'wucportal',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false, // Set to true for connection pooling in production
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    ];
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        require_once dirname(__FILE__) . '/portal_config.php';
        
        $isDevelopment = APP_ENV === 'development';
        $this->config['host'] = getenv('WUC_DB_HOST') ?: '127.0.0.1';
        $this->config['port'] = (int)(getenv('WUC_DB_PORT') ?: 3306);
        $this->config['username'] = getenv('WUC_DB_USER') ?: ($isDevelopment ? 'root' : '');
        $db_pass = getenv('WUC_DB_PASSWORD');
        $this->config['password'] = $db_pass === false ? '' : $db_pass;
        $this->config['database'] = getenv('WUC_DB_NAME') ?: 'wucportal';

        $this->connect();
    }
    
    /**
     * Get singleton instance
     * @return DatabaseConnection
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establish database connection
     * @throws PDOException
     */
    private function connect(): void {
        try {
            $dsn = sprintf(
                "mysql:host=%s;port=%d;dbname=%s;charset=%s",
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                $this->config['charset']
            );
            
            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $this->config['options']
            );
            
            // Set collation
            $this->connection->exec("SET collation_connection = '{$this->config['collation']}'");
            
            // Log successful connection in debug mode
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log('Database connection established successfully');
            }
            
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed. Please contact support.');
        }
    }
    
    /**
     * Get the PDO connection object
     * @return PDO
     */
    public function getConnection(): PDO {
        // Check if connection is still alive
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }
    
    /**
     * Execute a prepared statement with parameters
     * @param string $query SQL query with placeholders
     * @param array $params Parameters to bind
     * @return PDOStatement
     */
    public function execute(string $query, array $params = []): PDOStatement {
        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log('Query execution failed: ' . $e->getMessage() . ' | Query: ' . $query);
            throw new Exception('Database query failed');
        }
    }
    
    /**
     * Fetch a single row
     * @param string $query SQL query
     * @param array $params Parameters
     * @return array|null
     */
    public function fetchOne(string $query, array $params = []): ?array {
        $stmt = $this->execute($query, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * Fetch all rows
     * @param string $query SQL query
     * @param array $params Parameters
     * @return array
     */
    public function fetchAll(string $query, array $params = []): array {
        $stmt = $this->execute($query, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Fetch a single column value
     * @param string $query SQL query
     * @param array $params Parameters
     * @return mixed
     */
    public function fetchColumn(string $query, array $params = []) {
        $stmt = $this->execute($query, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Insert a record and return the last insert ID
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @return int Last insert ID
     */
    public function insert(string $table, array $data): int {
        $columns = array_keys($data);
        $placeholders = array_map(function($col) { return ":$col"; }, $columns);
        
        $query = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        
        $params = [];
        foreach ($data as $key => $value) {
            $params[":$key"] = $value;
        }
        
        $this->execute($query, $params);
        return (int) $this->connection->lastInsertId();
    }
    
    /**
     * Update records
     * @param string $table Table name
     * @param array $data Data to update
     * @param string $where WHERE clause
     * @param array $whereParams WHERE parameters
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        $setParts = [];
        $params = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = "$column = :set_$column";
            $params[":set_$column"] = $value;
        }
        
        $query = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            implode(', ', $setParts),
            $where
        );
        
        $params = array_merge($params, $whereParams);
        $stmt = $this->execute($query, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Delete records
     * @param string $table Table name
     * @param string $where WHERE clause
     * @param array $params WHERE parameters
     * @return int Number of affected rows
     */
    public function delete(string $table, string $where, array $params = []): int {
        $query = sprintf("DELETE FROM %s WHERE %s", $table, $where);
        $stmt = $this->execute($query, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Begin a transaction
     * Supports nested transactions
     */
    public function beginTransaction(): void {
        if ($this->transactionLevel === 0) {
            $this->connection->beginTransaction();
        } else {
            // Use savepoints for nested transactions
            $this->connection->exec("SAVEPOINT LEVEL{$this->transactionLevel}");
        }
        $this->transactionLevel++;
    }
    
    /**
     * Commit a transaction
     */
    public function commit(): void {
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            $this->connection->commit();
        } else {
            // Release savepoint
            $this->connection->exec("RELEASE SAVEPOINT LEVEL{$this->transactionLevel}");
        }
    }
    
    /**
     * Rollback a transaction
     */
    public function rollback(): void {
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            $this->connection->rollBack();
        } else {
            // Rollback to savepoint
            $this->connection->exec("ROLLBACK TO SAVEPOINT LEVEL{$this->transactionLevel}");
        }
    }
    
    /**
     * Check if currently in a transaction
     * @return bool
     */
    public function inTransaction(): bool {
        return $this->transactionLevel > 0;
    }
    
    /**
     * Execute a callable within a transaction
     * Automatically handles commit/rollback
     * @param callable $callback
     * @return mixed Result of callback
     * @throws Exception
     */
    public function transaction(callable $callback) {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            error_log('Transaction failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Prepare a statement (for manual execution)
     * @param string $query
     * @return PDOStatement
     */
    public function prepare(string $query): PDOStatement {
        return $this->connection->prepare($query);
    }
    
    /**
     * Get the last insert ID
     * @return string
     */
    public function lastInsertId(): string {
        return $this->connection->lastInsertId();
    }
    
    /**
     * Execute raw SQL (use with caution)
     * @param string $query
     * @return int|false
     */
    public function exec(string $query) {
        return $this->connection->exec($query);
    }
    
    /**
     * Check if a table exists
     * @param string $tableName
     * @return bool
     */
    public function tableExists(string $tableName): bool {
        $query = "SHOW TABLES LIKE ?";
        $stmt = $this->execute($query, [$tableName]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get table column information
     * @param string $tableName
     * @return array
     */
    public function getTableColumns(string $tableName): array {
        $query = "DESCRIBE {$tableName}";
        return $this->fetchAll($query);
    }
    
    /**
     * Ping the database connection
     * @return bool
     */
    public function ping(): bool {
        try {
            $this->connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Close the database connection
     */
    public function close(): void {
        $this->connection = null;
        self::$instance = null;
    }
    
    /**
     * Prevent cloning of the instance
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization of the instance
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Helper function to get database instance
 * @return DatabaseConnection
 */
function db(): DatabaseConnection {
    return DatabaseConnection::getInstance();
}

/**
 * Legacy compatibility - returns mysqli connection
 * @deprecated Use DatabaseConnection class instead
 * @return mysqli
 */
function getLegacyConnection(): mysqli {
    static $mysqli = null;
    if ($mysqli === null) {
        $mysqli = new mysqli('127.0.0.1', 'root', '', 'wucportal');
        if ($mysqli->connect_error) {
            throw new Exception('Legacy connection failed: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');
        $mysqli->query("SET collation_connection = 'utf8mb4_unicode_ci'");
    }
    return $mysqli;
}
