<?php
require_once __DIR__ . '/../includes/DatabaseConnection.php';

$dbConnection = DatabaseConnection::getInstance();
$db = $dbConnection->getMysqli();

if (!$db) {
    die("Failed to connect to database\n");
}

echo "=== student_courses table structure ===\n";
$result = $db->query('DESCRIBE student_courses');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}

echo "\n=== course_registrations table structure ===\n";
$result = $db->query('DESCRIBE course_registrations');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}

echo "\n=== invoices table structure ===\n";
$result = $db->query('DESCRIBE invoices');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}
?>
