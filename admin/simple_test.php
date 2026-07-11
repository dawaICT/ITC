<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "Testing database connection...\n";

// Try to include the admin file
try {
    require_once "includes/admin.php";
    echo "✓ Admin file included successfully\n";
    
    if (isset($db)) {
        echo "✓ Database connection variable exists\n";
        
        if (!$db->connect_error) {
            echo "✓ Database connection successful\n";
            
            // Test basic queries
            $result = $db->query("SELECT COUNT(*) as count FROM departments");
            if ($result) {
                $count = $result->fetch_assoc()['count'];
                echo "✓ Departments table accessible: $count records\n";
            } else {
                echo "✗ Error accessing departments table: " . $db->error . "\n";
            }
            
            $result = $db->query("SELECT COUNT(*) as count FROM programs");
            if ($result) {
                $count = $result->fetch_assoc()['count'];
                echo "✓ Programs table accessible: $count records\n";
            } else {
                echo "✗ Error accessing programs table: " . $db->error . "\n";
            }
            
            $result = $db->query("SELECT COUNT(*) as count FROM student_program");
            if ($result) {
                $count = $result->fetch_assoc()['count'];
                echo "✓ Student_program table accessible: $count records\n";
            } else {
                echo "✗ Error accessing student_program table: " . $db->error . "\n";
            }
            
        } else {
            echo "✗ Database connection failed: " . $db->connect_error . "\n";
        }
    } else {
        echo "✗ Database connection variable not set\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\nTest completed.\n";
?> 