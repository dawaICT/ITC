<?php
require_once __DIR__ . '/../db/connect.php';
foreach (['academic_periods', 'semester_registration', 'course_registration', 'programs'] as $t) {
    echo "=== $t ===\n";
    $r = @$db->query("DESCRIBE `$t`");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            echo $row['Field'] . ' ' . $row['Type'] . "\n";
        }
    } else {
        echo "MISSING\n";
    }
}
