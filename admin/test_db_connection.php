<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Include database connection
require_once __DIR__ . '/../includes/db_connect.php';

// Test database connection
echo "<h2>Database Connection Test</h2>";

// Check if $db object exists
if (!isset($db)) {
    die("Error: Database connection object (\$db) is not defined");
}

// Check connection status
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Test query
$test_query = "SELECT 1";
$result = $db->query($test_query);

if ($result === false) {
    die("Query failed: " . $db->error);
}

// Test database version
$version = $db->server_info;
echo "<p>Connected to MySQL Server Version: $version</p>";

// Test database name
$db_name = $db->query("SELECT DATABASE()")->fetch_array()[0];
echo "<p>Connected to Database: $db_name</p>";

// Test character set
$charset = $db->get_charset();
echo "<p>Current Character Set: " . $charset->charset . "</p>";

// Test table existence
$tables = $db->query("SHOW TABLES");
if ($tables === false) {
    die("Error checking tables: " . $db->error);
}

echo "<h3>Available Tables:</h3>";
echo "<ul>";
while ($table = $tables->fetch_array()) {
    echo "<li>" . $table[0] . "</li>";
}
echo "</ul>";

// Close connection
$db->close();
echo "<p>Database connection test completed successfully!</p>";
?> 