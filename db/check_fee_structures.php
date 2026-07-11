<?php
require_once __DIR__ . '/connect.php';
$result = $db->query("SELECT COUNT(*) as c FROM fee_structures");
$row = $result ? $result->fetch_assoc() : null;
echo "fee_structures count: " . ($row['c'] ?? '0') . "\n";
$result2 = $db->query("SELECT program_code, academic_year, semester, year_of_study, amount FROM fee_structures LIMIT 10");
if ($result2) {
    while ($r = $result2->fetch_assoc()) {
        echo implode(' | ', [$r['program_code'], $r['academic_year'], $r['semester'], $r['year_of_study'], $r['amount']]) . "\n";
    }
} else {
    echo "No rows or query failed.\n";
}
