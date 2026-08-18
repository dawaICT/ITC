<?php
require_once dirname(__DIR__) . '/db/connect.php';
foreach (['exam_schedule', 'classrooms', 'course_lecturer', 'academic_periods', 'programs', 'courses', 'departments'] as $t) {
    echo "=== $t ===\n";
    $r = @$db->query("DESCRIBE `$t`");
    if (!$r) {
        echo "MISSING\n";
        continue;
    }
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . ' ' . $row['Type'] . "\n";
    }
}
