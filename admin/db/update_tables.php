<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Database connection
$host = 'localhost';
$dbname = 'wucportal';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Read and execute SQL file
    $sql = file_get_contents(__DIR__ . '/update_programs_table.sql');
    $pdo->exec($sql);

    echo "Database tables updated successfully!";
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 