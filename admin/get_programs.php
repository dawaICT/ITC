<?php
include "includes/admin.php";

// Prevent any unwanted output
ob_clean();

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Disable error output
ini_set('display_errors', 0);
error_reporting(0);

try {
    // Validate database connection
    if (!$db || $db->connect_error) {
        throw new Exception("Database connection failed");
    }

    // Fetch programs ordered by name (removed status condition)
    $query = "SELECT program_code, program_name 
             FROM programs 
             ORDER BY program_name ASC";

    if ($stmt = $db->prepare($query)) {
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute query: " . $stmt->error);
        }

        $result = $stmt->get_result();
        $programs = array();

        while ($row = $result->fetch_assoc()) {
            $programs[] = array(
                'program_code' => $row['program_code'],
                'program_name' => $row['program_name']
            );
        }

        if (empty($programs)) {
            echo json_encode([
                'warning' => 'No programs found in the system',
                'programs' => []
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => count($programs) . ' programs loaded successfully',
                'programs' => $programs
            ]);
        }

        $stmt->close();
    } else {
        throw new Exception("Failed to prepare query: " . $db->error);
    }

} catch (Exception $e) {
    error_log("Program loading error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to load programs',
        'details' => $e->getMessage()
    ]);
} finally {
    if (isset($db)) {
        $db->close();
    }
}
?> 