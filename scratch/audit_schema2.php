<?php
declare(strict_types=1);
putenv('WUC_CONFIG_FILE=C:\xampp\wucportal-var\config\environment.php');
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

echo "=== program_courses by program structure ===\n";
$r = $db->query("SELECT p.program_code, p.structure_type, p.period_mode, COUNT(*) cnt,
    MIN(pc.semester) min_sem, MAX(pc.semester) max_sem, MIN(pc.year) min_yr, MAX(pc.year) max_yr
    FROM program_courses pc JOIN programs p ON p.program_code = pc.program_code
    GROUP BY p.program_code, p.structure_type, p.period_mode ORDER BY p.program_code");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== TERM programs with semester > 3 ===\n";
$r = $db->query("SELECT pc.*, p.structure_type FROM program_courses pc
    JOIN programs p ON p.program_code = pc.program_code
    WHERE p.structure_type = 'TERM_BASED' AND pc.semester > 3 LIMIT 20");
while ($row = $r->fetch_assoc()) { echo json_encode($row) . "\n"; }

echo "\n=== SEMESTER programs with semester > 2 ===\n";
$r = $db->query("SELECT pc.*, p.structure_type FROM program_courses pc
    JOIN programs p ON p.program_code = pc.program_code
    WHERE p.structure_type = 'SEMESTER_BASED' AND pc.semester > 2 LIMIT 20");
while ($row = $r->fetch_assoc()) { echo json_encode($row) . "\n"; }

echo "\n=== curriculum_courses columns ===\n";
$r = $db->query('DESCRIBE curriculum_courses');
while ($row = $r->fetch_assoc()) { echo $row['Field'].' | '.$row['Type']."\n"; }

echo "\n=== curriculum mismatches (term program with semester_number set) ===\n";
$r = $db->query("SELECT cv.program_code, cc.course_code, cc.year_number, cc.term_number, cc.semester_number, p.structure_type
    FROM curriculum_courses cc
    JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
    JOIN programs p ON p.program_code = cv.program_code
    WHERE p.structure_type = 'TERM_BASED' AND cc.term_number IS NULL AND cc.semester_number IS NOT NULL
    LIMIT 20");
$cnt = 0;
while ($row = $r->fetch_assoc()) { echo json_encode($row) . "\n"; $cnt++; }
echo "count shown: $cnt\n";

echo "\n=== curriculum mismatches (semester program with term_number set) ===\n";
$r = $db->query("SELECT cv.program_code, cc.course_code, cc.year_number, cc.term_number, cc.semester_number, p.structure_type
    FROM curriculum_courses cc
    JOIN curriculum_versions cv ON cv.id = cc.curriculum_version_id
    JOIN programs p ON p.program_code = cv.program_code
    WHERE p.structure_type = 'SEMESTER_BASED' AND cc.semester_number IS NULL AND cc.term_number IS NOT NULL
    LIMIT 20");
while ($row = $r->fetch_assoc()) { echo json_encode($row) . "\n"; }

echo "\n=== course_registration sample columns ===\n";
$r = $db->query('DESCRIBE course_registration');
while ($row = $r->fetch_assoc()) { echo $row['Field'].' | '.$row['Type']."\n"; }

echo "\n=== semester_registration sample ===\n";
$r = $db->query('DESCRIBE semester_registration');
while ($row = $r->fetch_assoc()) { echo $row['Field'].' | '.$row['Type']."\n"; }

echo "\n=== student_program period columns ===\n";
$r = $db->query('DESCRIBE student_program');
while ($row = $r->fetch_assoc()) { echo $row['Field'].' | '.$row['Type']."\n"; }

echo "\nDONE\n";
