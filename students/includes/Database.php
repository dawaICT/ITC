<?php
// Unified Database class (PDO) – removed duplicate definitions that caused fatal errors and blank pages
class Database {
    private $host;
    private $username;
    private $password;
    private $database;
    private $conn;

    public function __construct() {
        // Prefer environment variables if set; fallback to common XAMPP defaults
        // Use 127.0.0.1 instead of localhost to match db/connect.php and avoid socket issues
        $this->host = getenv('DB_HOST') ?: '127.0.0.1';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') ?: '';
        $this->database = getenv('DB_NAME') ?: 'wucportal';

        try {
            // Create PDO connection directly; MySQL server/db existence errors will throw PDOException
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            error_log("Database connection established successfully");
        } catch (PDOException $e) {
            error_log("PDO Connection Error: " . $e->getMessage());
            // Surface a controlled message to avoid totally blank page when this class is used on its own endpoints
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Database connection failed. Check logs/error.log for details.';
            exit;
        }
    }

    public function getConnection(): PDO {
        if (!$this->conn) {
            throw new RuntimeException('Database connection not established');
        }
        return $this->conn;
    }
}