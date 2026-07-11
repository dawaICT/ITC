<?php
declare(strict_types=1);
$env = getenv('WUC_CONFIG_FILE') ?: 'C:\xampp\wucportal-var\config\environment.php';
putenv('WUC_CONFIG_FILE=' . $env);
require_once dirname(__DIR__) . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$tables = ['programs','courses','program_courses','student_program','students','course_registration','semester_registration','intakes','academic_years','terms','semesters','timetables','curriculum_versions','curriculum_courses','course_offerings'];

echo "=== TABLE EXISTENCE ===\n";
foreach ($tables as $t) {
    $r = $db->query("SHOW TABLES LIKE '$t'");
    echo "$t: " . ($r->num_rows ? 'EXISTS' : 'MISSING') . "\n";
}

echo "\n=== PROGRAMS COLUMNS ===\n";
$r = $db->query('DESCRIBE programs');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . ($row['Null'] ?? '') . ' | ' . ($row['Default'] ?? 'NULL') . "\n";
}

echo "\n=== PROGRAM period_mode / structure_type DATA ===\n";
$r = $db->query("SELECT program_code, program_name, period_mode, structure_type, study_mode, uses_terms, uses_semesters, is_short_course, academic_structure FROM programs ORDER BY program_code LIMIT 50");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== PROGRAM_COURSES COLUMNS ===\n";
if ($db->query("SHOW TABLES LIKE 'program_courses'")->num_rows) {
    $r = $db->query('DESCRIBE program_courses');
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . ' | ' . $row['Type'] . "\n";
    }
}

echo "\n=== SAMPLE program_courses period fields ===\n";
if ($db->query("SHOW TABLES LIKE 'program_courses'")->num_rows) {
    $r = $db->query("SELECT program_code, course_code, year, semester, term FROM program_courses LIMIT 20");
    while ($row = $r->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
}

echo "\n=== INCONSISTENT program_courses (term program with semester data) ===\n";
require_once dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';
$r = $db->query("SELECT pc.program_code, pc.course_code, pc.year, pc.semester, pc.term, p.period_mode, p.structure_type FROM program_courses pc JOIN programs p ON p.program_code = pc.program_code LIMIT 500");
$issues = [];
while ($row = $r->fetch_assoc()) {
    $st = wuc_program_structure_type($db, $row['program_code']);
    $kind = wuc_structure_period_kind($st);
    if ($kind === 'term' && !empty($row['semester']) && empty($row['term'])) {
        $issues[] = $row;
    } elseif ($kind === 'semester' && !empty($row['term']) && empty($row['semester'])) {
        $issues[] = $row;
    }
}
echo "Found " . count($issues) . " mismatches\n";
foreach (array_slice($issues, 0, 15) as $i) {
    echo json_encode($i) . "\n";
}

echo "\nDONE\n";
