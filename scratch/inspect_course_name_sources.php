<?php
require dirname(__DIR__) . '/db/connect.php';

echo "=== course_levels columns ===\n";
if ($r = $db->query('DESCRIBE course_levels')) {
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . ' ' . $row['Type'] . "\n";
    }
}

echo "\n=== program_courses columns ===\n";
if ($r = $db->query('DESCRIBE program_courses')) {
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . ' ' . $row['Type'] . "\n";
    }
}

echo "\n=== courses columns ===\n";
if ($r = $db->query('DESCRIBE courses')) {
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . ' ' . $row['Type'] . "\n";
    }
}

// Find name mismatches: program_courses exact join vs TRIM/UPPER join
echo "\n=== Join mismatch samples (pc exact join empty, trim/upper has name) ===\n";
$sql = "SELECT pc.course_code, pc.program_code,
        COALESCE(c1.course_name,'') AS exact_name,
        COALESCE(c2.course_name,'') AS trim_name
    FROM program_courses pc
    LEFT JOIN courses c1 ON c1.course_code = pc.course_code
    LEFT JOIN courses c2 ON TRIM(UPPER(c2.course_code)) = TRIM(UPPER(pc.course_code))
    WHERE COALESCE(c1.course_name,'') <> COALESCE(c2.course_name,'')
    LIMIT 20";
if ($r = $db->query($sql)) {
    while ($row = $r->fetch_assoc()) {
        echo "{$row['program_code']} {$row['course_code']}: exact='{$row['exact_name']}' trim='{$row['trim_name']}'\n";
    }
    echo "rows: " . $r->num_rows . "\n";
}

// program_courses has course_name column?
$pcCols = [];
if ($r = $db->query('SHOW COLUMNS FROM program_courses')) {
    while ($row = $r->fetch_assoc()) {
        $pcCols[strtolower($row['Field'])] = $row['Field'];
    }
}
if (isset($pcCols['course_name'])) {
    echo "\n=== program_courses.course_name vs courses.course_name mismatches ===\n";
    $col = $pcCols['course_name'];
    $sql = "SELECT pc.course_code, pc.`{$col}` AS pc_name, COALESCE(c.course_name,'') AS cat_name
            FROM program_courses pc
            LEFT JOIN courses c ON TRIM(UPPER(c.course_code)) = TRIM(UPPER(pc.course_code))
            WHERE pc.`{$col}` <> COALESCE(c.course_name,'') AND pc.`{$col}` <> ''
            LIMIT 20";
    if ($r = $db->query($sql)) {
        while ($row = $r->fetch_assoc()) {
            echo "{$row['course_code']}: pc='{$row['pc_name']}' catalog='{$row['cat_name']}'\n";
        }
        echo "rows: " . $r->num_rows . "\n";
    }
}

// Students with semester registration
echo "\n=== Active semester registrations (sample) ===\n";
if ($r = $db->query("SELECT DISTINCT sr.student_id, sr.program_code, sr.year_of_study, sr.semester
    FROM semester_registration sr
    ORDER BY sr.id DESC LIMIT 10")) {
    while ($row = $r->fetch_assoc()) {
        echo implode(' | ', $row) . "\n";
    }
}
