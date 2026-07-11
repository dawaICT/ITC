<?php
/**
 * Reset test-student registration and remove e-learning test / non-curriculum courses.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/reset_test_registration_and_courses.php          # dry-run
 *   C:\xampp\php\php.exe scripts/reset_test_registration_and_courses.php --apply
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

putenv('APP_ENV=development');

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/schema_guard.php';

$apply = in_array('--apply', $argv ?? [], true);
$testStudentIds = ['CSE26456789', 'STU900'];
$testCoursePatterns = ['EL900', 'EL901', 'EL902', 'EL903', 'EL904', 'EL905', 'EL906', 'EL907', 'EL908'];
$backupDir = dirname(__DIR__, 2) . '/wucportal-var/backups';
if (!is_dir($backupDir) && !@mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
    $backupDir = sys_get_temp_dir();
}

function tableExists(mysqli $db, string $table): bool
{
    return wuc_table_exists($db, $table);
}

function columnExists(mysqli $db, string $table, string $column): bool
{
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $safeCol = preg_replace('/[^A-Za-z0-9_]/', '', $column);
    $sql = "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'";
    $cache[$key] = ($r = @$db->query($sql)) && $r->num_rows > 0;
    if ($r) {
        $r->free();
    }
    return $cache[$key];
}

function pickColumn(mysqli $db, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (columnExists($db, $table, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function fetchRows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function countDelete(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();
    return max(0, $count);
}

function backupJson(string $dir, string $name, array $data): string
{
    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $path;
}

echo ($apply ? "APPLY" : "DRY-RUN") . ": reset test registration and purge test/non-curriculum courses\n\n";

$existingStudents = [];
foreach ($testStudentIds as $sid) {
    $rows = fetchRows($db, 'SELECT SID FROM students WHERE SID = ? LIMIT 1', 's', [$sid]);
    if ($rows) {
        $existingStudents[] = $sid;
    }
}
if ($existingStudents === []) {
    echo "No test students found.\n";
    exit(0);
}
echo 'Test students: ' . implode(', ', $existingStudents) . "\n";

$programByStudent = [];
foreach ($existingStudents as $sid) {
    $row = fetchRows(
        $db,
        'SELECT program_code FROM student_program WHERE Sid = ? ORDER BY id DESC LIMIT 1',
        's',
        [$sid]
    );
    $programByStudent[$sid] = trim((string)($row[0]['program_code'] ?? ''));
    if ($programByStudent[$sid] === '') {
        $row = fetchRows($db, 'SELECT program FROM students WHERE SID = ? LIMIT 1', 's', [$sid]);
        $programByStudent[$sid] = trim((string)($row[0]['program'] ?? ''));
    }
    echo "  {$sid} program: {$programByStudent[$sid]}\n";
}

$curriculumByProgram = [];
if (tableExists($db, 'program_courses')) {
    $res = $db->query('SELECT program_code, course_code FROM program_courses');
    while ($row = $res->fetch_assoc()) {
        $prog = trim((string)$row['program_code']);
        $code = trim((string)$row['course_code']);
        if ($prog !== '' && $code !== '') {
            $curriculumByProgram[$prog][$code] = true;
        }
    }
    $res->free();
}

$crSidCol = pickColumn($db, 'course_registration', ['Sid', 'student_id', 'SID']) ?? 'Sid';
$crCourseCol = pickColumn($db, 'course_registration', ['course_code', 'Course_Code']) ?? 'course_code';
$srSidCol = pickColumn($db, 'semester_registration', ['student_id', 'Sid', 'SID']) ?? 'student_id';

$plan = [
    'semester_registration_deleted' => 0,
    'course_registration_deleted' => 0,
    'non_curriculum_deleted' => 0,
    'test_course_registrations_deleted' => 0,
    'program_courses_deleted' => 0,
    'curriculum_courses_deleted' => 0,
    'courses_deleted' => 0,
    'course_lecturer_deleted' => 0,
    'elearning_rows_deleted' => 0,
    'ai_recommendations_deleted' => 0,
    'semester_assessment_deleted' => 0,
];

$backup = [
    'generated_at' => date('c'),
    'students' => $existingStudents,
    'semester_registration' => [],
    'course_registration' => [],
];

foreach ($existingStudents as $sid) {
    $backup['semester_registration'][$sid] = fetchRows(
        $db,
        "SELECT * FROM semester_registration WHERE `{$srSidCol}` = ?",
        's',
        [$sid]
    );
    $backup['course_registration'][$sid] = fetchRows(
        $db,
        "SELECT * FROM course_registration WHERE `{$crSidCol}` = ?",
        's',
        [$sid]
    );
}

$nonCurriculumCodes = [];
foreach ($existingStudents as $sid) {
    $program = $programByStudent[$sid] ?? '';
    $allowed = $curriculumByProgram[$program] ?? [];
    foreach ($backup['course_registration'][$sid] as $row) {
        $code = trim((string)($row[$crCourseCol] ?? ''));
        if ($code === '') {
            continue;
        }
        if (!isset($allowed[$code])) {
            $nonCurriculumCodes[$code] = true;
        }
    }
}

echo "\nNon-curriculum registered codes to remove: "
    . ( $nonCurriculumCodes ? implode(', ', array_keys($nonCurriculumCodes)) : '(none)' ) . "\n";
echo 'E-learning test codes to remove: ' . implode(', ', $testCoursePatterns) . "\n";

if (!$apply) {
    echo "\nDry-run only. Re-run with --apply to execute.\n";
    exit(0);
}

$backupPath = backupJson(
    $backupDir,
    'registration_reset_' . date('Ymd_His') . '.json',
    $backup
);
echo "\nBackup written: {$backupPath}\n";

$db->begin_transaction();
try {
    foreach ($existingStudents as $sid) {
        if (tableExists($db, 'ai_recommendations')) {
            $plan['ai_recommendations_deleted'] += countDelete(
                $db,
                "DELETE FROM ai_recommendations WHERE target_user_id = ?",
                's',
                [$sid]
            );
        }

        $codesToPurge = array_unique(array_merge(
            $testCoursePatterns,
            array_keys($nonCurriculumCodes)
        ));

        if (tableExists($db, 'semester_assessment')) {
            foreach ($codesToPurge as $code) {
                $plan['semester_assessment_deleted'] += countDelete(
                    $db,
                    'DELETE FROM semester_assessment WHERE Sid = ? AND Course_Code = ?',
                    'ss',
                    [$sid, $code]
                );
            }
        }

        $plan['course_registration_deleted'] += countDelete(
            $db,
            "DELETE FROM course_registration WHERE `{$crSidCol}` = ?",
            's',
            [$sid]
        );

        $srPredicates = ["`{$srSidCol}` = ?"];
        $srTypes = 's';
        $srParams = [$sid];
        if ($srSidCol !== 'SID' && columnExists($db, 'semester_registration', 'SID')) {
            $srPredicates[] = '`SID` = ?';
            $srTypes .= 's';
            $srParams[] = $sid;
        }
        if ($srSidCol !== 'student_id' && columnExists($db, 'semester_registration', 'student_id')) {
            $srPredicates[] = '`student_id` = ?';
            $srTypes .= 's';
            $srParams[] = $sid;
        }
        $plan['semester_registration_deleted'] += countDelete(
            $db,
            'DELETE FROM semester_registration WHERE ' . implode(' OR ', $srPredicates),
            $srTypes,
            $srParams
        );
    }

    if ($testCoursePatterns !== []) {
        $ph = implode(',', array_fill(0, count($testCoursePatterns), '?'));
        $types = str_repeat('s', count($testCoursePatterns));

        $dependentTables = [
            'curriculum_courses' => 'course_code',
            'assessment_schemes' => 'course_code',
            'course_levels' => 'course_code',
            'program_courses' => 'course_code',
            'course_lecturer' => 'course_code',
        ];
        foreach ($dependentTables as $table => $column) {
            if (!tableExists($db, $table) || !columnExists($db, $table, $column)) {
                continue;
            }
            if ($table === 'program_courses') {
                $plan['program_courses_deleted'] += countDelete(
                    $db,
                    "DELETE FROM `{$table}` WHERE `{$column}` IN ({$ph})",
                    $types,
                    $testCoursePatterns
                );
                continue;
            }
            if ($table === 'course_lecturer') {
                $plan['course_lecturer_deleted'] += countDelete(
                    $db,
                    "DELETE FROM `{$table}` WHERE `{$column}` IN ({$ph})",
                    $types,
                    $testCoursePatterns
                );
                continue;
            }
            $deleted = countDelete(
                $db,
                "DELETE FROM `{$table}` WHERE `{$column}` IN ({$ph})",
                $types,
                $testCoursePatterns
            );
            if ($table === 'curriculum_courses') {
                $plan['curriculum_courses_deleted'] = ($plan['curriculum_courses_deleted'] ?? 0) + $deleted;
            }
        }

        $plan['test_course_registrations_deleted'] += countDelete(
            $db,
            "DELETE FROM course_registration WHERE `{$crCourseCol}` IN ({$ph})",
            $types,
            $testCoursePatterns
        );

        $elearningTables = [
            'el_modules', 'el_live_sessions', 'el_forums', 'el_quizzes',
            'el_assignments', 'el_progress', 'el_certificates', 'el_announcements',
            'lecturer_courses',
        ];
        foreach ($elearningTables as $table) {
            if (!tableExists($db, $table) || !columnExists($db, $table, 'course_code')) {
                continue;
            }
            $plan['elearning_rows_deleted'] += countDelete(
                $db,
                "DELETE FROM `{$table}` WHERE course_code IN ({$ph})",
                $types,
                $testCoursePatterns
            );
        }

        if (tableExists($db, 'courses')) {
            $plan['courses_deleted'] += countDelete(
                $db,
                "DELETE FROM courses WHERE course_code IN ({$ph})",
                $types,
                $testCoursePatterns
            );
        }
    }

    foreach ($existingStudents as $sid) {
        $program = $programByStudent[$sid] ?? '';
        if ($program === '') {
            continue;
        }
        $stmt = $db->prepare(
            'UPDATE students SET program = ?, year = 1 WHERE SID = ?'
        );
        $stmt->bind_param('ss', $program, $sid);
        $stmt->execute();
        $stmt->close();

        if (tableExists($db, 'student_program')) {
            $stmt = $db->prepare('DELETE FROM student_program WHERE Sid = ? AND program_code <> ?');
            $stmt->bind_param('ss', $sid, $program);
            $stmt->execute();
            $stmt->close();
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone:\n";
foreach ($plan as $key => $value) {
    echo "  {$key}: {$value}\n";
}

foreach ($existingStudents as $sid) {
    $sem = fetchRows($db, "SELECT COUNT(*) AS c FROM semester_registration WHERE `{$srSidCol}` = ?", 's', [$sid]);
    $cr = fetchRows($db, "SELECT COUNT(*) AS c FROM course_registration WHERE `{$crSidCol}` = ?", 's', [$sid]);
    echo "  {$sid} remaining sem_reg=" . ($sem[0]['c'] ?? 0) . ' course_reg=' . ($cr[0]['c'] ?? 0) . "\n";
}

echo "\nStudent can re-register at registration.php for a clean auto-enrol.\n";
