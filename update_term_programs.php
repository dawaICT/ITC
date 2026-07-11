<?php
require_once 'db/connect.php';

echo "=== Updating term-based programs ===\n";
$db->query("UPDATE programs SET study_mode = 'term', period_mode = 'term' WHERE term_based = 1");
echo "Updated term_based programs to use term mode\n\n";

echo "=== All Programs Status ===\n";
$res = $db->query('SELECT program_code, program_name, study_mode, period_mode, term_based FROM programs');
while($r = $res->fetch_assoc()) {
    echo $r['program_code'] . ' - ' . $r['program_name'] . "\n";
    echo '  study_mode: ' . $r['study_mode'] . ', period_mode: ' . $r['period_mode'] . ', term_based: ' . $r['term_based'] . "\n";
}
