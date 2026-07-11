<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) {
    echo "Connection failed: " . $db->connect_error . "\n";
    exit(1);
}

$tables = [
    'elearning_courses',
    'elearning_modules',
    'elearning_resources',
    'elearning_enrollments'
];

echo "=== Elearning Tables Check ===\n\n";
foreach ($tables as $t) {
    $res = $db->query("SHOW TABLES LIKE '$t'");
    if ($res && $res->num_rows > 0) {
        echo "$t: EXISTS\n";
        $cols = $db->query("SHOW COLUMNS FROM `$t`");
        while ($c = $cols->fetch_assoc()) {
            echo " - " . $c['Field'] . " (" . $c['Type'] . ")\n";
        }
        echo "\n";
    } else {
        echo "$t: MISSING\n\n";
    }
}

$db->close();
?>
<?php
require_once __DIR__ . '/db/connect.php';

echo "Checking eLearning tables...\n\n";

$res = $db->query("SHOW TABLES LIKE 'el_%'");
if ($res && $res->num_rows > 0) {
    echo "Found tables:\n";
    while ($row = $res->fetch_array()) {
        echo "  - " . $row[0] . "\n";
    }
} else {
    echo "No el_ tables found. Need to run schema.\n";
}

echo "\n\nChecking course_registration or similar tables...\n";
$tables = ['course_registration', 'registered_courses', 'student_courses'];
foreach ($tables as $t) {
    $res = $db->query("SHOW TABLES LIKE '$t'");
    if ($res && $res->num_rows > 0) {
        echo "  - $t EXISTS\n";
        // Show columns
        $cols = $db->query("SHOW COLUMNS FROM $t");
        if ($cols) {
            while ($c = $cols->fetch_assoc()) {
                echo "      Column: " . $c['Field'] . " (" . $c['Type'] . ")\n";
            }
        }
    } else {
        echo "  - $t NOT FOUND\n";
    }
}

echo "\nDone.\n";
