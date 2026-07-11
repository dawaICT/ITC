<?php
/**
 * Database Connection Manager
 * Provides a singleton database connection with improved error handling,
 * query helpers, and connection management.
 */

class Database {
    private static ?Database $instance = null;
    private ?mysqli $connection = null;
    private array $config;
    private int $queryCount = 0;
    private array $queryLog = [];
    private bool $debugMode = false;
    
    // Default configuration
    private const DEFAULT_CONFIG = [
        'host' => '127.0.0.1',
        'username' => 'root',
        'password' => '',
        'database' => 'wucportal',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'port' => 3306,
        'timeout' => 30
    ];
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct(array $config = []) {
        require_once dirname(__DIR__) . '/includes/portal_config.php';
        
        $isDevelopment = APP_ENV === 'development';
        $envConfig = [
            'host' => getenv('WUC_DB_HOST') ?: '127.0.0.1',
            'username' => getenv('WUC_DB_USER') ?: ($isDevelopment ? 'root' : ''),
            'password' => getenv('WUC_DB_PASSWORD') === false ? '' : getenv('WUC_DB_PASSWORD'),
            'database' => getenv('WUC_DB_NAME') ?: 'wucportal',
            'port' => (int)(getenv('WUC_DB_PORT') ?: 3306),
        ];

        $this->config = array_merge(self::DEFAULT_CONFIG, $envConfig, $config);
        $this->connect();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(array $config = []): Database {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }
    
    /**
     * Get the raw mysqli connection
     */
    public function getConnection(): mysqli {
        if (!$this->isConnected()) {
            $this->reconnect();
        }
        return $this->connection;
    }
    
    /**
     * Establish database connection
     */
    private function connect(): void {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        
        try {
            $this->connection = new mysqli(
                $this->config['host'],
                $this->config['username'],
                $this->config['password'],
                $this->config['database'],
                $this->config['port']
            );
            
            // Set charset and collation
            $this->connection->set_charset($this->config['charset']);
            $this->connection->query("SET NAMES '{$this->config['charset']}' COLLATE '{$this->config['collation']}'");
            $this->connection->query("SET collation_connection = '{$this->config['collation']}'");
            
            // Set timeout
            $this->connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, $this->config['timeout']);
            
        } catch (mysqli_sql_exception $e) {
            $this->logError('Connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed. Please try again later.');
        }
    }
    
    /**
     * Check if connection is alive
     */
    public function isConnected(): bool {
        return $this->connection !== null && $this->connection->ping();
    }
    
    /**
     * Reconnect if connection was lost
     */
    public function reconnect(): void {
        if ($this->connection !== null) {
            @$this->connection->close();
        }
        $this->connect();
    }
    
    /**
     * Execute a query with optional parameters (prepared statement)
     */
    public function query(string $sql, array $params = []): mysqli_result|bool {
        $startTime = microtime(true);
        
        try {
            if (empty($params)) {
                $result = $this->connection->query($sql);
            } else {
                $stmt = $this->connection->prepare($sql);
                if ($stmt === false) {
                    throw new Exception('Prepare failed: ' . $this->connection->error);
                }
                
                if (!empty($params)) {
                    $types = $this->getParamTypes($params);
                    $stmt->bind_param($types, ...$params);
                }
                
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result === false && $stmt->affected_rows >= 0) {
                    $result = true; // For INSERT/UPDATE/DELETE
                }
                
                $stmt->close();
            }
            
            $this->queryCount++;
            
            if ($this->debugMode) {
                $this->queryLog[] = [
                    'sql' => $sql,
                    'params' => $params,
                    'time' => (microtime(true) - $startTime) * 1000
                ];
            }
            
            return $result;
            
        } catch (mysqli_sql_exception $e) {
            $this->logError("Query failed: {$e->getMessage()} | SQL: $sql");
            throw $e;
        }
    }
    
    /**
     * Fetch single row
     */
    public function fetchOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params);
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $result->free();
            return $row;
        }
        return null;
    }
    
    /**
     * Fetch all rows
     */
    public function fetchAll(string $sql, array $params = []): array {
        $result = $this->query($sql, $params);
        $rows = [];
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        return $rows;
    }
    
    /**
     * Fetch single value
     */
    public function fetchValue(string $sql, array $params = []) {
        $row = $this->fetchOne($sql, $params);
        return $row ? reset($row) : null;
    }
    
    /**
     * Insert a record
     */
    public function insert(string $table, array $data): int|false {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($data), '?');
        
        $sql = sprintf(
            "INSERT INTO `%s` (`%s`) VALUES (%s)",
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );
        
        $result = $this->query($sql, array_values($data));
        return $result ? $this->connection->insert_id : false;
    }
    
    /**
     * Update records
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        $setParts = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = "`$column` = ?";
            $values[] = $value;
        }
        
        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE %s",
            $table,
            implode(', ', $setParts),
            $where
        );
        
        $this->query($sql, array_merge($values, $whereParams));
        return $this->connection->affected_rows;
    }
    
    /**
     * Delete records
     */
    public function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM `$table` WHERE $where";
        $this->query($sql, $params);
        return $this->connection->affected_rows;
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction(): bool {
        return $this->connection->begin_transaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit(): bool {
        return $this->connection->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback(): bool {
        return $this->connection->rollback();
    }
    
    /**
     * Escape string (use prepared statements instead when possible)
     */
    public function escape(string $value): string {
        return $this->connection->real_escape_string($value);
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId(): int {
        return $this->connection->insert_id;
    }
    
    /**
     * Get affected rows
     */
    public function affectedRows(): int {
        return $this->connection->affected_rows;
    }
    
    /**
     * Get last error
     */
    public function getError(): string {
        return $this->connection->error;
    }
    
    /**
     * Get query count
     */
    public function getQueryCount(): int {
        return $this->queryCount;
    }
    
    /**
     * Get query log (only in debug mode)
     */
    public function getQueryLog(): array {
        return $this->queryLog;
    }
    
    /**
     * Enable/disable debug mode
     */
    public function setDebugMode(bool $enabled): void {
        $this->debugMode = $enabled;
    }
    
    /**
     * Check if table exists
     */
    public function tableExists(string $table): bool {
        $result = $this->query("SHOW TABLES LIKE ?", [$table]);
        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
    
    /**
     * Get table columns
     */
    public function getColumns(string $table): array {
        return $this->fetchAll("SHOW COLUMNS FROM `$table`");
    }
    
    /**
     * Determine parameter types for prepared statements
     */
    private function getParamTypes(array $params): string {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } elseif (is_string($param)) {
                $types .= 's';
            } else {
                $types .= 'b'; // blob
            }
        }
        return $types;
    }
    
    /**
     * Log error
     */
    private function logError(string $message): void {
        error_log("[Database] $message");
    }
    
    /**
     * Close connection
     */
    public function close(): void {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Destructor
     */
    public function __destruct() {
        $this->close();
    }
}

/**
 * Helper function to get database instance
 */
function db(): Database {
    return Database::getInstance();
}
