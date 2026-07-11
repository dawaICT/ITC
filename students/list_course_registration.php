<?php
// List course_registration rows for a student
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/includes/DatabaseConnection.php';
$db = DatabaseConnection::getInstance();
$mysqli = $db->getMysqli();

$sid = $argv[1] ?? $_REQUEST['student_id'] ?? null;
if (!$sid) { echo "Usage: php list_course_registration.php <student_id>\n"; exit(1); }

// Discover available columns in course_registration
$cols = [];
$meta = $mysqli->query('SHOW COLUMNS FROM course_registration');
while ($c = $meta->fetch_assoc()) {
    $cols[strtolower($c['Field'])] = $c['Field'];
}

$whereParts = [];
$params = [];
if (isset($cols['sid'])) { $whereParts[] = "`{$cols['sid']}` = '" . $mysqli->real_escape_string($sid) . "'"; }
if (isset($cols['student_id'])) { $whereParts[] = "`{$cols['student_id']}` = '" . $mysqli->real_escape_string($sid) . "'"; }
if (empty($whereParts)) {
    echo "ERROR: No identifiable student column in course_registration (expected 'Sid' or 'student_id').\n";
    exit(1);
}

$query = 'SELECT * FROM course_registration WHERE ' . implode(' OR ', $whereParts);
$res = $mysqli->query($query);
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

echo "Found " . count($rows) . " row(s) for student $sid\n";
foreach ($rows as $r) {
    echo "- course_code=" . ($r['course_code'] ?? $r['CourseCode'] ?? 'N/A') . ", ";
    echo "semester_registration_id=" . ($r['semester_registration_id'] ?? $r['SemesterRegistrationId'] ?? 'NULL') . ", ";
    echo "Sid=" . ($r['Sid'] ?? $r['student_id'] ?? 'NULL') . "\n";
}

file_put_contents(__DIR__ . "/list_course_registration_backup_{$sid}.json", json_encode($rows, JSON_PRETTY_PRINT));
if (count($rows) > 0) echo "\nFull rows saved to students/list_course_registration_backup_{$sid}.json\n";
