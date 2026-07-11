<?php
require_once 'db/connect.php';

// Corrected query using existing columns
$query = "SELECT course_code, course_name, credits, course_fee FROM courses WHERE status = 'active'";

$result = $db->query($query);

if ($result && $result->num_rows > 0) {
    echo "<table border='1'>";
    echo "<tr><th>Course Code</th><th>Course Name</th><th>Credits</th><th>Course Fee</th></tr>";

    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['course_code'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($row['course_name'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($row['credits'] ?? '0') . "</td>";
        echo "<td>" . htmlspecialchars($row['course_fee'] ?? '0.00') . "</td>";
        echo "</tr>";
    }

    echo "</table>";
} else {
    echo "No active courses found.";
}

$db->close();
?>
