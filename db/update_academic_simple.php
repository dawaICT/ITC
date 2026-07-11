<?php
require_once "connect.php";

// Read SQL file
$sql = file_get_contents(__DIR__ . '/update_academic_simple.sql');

// Execute SQL
try {
    if ($db->multi_query($sql)) {
        do {
            // Store first result set
            if ($result = $db->store_result()) {
                $result->free();
            }
        } while ($db->more_results() && $db->next_result());

        echo "<h2>Update Successful!</h2>";
        echo "<p>The academic periods structure has been updated successfully.</p>";
    }
} catch (Exception $e) {
    echo "<h2>Error During Update</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";

    // If there was an error, try to rollback
    $db->query("ROLLBACK");
}

// Check the results
echo "<h2>Verification Results:</h2>";

// Check academic_periods table
$result = $db->query("SELECT * FROM academic_periods ORDER BY academic_year DESC, semester_term ASC");
if ($result && $result->num_rows > 0) {
    echo "<h3>Academic Periods:</h3>";
    echo "<table border='1' cellpadding='5'>
        <tr>
            <th>Academic Year</th>
            <th>Semester</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Is Current</th>
            <th>Status</th>
        </tr>";

    while ($row = $result->fetch_assoc()) {
        echo "<tr>
            <td>{$row['academic_year']}</td>
            <td>{$row['semester_term']}</td>
            <td>{$row['start_date']}</td>
            <td>{$row['end_date']}</td>
            <td>" . ($row['is_current'] ? 'Yes' : 'No') . "</td>
            <td>{$row['status']}</td>
        </tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red'>No academic periods found!</p>";
}

// Check student_payments structure
$result = $db->query("SHOW COLUMNS FROM student_payments WHERE Field = 'academic_period_id'");
if ($result && $result->num_rows > 0) {
    echo "<p style='color: green'>academic_period_id column exists in student_payments table.</p>";
} else {
    echo "<p style='color: red'>academic_period_id column is missing from student_payments table!</p>";
}

// Check foreign key
$result = $db->query("
    SELECT * 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_NAME = 'student_payments' 
    AND COLUMN_NAME = 'academic_period_id' 
    AND REFERENCED_TABLE_NAME = 'academic_periods'
");
if ($result && $result->num_rows > 0) {
    echo "<p style='color: green'>Foreign key constraint exists between student_payments and academic_periods.</p>";
} else {
    echo "<p style='color: red'>Foreign key constraint is missing!</p>";
}

$db->close(); 