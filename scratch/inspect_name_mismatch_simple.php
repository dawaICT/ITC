<?php
require dirname(__DIR__) . '/db/connect.php';

foreach (['courses', 'program_courses', 'course_levels'] as $tbl) {
    echo "=== $tbl ===\n";
    if ($r = $db->query("DESCRIBE `$tbl`")) {
        while ($row = $r->fetch_assoc()) {
            echo "  {$row['Field']}\n";
        }
    }
    echo "\n";
}

$pcCols = [];
if ($r = $db->query('SHOW COLUMNS FROM program_courses')) {
    while ($row = $r->fetch_assoc()) {
        $pcCols[strtolower($row['Field'])] = $row['Field'];
    }
}

if (isset($pcCols['course_name'])) {
    $pcn = $pcCols['course_name'];
    echo "=== pc.course_name != courses.course_name (limit 15) ===\n";
    $sql = "SELECT pc.course_code, pc.`{$pcn}` AS pc_name, c.course_name AS cat_name
            FROM program_courses pc
            INNER JOIN courses c ON TRIM(UPPER(c.course_code)) = TRIM(UPPER(pc.course_code))
            WHERE TRIM(pc.`{$pcn}`) <> TRIM(COALESCE(c.course_name,''))
            LIMIT 15";
    if ($r = $db->query($sql)) {
        $n = 0;
        while ($row = $r->fetch_assoc()) {
            echo "  {$row['course_code']}: pc='{$row['pc_name']}' | cat='{$row['cat_name']}'\n";
            $n++;
        }
        echo "  total shown: $n\n\n";
    }
}

echo "=== exact join miss but trim join hit (limit 15) ===\n";
$sql = "SELECT pc.course_code, pc.program_code
        FROM program_courses pc
        LEFT JOIN courses c1 ON c1.course_code = pc.course_code
        INNER JOIN courses c2 ON TRIM(UPPER(c2.course_code)) = TRIM(UPPER(pc.course_code))
        WHERE c1.course_code IS NULL
        LIMIT 15";
if ($r = $db->query($sql)) {
    while ($row = $r->fetch_assoc()) {
        echo "  {$row['program_code']} / {$row['course_code']}\n";
    }
    echo "  rows: {$r->num_rows}\n";
}
