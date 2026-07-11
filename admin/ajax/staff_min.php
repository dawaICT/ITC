<?php
/**
 * AJAX endpoint to retrieve minimal staff information for dropdown selections
 * Returns JSON array of staff with essential fields: staff_id, title, Fname, Lname
 */

// Prevent direct file access
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    // Allow both AJAX and regular requests for testing
}

// Include admin authentication
require_once "../includes/admin.php";

// Set JSON response header
header('Content-Type: application/json; charset=UTF-8');

try {
    // Check database connection
    if (!isset($db) || !$db || $db->connect_errno) {
        throw new Exception("Database connection not available");
    }

    // Query to fetch staff members with essential information
    $query = "SELECT 
                staff_id, 
                title, 
                Fname, 
                Lname, 
                email,
                mobile
              FROM staff 
              ORDER BY Fname ASC, Lname ASC";

    $result = $db->query($query);

    if (!$result) {
        throw new Exception("Query failed: " . $db->error);
    }

    // Build staff array
    $staff_list = array();
    while ($row = $result->fetch_assoc()) {
        $staff_list[] = array(
            'staff_id' => $row['staff_id'],
            'title' => $row['title'] ?? '',
            'Fname' => $row['Fname'] ?? '',
            'Lname' => $row['Lname'] ?? '',
            'email' => $row['email'] ?? '',
            'phone' => $row['mobile'] ?? '', // Map 'mobile' column to 'phone' key for consistency
            'mobile' => $row['mobile'] ?? ''
        );
    }

    // Free result set
    $result->free();

    // Return JSON response
    echo json_encode($staff_list, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    // Log error
    error_log("Error in staff_min.php: " . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode(array(
        'error' => true,
        'message' => 'Failed to retrieve staff data',
        'details' => $e->getMessage()
    ), JSON_PRETTY_PRINT);
}
?>
