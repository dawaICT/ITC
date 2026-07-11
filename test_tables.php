<?php
// Test table existence and counts
require_once __DIR__ . '/students/includes/guard.php';

echo "=== Table Existence Check ===\n";
$tables = ['semester_registration', 'course_registration', 'course_levels', 'courses', 'students', 'programs'];

foreach ($tables as $t) {
    $r = $db->query("SHOW TABLES LIKE '{$t}'");
    $exists = ($r && $r->num_rows > 0);
    echo $t . ': ' . ($exists ? 'EXISTS' : 'MISSING') . "\n";
    if ($r) $r->free();
}

echo "\n=== Record Counts ===\n";
foreach ($tables as $t) {
    $r = $db->query("SELECT COUNT(*) as cnt FROM {$t}");
    if ($r) {
        $row = $r->fetch_assoc();
        echo $t . ': ' . $row['cnt'] . ' records' . "\n";
        $r->free();
    }
}
