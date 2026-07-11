<?php
require_once "connect.php";

function checkTableExists($db, $tableName) {
    $result = $db->query("SHOW TABLES LIKE '$tableName'");
    return $result->num_rows > 0;
}

function checkTableStructure($db, $tableName) {
    $result = $db->query("DESCRIBE $tableName");
    $columns = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = $row;
        }
    }
    return $columns;
}

function checkIndexes($db, $tableName) {
    $result = $db->query("SHOW INDEX FROM $tableName");
    $indexes = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $indexes[$row['Key_name']][] = $row;
        }
    }
    return $indexes;
}

// Check tables
$tables = [
    'academic_periods',
    'student_payments',
    'student_payments_backup'
];

echo "<h2>Database Structure Check</h2>";

foreach ($tables as $table) {
    echo "<h3>Table: $table</h3>";

    if (!checkTableExists($db, $table)) {
        echo "<p style='color: red'>Table does not exist!</p>";
        continue;
    }

    // Check columns
    $columns = checkTableStructure($db, $table);
    echo "<h4>Columns:</h4>";
    echo "<ul>";
    foreach ($columns as $name => $info) {
        echo "<li>" . htmlspecialchars("$name: {$info['Type']} {$info['Null']} {$info['Key']} {$info['Default']}") . "</li>";
    }
    echo "</ul>";

    // Check indexes
    $indexes = checkIndexes($db, $table);
    echo "<h4>Indexes:</h4>";
    echo "<ul>";
    foreach ($indexes as $name => $info) {
        $columns = array_map(function($idx) { return $idx['Column_name']; }, $info);
        echo "<li>" . htmlspecialchars("$name: " . implode(', ', $columns)) . "</li>";
    }
    echo "</ul>";
}

// Check academic periods data
echo "<h3>Academic Periods Data:</h3>";
$result = $db->query("SELECT * FROM academic_periods ORDER BY academic_year DESC, semester_term ASC");
if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>
        <tr>
            <th>ID</th>
            <th>Academic Year</th>
            <th>Semester</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Is Current</th>
            <th>Status</th>
        </tr>";

    while ($row = $result->fetch_assoc()) {
        echo "<tr>
            <td>{$row['id']}</td>
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

// Check student payments with academic periods
echo "<h3>Sample Student Payments with Academic Periods:</h3>";
$query = "SELECT sp.payment_id, sp.Sid, sp.amount_paid, sp.academic_period_id, 
          ap.academic_year, ap.semester_term
          FROM student_payments sp
          LEFT JOIN academic_periods ap ON sp.academic_period_id = ap.id
          LIMIT 5";
$result = $db->query($query);

if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>
        <tr>
            <th>Payment ID</th>
            <th>Student ID</th>
            <th>Amount</th>
            <th>Academic Year</th>
            <th>Semester</th>
        </tr>";

    while ($row = $result->fetch_assoc()) {
        echo "<tr>
            <td>{$row['payment_id']}</td>
            <td>{$row['Sid']}</td>
            <td>{$row['amount_paid']}</td>
            <td>{$row['academic_year']}</td>
            <td>{$row['semester_term']}</td>
        </tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red'>No payments found or not linked to academic periods!</p>";
}

$db->close(); 