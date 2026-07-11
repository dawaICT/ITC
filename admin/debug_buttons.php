<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "=== DEBUGGING BUTTON FUNCTIONALITY ===\n\n";

// Define IS_SCRIPT to bypass session requirements
define('IS_SCRIPT', true);

try {
    require_once "includes/admin.php";
    echo "✓ Admin file included successfully\n";
    
    if (isset($db) && !$db->connect_error) {
        echo "✓ Database connection successful\n\n";
        
        // Test programs query
        $prog_query = "SELECT p.*, d.department_name FROM programs p LEFT JOIN departments d ON p.department_id = d.id ORDER BY p.program_name";
        $prog_result = $db->query($prog_query);
        
        if ($prog_result) {
            echo "✓ Programs query works: " . $prog_result->num_rows . " programs found\n\n";
            
            if ($prog_result->num_rows > 0) {
                echo "Sample program data:\n";
                $program = $prog_result->fetch_assoc();
                
                echo "Program Code: " . $program['program_code'] . "\n";
                echo "Program Name: " . $program['program_name'] . "\n";
                echo "Program Type: " . $program['program_type'] . "\n";
                echo "Department: " . ($program['department_name'] ?? 'N/A') . "\n";
                echo "Is Active: " . $program['is_active'] . "\n";
                
                // Test JSON encoding
                $json_data = json_encode($program);
                if ($json_data !== false) {
                    echo "\n✓ JSON encoding works:\n";
                    echo $json_data . "\n";
                } else {
                    echo "\n✗ JSON encoding failed\n";
                }
                
                // Test HTML encoding
                echo "\nHTML encoded data for buttons:\n";
                echo "data-program='" . htmlspecialchars($json_data) . "'\n";
                echo "data-program-code='" . htmlspecialchars($program['program_code']) . "'\n";
                echo "data-program-name='" . htmlspecialchars($program['program_name']) . "'\n";
                
            } else {
                echo "⚠ No programs found in database\n";
            }
        } else {
            echo "✗ Error in programs query: " . $db->error . "\n";
        }
        
        // Test departments query
        echo "\nTesting departments for dropdown:\n";
        $dept_query = "SELECT id, department_name FROM departments ORDER BY department_name";
        $dept_result = $db->query($dept_query);
        
        if ($dept_result) {
            echo "✓ Departments query works: " . $dept_result->num_rows . " departments found\n";
            
            while ($dept = $dept_result->fetch_assoc()) {
                echo "  - ID: " . $dept['id'] . ", Name: " . $dept['department_name'] . "\n";
            }
        } else {
            echo "✗ Error in departments query: " . $db->error . "\n";
        }
        
    } else {
        echo "✗ Database connection failed\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\nDebug completed.\n";
?> 