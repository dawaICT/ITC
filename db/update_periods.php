<?php
require_once "connect.php";

// Read SQL file
$sql = file_get_contents(__DIR__ . '/update_academic_periods.sql');

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
        echo "<p>Academic periods have been updated successfully.</p>";
    }
} catch (Exception $e) {
    echo "<h2>Error During Update</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";

    // If there was an error, try to rollback
    $db->query("ROLLBACK");
}

// Verify the update
echo "<h2>Current Academic Periods:</h2>";
$result = $db->query("SELECT * FROM academic_periods ORDER BY academic_year DESC, semester_term ASC");

if ($result && $result->num_rows > 0) {
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

    // Show sample of linked payments
    echo "<h2>Sample Payments with Academic Periods:</h2>";
    $query = "
        SELECT 
            sp.payment_id,
            sp.Sid,
            sp.amount_paid,
            sp.payment_date,
            ap.academic_year,
            ap.semester_term
        FROM student_payments sp
        LEFT JOIN academic_periods ap ON sp.academic_period_id = ap.id
        ORDER BY sp.payment_date DESC
        LIMIT 5
    ";

    $result = $db->query($query);
    if ($result && $result->num_rows > 0) {
        echo "<table border='1' cellpadding='5'>
            <tr>
                <th>Payment ID</th>
                <th>Student ID</th>
                <th>Amount</th>
                <th>Payment Date</th>
                <th>Academic Year</th>
                <th>Semester</th>
            </tr>";

        while ($row = $result->fetch_assoc()) {
            echo "<tr>
                <td>{$row['payment_id']}</td>
                <td>{$row['Sid']}</td>
                <td>ZMK " . number_format($row['amount_paid'], 2) . "</td>
                <td>{$row['payment_date']}</td>
                <td>{$row['academic_year']}</td>
                <td>{$row['semester_term']}</td>
            </tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No payments found.</p>";
    }
} else {
    echo "<p style='color: red'>No academic periods found!</p>";
}

$db->close(); 