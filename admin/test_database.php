<?php
// Set headers for JSON response
header('Content-Type: application/json');

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in response

// Initialize response array
$response = [
    'success' => false,
    'message' => 'Database test failed',
    'details' => []
];

try {
    // Include database connection
    require_once __DIR__ . '/../includes/db_connect.php';
    
    // Check if database connection exists
    if (!isset($db) || !$db) {
        throw new Exception("Database connection variable not available");
    }
    
    // Test ping to verify connection is alive
    if (!$db->ping()) {
        throw new Exception("Database connection lost: " . $db->error);
    }
    
    // Try a simple query to verify permissions
    $test_query = "SELECT 1 AS test";
    $result = $db->query($test_query);
    
    if (!$result) {
        throw new Exception("Unable to execute test query: " . $db->error);
    }
    
    // Test reading from a table
    $tables_result = $db->query("SHOW TABLES");
    if (!$tables_result) {
        throw new Exception("Unable to list tables: " . $db->error);
    }
    
    $tables = [];
    while ($table = $tables_result->fetch_array()) {
        $tables[] = $table[0];
    }
    
    // Get server info
    $server_info = $db->server_info;
    $host_info = $db->host_info;
    
    // Return success response
    $response = [
        'success' => true,
        'message' => 'Database connection successful',
        'details' => [
            'server' => $host_info,
            'version' => $server_info,
            'tables_found' => count($tables),
            'table_list' => array_slice($tables, 0, 10), // Limit to first 10 tables
            'connection_type' => $db->client_info
        ]
    ];
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    $response['details']['error'] = $e->getMessage();
    $response['details']['trace'] = $e->getTraceAsString();
    
    // Try to get mysqli error if available
    if (isset($db) && $db) {
        $response['details']['mysqli_error'] = $db->error;
        $response['details']['mysqli_errno'] = $db->errno;
    }
}

// Return JSON response
echo json_encode($response, JSON_PRETTY_PRINT);
?> 