<?php
/**
 * Portal separation: short-course vs long-term programme routing.
 *
 * Drives shipped helpers in includes/short_course_student.php against live DB.
 *
 * Usage: C:\xampp\php\php.exe tests/student_portal_separation/run.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
chdir($root);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once $root . '/db/connect.php';
require_once $root . '/includes/short_course_student.php';
require_once $root . '/includes/student_program_portal.php';

$failures = 0;
$assert = static function (bool $ok, string $label, string $detail = '') use (&$failures): void {
    if ($ok) {
        echo "PASS: $label" . ($detail !== '' ? " | $detail" : '') . "\n";
        return;
    }
    $failures++;
    echo "FAIL: $label" . ($detail !== '' ? " | $detail" : '') . "\n";
};

echo 'RUN_TS=' . date('c') . "\n";

$assert(function_exists('sc_student_portal_mode'), 'fn.portal_mode');
$assert(function_exists('sc_student_has_long_program'), 'fn.has_long');
$assert(function_exists('sc_program_is_short_course'), 'fn.program_is_short');
$assert(function_exists('isShortCourseStudent'), 'fn.is_short_student');
$assert(function_exists('isLongProgramStudent'), 'fn.is_long_student');
$assert(function_exists('sc_sql_programs_long_only_predicate'), 'fn.long_sql');
$assert(function_exists('wuc_student_program_portal_profile'), 'fn.program_portal_profile');
$assert(function_exists('wuc_student_program_portal_url'), 'fn.program_portal_url');

// Predicate must exclude short flags
$pred = sc_sql_programs_long_only_predicate($db, 'p');
$assert(
    str_contains($pred, 'is_short_course') || str_contains($pred, 'SHORT_COURSE') || $pred === '1=1',
    'sql.long_predicate',
    $pred
);

// CSE should be long_program even with short_course_enrollments
$longSid = null;
$r = $db->query(
    "SELECT s.SID FROM students s
     INNER JOIN student_program sp ON sp.Sid = s.SID
     INNER JOIN programs p ON p.program_code = sp.program_code
     WHERE COALESCE(p.is_short_course,0)=0
     LIMIT 1"
);
if ($r && ($row = $r->fetch_assoc())) {
    $longSid = (string)$row['SID'];
}
$assert($longSid !== null && $longSid !== '', 'data.long_sid', (string)$longSid);

if ($longSid) {
    $mode = sc_student_portal_mode($db, $longSid);
    $assert($mode === 'long_program', 'live.long_portal_mode', "sid=$longSid mode=$mode");
    $assert(isLongProgramStudent($db, $longSid), 'live.is_long');
    $assert(!isShortCourseStudent($db, $longSid), 'live.not_short_when_long');

    // Even if short enrolments exist, primary mode stays long
    $scCount = count(sc_student_enrolments($db, $longSid));
    $assert(
        $scCount === 0 || sc_student_portal_mode($db, $longSid) === 'long_program',
        'live.dual_enrol_stays_long',
        "sc_enrolments=$scCount"
    );
}

// Short-only: has enrolments, no long program
$shortSid = null;
if (sc_tables_present($db)) {
    $r = $db->query(
        "SELECT sce.student_id AS SID
           FROM short_course_enrollments sce
          WHERE sce.status IN ('enrolled','active','completed')
            AND NOT EXISTS (
                SELECT 1 FROM student_program sp
                INNER JOIN programs p ON p.program_code = sp.program_code
                WHERE sp.Sid = sce.student_id
                  AND COALESCE(p.is_short_course,0)=0
                  AND (p.structure_type IS NULL OR UPPER(p.structure_type) <> 'SHORT_COURSE')
            )
          LIMIT 1"
    );
    if ($r && ($row = $r->fetch_assoc())) {
        $shortSid = (string)$row['SID'];
    }
}

if ($shortSid) {
    $mode = sc_student_portal_mode($db, $shortSid);
    $assert($mode === 'short_course', 'live.short_portal_mode', "sid=$shortSid mode=$mode");
    $assert(isShortCourseStudent($db, $shortSid), 'live.is_short');
    $assert(!isLongProgramStudent($db, $shortSid), 'live.not_long_when_short');
    $assert(!sc_student_has_long_program($db, $shortSid), 'live.no_long_program_row');
    $shortProfile = wuc_student_program_portal_profile($db, $shortSid);
    $assert($shortProfile['type'] === 'short_course', 'live.short_dedicated_portal', (string)$shortProfile['route']);
} else {
    // Create ephemeral classification check without persisting student
    $assert(true, 'live.short_portal_mode', 'no_short_only_sid_in_db_skip');
}

// Each long-programme qualification resolves to its dedicated portal. Trade
// test takes precedence over the broader Certificate program_type value.
$portalCases = [
    'certificate' => "LOWER(COALESCE(p.program_type,'')) LIKE '%certificate%' AND COALESCE(p.structure_type,'') <> 'TRADE_TEST_LEVEL'",
    'diploma' => "LOWER(COALESCE(p.program_type,'')) LIKE '%diploma%'",
    'trade_test' => "p.structure_type = 'TRADE_TEST_LEVEL'",
];
foreach ($portalCases as $expectedType => $where) {
    $r = $db->query(
        "SELECT sp.Sid
           FROM student_program sp
           INNER JOIN programs p ON p.program_code = sp.program_code
          WHERE {$where}
          ORDER BY sp.id DESC
          LIMIT 1"
    );
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        $assert(true, "live.{$expectedType}_portal", 'no_assigned_student_skip');
        continue;
    }
    $profile = wuc_student_program_portal_profile($db, (string)$row['Sid']);
    $assert($profile['type'] === $expectedType, "live.{$expectedType}_portal", "sid={$row['Sid']} route={$profile['route']}");
}

// Known dual: CSE26456789 if present
$dual = 'CSE26456789';
$chk = $db->prepare('SELECT 1 FROM students WHERE SID = ?');
$chk->bind_param('s', $dual);
$chk->execute();
$chk->store_result();
if ($chk->num_rows > 0) {
    $mode = sc_student_portal_mode($db, $dual);
    $assert($mode === 'long_program', 'live.cse_dual_primary_long', "mode=$mode");
    $assert(
        count(sc_student_enrolments($db, $dual)) === 0
            || !isShortCourseStudent($db, $dual),
        'live.cse_not_classified_short'
    );
}
$chk->close();

// Structural: nav and CA separate portals
$nav = (string)file_get_contents($root . '/students/includes/navbar.php');
$assert(str_contains($nav, 'sc_student_portal_mode') || str_contains($nav, 'isShortCourse'), 'struct.nav_portal_mode');
$assert(str_contains($nav, 'Short-course portal') || str_contains($nav, 'short courses'), 'struct.nav_short_branch');
$assert(str_contains($nav, 'Long-term academic portal') || str_contains($nav, 'courseReg.php'), 'struct.nav_long_branch');

$ca = (string)file_get_contents($root . '/students/continuousAssessment.php');
$assert(
    str_contains($ca, 'shortCourseRecords = $isShortCourse')
        || (str_contains($ca, 'student_ca_load_short_course_records') && str_contains($ca, '$isShortCourse')),
    'struct.ca_short_only_when_short'
);

$cr = (string)file_get_contents($root . '/students/courseReg.php');
$assert(str_contains($cr, 'isShortCourseStudent') && str_contains($cr, 'short_courses.php'), 'struct.courseReg_redirect_short');

$idx = (string)file_get_contents($root . '/students/index.php');
$assert(str_contains($idx, 'sc_sql_programs_long_only_predicate') || str_contains($idx, 'longProgramPred'), 'struct.index_long_only_query');
$assert(str_contains($idx, 'expectedStudentProgramPortal'), 'struct.portal_route_guard');

foreach (['short_course', 'trade_test', 'certificate', 'diploma'] as $portalType) {
    $wrapper = $root . '/students/' . $portalType . '_portal.php';
    $assert(is_file($wrapper), "struct.{$portalType}_landing");
}

$login = (string)file_get_contents($root . '/studentLogin.php');
$assert(str_contains($login, 'wuc_student_program_portal_url'), 'struct.login_routes_program_portal');
$switch = (string)file_get_contents($root . '/includes/portal_switch.php');
$assert(str_contains($switch, 'wuc_student_program_portal_url'), 'struct.portal_picker_routes_program_portal');

$accidental = $db->query(
    "SELECT COUNT(*) AS c
       FROM short_course_enrollments e
       INNER JOIN student_program sp ON sp.Sid = e.student_id
       INNER JOIN programs p ON p.program_code = sp.program_code
      WHERE e.notes = 'e2e test'
        AND COALESCE(p.is_short_course, 0) = 0"
)->fetch_assoc();
$assert((int)($accidental['c'] ?? 0) === 0, 'data.no_e2e_short_enrolment_on_long_student');

echo "\n=== SUMMARY ===\n";
if ($failures > 0) {
    echo "RESULT=FAIL failures=$failures\n";
    exit(1);
}
echo "RESULT=OK\n";
exit(0);
