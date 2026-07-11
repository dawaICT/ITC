<?php
/**
 * Fix credit hours for courses with 0 or NULL credits
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/includes/DatabaseConnection.php';

$db = DatabaseConnection::getInstance();
$mysqli = $db->getMysqli();

echo "=== Fixing Course Credit Hours ===\n\n";

// Check current state
$res = $mysqli->query("SELECT course_code, course_name, credit_hours, credits FROM courses WHERE course_code IN ('COM101', 'MAT101')");
echo "Before fix:\n";
while ($row = $res->fetch_assoc()) {
    echo "  {$row['course_code']}: credit_hours=" . ($row['credit_hours'] ?? 'NULL') . ", credits=" . ($row['credits'] ?? 'NULL') . "\n";
}

// Update credit hours (standard 3 credits for these foundation courses)
$stmt = $mysqli->prepare("UPDATE courses SET credit_hours = 3 WHERE course_code IN ('COM101', 'MAT101') AND (credit_hours IS NULL OR credit_hours = 0)");
$stmt->execute();
echo "\nUpdated " . $mysqli->affected_rows . " course(s)\n";

// Verify
$res = $mysqli->query("SELECT course_code, course_name, credit_hours FROM courses WHERE course_code IN ('COM101', 'MAT101')");
echo "\nAfter fix:\n";
while ($row = $res->fetch_assoc()) {
    echo "  OK {$row['course_code']}: {$row['course_name']} - {$row['credit_hours']} credits\n";
}

echo "\n=== Done ===\n";
