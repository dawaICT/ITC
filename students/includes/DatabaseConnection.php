<?php
/**
 * DatabaseConnection - Unified database connection manager
 * 
 * Provides both mysqli and PDO connections for compatibility with 
 * legacy code (mysqli) and modern service classes (PDO).
 * 
 * Usage:
 *   $dbConn = DatabaseConnection::getInstance();
 *   $mysqli = $dbConn->getMysqli();  // For legacy code
 *   $pdo = $dbConn->getPdo();        // For service classes
 * 
 * Configuration is read from environment variables or falls back to defaults.
 */

declare(strict_types=1);

class DatabaseConnection
{
    private static ?DatabaseConnection $instance = null;
    
    private ?mysqli $mysqli = null;
    private ?PDO $pdo = null;
    
    private string $host;
    private string $username;
    private string $password;
    private string $database;
    private string $charset = 'utf8mb4';
    private string $collation = 'utf8mb4_unicode_ci';
    
    private bool $connected = false;
    private ?string $lastError = null;

    /**
     * Private constructor - use getInstance()
     */
    private function __construct()
    {
        require_once dirname(dirname(__DIR__)) . '/includes/portal_config.php';

        // wuc_portal_env (not getenv) — putenv'd values can vanish mid-request
        // under threaded Apache when a concurrent request's shutdown restores
        // the shared process environment.
        $isDevelopment = APP_ENV === 'development';
        $this->host = wuc_portal_env('WUC_DB_HOST', '127.0.0.1') ?: '127.0.0.1';
        $this->username = wuc_portal_env('WUC_DB_USER', $isDevelopment ? 'root' : '') ?? '';
        $this->password = wuc_portal_env('WUC_DB_PASSWORD', '') ?? '';
        $this->database = wuc_portal_env('WUC_DB_NAME', 'wucportal') ?: 'wucportal';
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get mysqli connection (legacy compatibility)
     */
    public function getMysqli(): ?mysqli
    {
        if ($this->mysqli === null) {
            $this->connectMysqli();
        }
        return $this->mysqli;
    }

    /**
     * Get PDO connection (modern code)
     */
    public function getPdo(): ?PDO
    {
        if ($this->pdo === null) {
            $this->connectPdo();
        }
        return $this->pdo;
    }

    /**
     * Check if database is connected
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Get last connection error
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Test database connectivity
     */
    public function testConnection(): bool
    {
        try {
            $pdo = $this->getPdo();
            if ($pdo) {
                $pdo->query('SELECT 1');
                return true;
            }
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
        }
        return false;
    }

    /**
     * Connect using mysqli
     */
    private function connectMysqli(): void
    {
        try {
            $this->mysqli = new mysqli(
                $this->host,
                $this->username,
                $this->password,
                $this->database
            );

            if ($this->mysqli->connect_error) {
                $this->lastError = 'MySQL connection failed: ' . $this->mysqli->connect_error;
                error_log($this->lastError);
                $this->mysqli = null;
                return;
            }

            // Set charset and collation for consistency
            $this->mysqli->set_charset($this->charset);
            $this->mysqli->query("SET NAMES '{$this->charset}' COLLATE '{$this->collation}'");
            $this->mysqli->query("SET collation_connection = '{$this->collation}'");
            
            $this->connected = true;
            
        } catch (Throwable $e) {
            $this->lastError = 'MySQL connection exception: ' . $e->getMessage();
            error_log($this->lastError);
            $this->mysqli = null;
        }
    }

    /**
     * Connect using PDO
     */
    private function connectPdo(): void
    {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->database};charset={$this->charset}";
            
            $this->pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$this->charset}' COLLATE '{$this->collation}'"
            ]);
            
            $this->connected = true;
            
        } catch (PDOException $e) {
            $this->lastError = 'PDO connection failed: ' . $e->getMessage();
            error_log($this->lastError);
            $this->pdo = null;
        }
    }

    /**
     * Close all connections
     */
    public function close(): void
    {
        if ($this->mysqli !== null) {
            $this->mysqli->close();
            $this->mysqli = null;
        }
        $this->pdo = null;
        $this->connected = false;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new RuntimeException('Cannot unserialize singleton');
    }
}
