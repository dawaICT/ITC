<?php
require_once __DIR__ . '/../db/connect.php';
$r = $db->query("SELECT COUNT(*) c, SUM(is_full_year) fy FROM program_courses");
$row = $r->fetch_assoc();
echo "total={$row['c']} is_full_year_sum={$row['fy']}\n";

$r2 = $db->query("SELECT program_code, course_code, year, COUNT(*) cnt FROM program_courses GROUP BY program_code, course_code, year HAVING cnt > 1 LIMIT 10");
while ($d = $r2->fetch_assoc()) {
    echo json_encode($d) . "\n";
}
