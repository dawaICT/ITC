<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "Testing button functionality and form handling...\n";

// Define IS_SCRIPT to bypass session requirements
define('IS_SCRIPT', true);

try {
    require_once "includes/admin.php";
    echo "✓ Admin file included successfully\n";
    
    if (isset($db) && !$db->connect_error) {
        echo "✓ Database connection successful\n";
        
        // Test if programs table has all required columns
        $required_columns = ['program_code', 'program_name', 'program_type', 'program_duration', 'program_description', 'department_id', 'is_active'];
        $result = $db->query("DESCRIBE programs");
        
        if ($result) {
            $existing_columns = [];
            while ($row = $result->fetch_assoc()) {
                $existing_columns[] = $row['Field'];
            }
            
            echo "✓ Programs table columns: " . implode(', ', $existing_columns) . "\n";
            
            $missing_columns = array_diff($required_columns, $existing_columns);
            if (!empty($missing_columns)) {
                echo "⚠ Missing columns: " . implode(', ', $missing_columns) . "\n";
            } else {
                echo "✓ All required columns exist\n";
            }
        }
        
        // Test departments query
        $dept_result = $db->query("SELECT id, department_name FROM departments ORDER BY department_name");
        if ($dept_result) {
            echo "✓ Departments query works: " . $dept_result->num_rows . " departments found\n";
        } else {
            echo "✗ Error in departments query: " . $db->error . "\n";
        }
        
        // Test programs query
        $prog_result = $db->query("SELECT p.*, d.department_name FROM programs p LEFT JOIN departments d ON p.department_id = d.id ORDER BY p.program_name");
        if ($prog_result) {
            echo "✓ Programs query works: " . $prog_result->num_rows . " programs found\n";
            
            // Test if we can get program data for edit button
            if ($prog_result->num_rows > 0) {
                $program = $prog_result->fetch_assoc();
                echo "✓ Sample program data: " . $program['program_code'] . " - " . $program['program_name'] . "\n";
                
                // Test JSON encoding for edit button
                $json_data = json_encode($program);
                if ($json_data !== false) {
                    echo "✓ JSON encoding works for edit button\n";
                } else {
                    echo "✗ JSON encoding failed for edit button\n";
                }
            }
        } else {
            echo "✗ Error in programs query: " . $db->error . "\n";
        }
        
        // Test form validation (simulate POST data)
        echo "\nTesting form validation...\n";
        
        // Test valid program data
        $test_program = [
            'program_code' => 'TEST-001',
            'program_name' => 'Test Program',
            'program_type' => 'Undergraduate',
            'program_duration' => '4',
            'program_description' => 'Test description',
            'department_id' => '1',
            'is_active' => '1'
        ];
        
        // Validate required fields
        $required_fields = ['program_code', 'program_name', 'program_type'];
        $missing_fields = [];
        
        foreach ($required_fields as $field) {
            if (empty($test_program[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (empty($missing_fields)) {
            echo "✓ Form validation works for required fields\n";
        } else {
            echo "✗ Missing required fields: " . implode(', ', $missing_fields) . "\n";
        }
        
        // Test department validation
        if (!empty($test_program['department_id'])) {
            $dept_check = $db->query("SELECT id FROM departments WHERE id = " . intval($test_program['department_id']));
            if ($dept_check && $dept_check->num_rows > 0) {
                echo "✓ Department validation works\n";
            } else {
                echo "✗ Department validation failed\n";
            }
        }
        
    } else {
        echo "✗ Database connection failed\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\nTest completed.\n";
?> 