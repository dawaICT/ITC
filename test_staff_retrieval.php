<?php
/**
 * Test script to verify staff data retrieval
 * Run this from command line or browser to test staff loading
 */

// Define as script to bypass session checks
define('IS_SCRIPT', true);

// Include database connection
require_once __DIR__ . "/db/connect.php";

echo "=== Staff Data Retrieval Test ===\n\n";

try {
    // Check database connection
    if (!isset($db) || !$db || $db->connect_errno) {
        throw new Exception("Database connection failed: " . ($db->connect_error ?? 'Unknown error'));
    }
    
    echo "✓ Database connection successful\n";

    // Check if staff table exists
    $result = $db->query("SHOW TABLES LIKE 'staff'");
    if (!$result || $result->num_rows == 0) {
        throw new Exception("Staff table does not exist");
    }
    echo "✓ Staff table exists\n\n";

    // Check staff table structure
    echo "--- Staff Table Structure ---\n";
    $result = $db->query("DESCRIBE staff");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            echo "  - {$row['Field']} ({$row['Type']})\n";
        }
        echo "\n";
    }

    // Query staff data
    $query = "SELECT 
                staff_id, 
                title, 
                Fname, 
                Lname, 
                email,
                mobile
              FROM staff 
              ORDER BY Fname ASC, Lname ASC
              LIMIT 5";

    echo "--- Executing Query ---\n";
    echo "Query: $query\n\n";

    $result = $db->query($query);

    if (!$result) {
        throw new Exception("Query failed: " . $db->error);
    }

    $count = $result->num_rows;
    echo "✓ Query successful - Found $count staff records\n\n";

    if ($count > 0) {
        echo "--- Sample Staff Records (first 5) ---\n";
        while ($row = $result->fetch_assoc()) {
            $fullName = trim(($row['title'] ?? '') . ' ' . ($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? ''));
            echo sprintf(
                "  • %s - %s (Email: %s, Mobile: %s)\n",
                $row['staff_id'] ?? 'N/A',
                $fullName ?: 'N/A',
                $row['email'] ?? 'N/A',
                $row['mobile'] ?? 'N/A'
            );
        }
        echo "\n✓ Staff data is retrievable!\n";
    } else {
        echo "⚠ Warning: No staff records found in database\n";
        echo "  You may need to add staff members to the database.\n";
    }

    // Count total staff
    $countResult = $db->query("SELECT COUNT(*) as total FROM staff");
    if ($countResult) {
        $countRow = $countResult->fetch_assoc();
        $totalStaff = $countRow['total'];
        echo "\n--- Total Staff Count: $totalStaff ---\n";
    }

    echo "\n=== Test Complete ===\n";
    echo "The staff retrieval system is working correctly.\n";
    echo "The ajax/staff_min.php endpoint should now function properly.\n";

} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "\nPlease check:\n";
    echo "  1. Database connection settings in db/connect.php\n";
    echo "  2. Staff table exists and has data\n";
    echo "  3. Required columns (staff_id, Fname, Lname, title) exist\n";
    exit(1);
}
?>
