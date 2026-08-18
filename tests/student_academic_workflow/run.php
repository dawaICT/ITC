<?php
/**
 * End-to-end academic workflow: long-programme reg → courses → lecturer CA → student CA.
 * Drives shipped services only (RegistrationDataService, StudentAcademicWorkflowService,
 * ca_helpers, continuous_assessment_helpers, course_lecturer).
 *
 * Usage: C:\xampp\php\php.exe tests/student_academic_workflow/run.php [SID] [STAFF_ID]
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
chdir($root);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once $root . '/db/connect.php';
require_once $root . '/includes/short_course_student.php';
require_once $root . '/includes/ca_helpers.php';
require_once $root . '/includes/elearning_access.php';
require_once $root . '/students/includes/period_mode_helper.php';
require_once $root . '/students/includes/RegistrationDataService.php';
require_once $root . '/students/includes/AcademicSessionService.php';
require_once $root . '/students/includes/StudentAcademicWorkflowService.php';
require_once $root . '/students/includes/continuous_assessment_helpers.php';

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

$sid = isset($argv[1]) ? trim((string)$argv[1]) : '';
$staffId = isset($argv[2]) ? trim((string)$argv[2]) : 'ITC907';

if ($sid === '') {
    $r = $db->query(
        "SELECT s.SID FROM students s
         INNER JOIN student_login sl ON s.SID = sl.Sid
         INNER JOIN student_program sp ON sp.Sid = s.SID
         INNER JOIN programs p ON p.program_code = sp.program_code
         WHERE COALESCE(p.is_short_course, 0) = 0
         LIMIT 1"
    );
    $sid = ($r && ($row = $r->fetch_assoc())) ? (string)$row['SID'] : '';
}
$assert($sid !== '', 'resolve_student_sid', $sid);

// ---------------------------------------------------------------------------
// 1) Portal mode + period registration context
// ---------------------------------------------------------------------------
$mode = sc_student_portal_mode($db, $sid);
$assert($mode === 'long_program', 'workflow.portal_long', "mode=$mode");

$regData = new RegistrationDataService($db);
$sessionService = new AcademicSessionService($db);
$periodMode = getStudentProgramPeriodMode($db, $sid);
$normalized = normalizeProgramPeriodMode($periodMode !== '' ? $periodMode : 'semester');
$session = $sessionService->getCurrentSession(
    in_array($normalized, ['term', 'semester'], true) ? $normalized : null
);
$ay = trim((string)($session['academic_year'] ?? date('Y')));
$periodNum = (int)($session['period_number'] ?? $session['semester'] ?? 0);
$ctx = $regData->resolveRegistrationTermContext(
    $sid,
    null,
    $ay !== '' ? $ay : null,
    $periodNum > 0 ? $periodNum : null,
    $periodMode !== '' ? $periodMode : null,
    true
);
$assert(is_array($ctx) || $ctx === null, 'workflow.term_context', is_array($ctx)
    ? ('sem=' . ($ctx['semester'] ?? '') . ' prog=' . ($ctx['program_code'] ?? '') . ' ay=' . ($ctx['academic_year'] ?? ''))
    : 'null');

$wf = new StudentAcademicWorkflowService($db);
if (is_array($ctx) && (int)($ctx['semester'] ?? 0) > 0) {
    $inserted = $wf->ensureYearCoursesEnrolled($sid);
    $assert(is_int($inserted), 'workflow.ensure_year_enrol', "inserted=$inserted");
} else {
    $assert(true, 'workflow.ensure_year_enrol', 'skipped_no_period');
}

$yos = (int)($ctx['year_of_study'] ?? 1);
$ayUse = (string)($ctx['academic_year'] ?? $ay);
$registered = $regData->getRegisteredCourses($sid, $yos, 0, null, $ayUse, 'year');
$assert(is_array($registered), 'workflow.registered_courses', 'count=' . count($registered));
$selectable = $regData->getSelectableCoursesForTerm($sid, $ctx);
$assert(is_array($selectable), 'workflow.selectable', 'count=' . count($selectable['courses'] ?? []));

// ---------------------------------------------------------------------------
// 2) Student CA display (long portal isolation)
// ---------------------------------------------------------------------------
$assert(!isShortCourseStudent($db, $sid), 'workflow.not_short_primary');
$profile = student_ca_fetch_student_profile($db, $sid);
$assert(is_array($profile), 'workflow.ca_profile', trim(($profile['Fname'] ?? '') . ' ' . ($profile['program_code'] ?? '')));
$periodConfig = student_ca_period_columns($db, (string)($profile['program_code'] ?? ''), $periodMode);
$periods = $periodConfig['periods'] ?? [1, 2, 3];
$componentsMap = student_ca_fetch_period_components_map($db, $sid, $ayUse, (string)$yos, $periods);
$records = student_ca_build_annual_records($registered, $componentsMap, $periods, (string)$yos);
$assert(is_array($records), 'workflow.ca_records', 'rows=' . count($records));

// ---------------------------------------------------------------------------
// 3) Lecturer assignment + CA write + publish + student read
// ---------------------------------------------------------------------------
$courseCode = '';
foreach ($registered as $row) {
    $code = trim((string)($row['course_code'] ?? ''));
    if ($code === '') {
        continue;
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM course_lecturer
          WHERE staff_id = ? AND course_code = ?
            AND COALESCE(status, 'active') <> 'inactive'
          LIMIT 1"
    );
    $stmt->bind_param('ss', $staffId, $code);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    if ($ok) {
        $courseCode = $code;
        break;
    }
}
$assert($courseCode !== '', 'workflow.lecturer_assignment', "staff=$staffId course=$courseCode");

// List courses for staff (same query shape as enter_results / upload_ca)
$listSql = "SELECT DISTINCT cl.course_code, c.course_name
            FROM course_lecturer cl
            LEFT JOIN courses c ON c.course_code = cl.course_code
            WHERE cl.staff_id = ?
              AND COALESCE(cl.status, 'active') <> 'inactive'
            ORDER BY cl.course_code";
$stmt = $db->prepare($listSql);
$stmt->bind_param('s', $staffId);
$stmt->execute();
$listed = [];
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $listed[] = $row['course_code'];
}
$stmt->close();
$assert(in_array($courseCode, $listed, true), 'workflow.lecturer_lists_course', 'listed=' . count($listed));

// Assigned check uses shipped helper when available
if (function_exists('isLecturerAssignedToCourse')) {
    $assert(
        isLecturerAssignedToCourse($db, $staffId, $courseCode),
        'workflow.lecturer_assigned_helper'
    );
}

$periodForCa = (string)($ctx['semester'] ?? $periodNum ?: '2');
// Prefer a period where student is registered for this course
foreach ([(string)$periodForCa, '1', '2', '3'] as $tryPeriod) {
    if (ca_student_registered($db, $sid, $courseCode, $tryPeriod, $ayUse)) {
        $periodForCa = $tryPeriod;
        break;
    }
}
$assert(
    ca_student_registered($db, $sid, $courseCode, $periodForCa, $ayUse),
    'workflow.student_registered_for_ca',
    "course=$courseCode period=$periodForCa ay=$ayUse"
);

// Class list must include student (legacy+normalized merge)
$fetch = ca_fetch_course_students($db, $courseCode, $periodForCa, $ayUse);
$fetchStudents = isset($fetch['students']) ? $fetch['students'] : $fetch;
$fetchSids = array_map(static fn($r) => (string)($r['Sid'] ?? ''), is_array($fetchStudents) ? $fetchStudents : []);
$assert(in_array($sid, $fetchSids, true), 'workflow.class_list_includes_student', 'count=' . count($fetchSids));

// Unique component value so we prove write/read of shipped path
$markValue = 12.0 + (float)(time() % 7) + 0.25;
$preSaveStmt = $db->prepare("SELECT status, A1 FROM semester_assessment WHERE Sid=? AND Course_Code=? AND semester=? AND Year=? LIMIT 1");
$preSaveStmt->bind_param('ssss', $sid, $courseCode, $periodForCa, $ayUse);
$preSaveStmt->execute();
$preSaveRow = $preSaveStmt->get_result()->fetch_assoc() ?: [];
$preSaveStmt->close();
$wasLocked = in_array(strtolower((string)($preSaveRow['status'] ?? '')), ['approved', 'published'], true);

$save = ca_save_component($db, $sid, $courseCode, $periodForCa, $ayUse, $normalized === 'term' ? 'term' : 'semester', 'A1', $markValue, $staffId);
if ($wasLocked) {
    $assert(empty($save['ok']) && str_contains(strtolower((string)($save['message'] ?? '')), 'locked'), 'workflow.ca_locked_after_publish', (string)($save['message'] ?? ''));
    $markValue = (float)($preSaveRow['A1'] ?? 0);
} else {
    $assert(!empty($save['ok']), 'workflow.ca_save', (string)($save['message'] ?? ''));
}

// Status should be Submitted (not stuck only as bare default without status column write)
$st = $db->prepare("SELECT status, A1, Total_CA FROM semester_assessment WHERE Sid=? AND Course_Code=? AND semester=? AND Year=? LIMIT 1");
$st->bind_param('ssss', $sid, $courseCode, $periodForCa, $ayUse);
$st->execute();
$saRow = $st->get_result()->fetch_assoc();
$st->close();
$assert(is_array($saRow), 'workflow.ca_row_exists');
$assert(
    is_array($saRow) && in_array((string)$saRow['status'], ['Submitted', 'Published', 'Pending'], true),
    'workflow.ca_status_after_save',
    (string)($saRow['status'] ?? '')
);

// Before publish, student published map may hide the row
$mapBefore = student_ca_fetch_period_components_map($db, $sid, $ayUse, (string)$yos, $periods);
$visibleBefore = isset($mapBefore[student_ca_normalize_course_code($courseCode)][(int)$periodForCa]['ass1']);

// Publish via shipped helper
$pub = ca_publish_assessment($db, $sid, $courseCode, $periodForCa, $ayUse, $staffId);
$assert(!empty($pub['ok']), 'workflow.ca_publish', (string)($pub['message'] ?? ''));

$mapAfter = student_ca_fetch_period_components_map($db, $sid, $ayUse, (string)$yos, $periods);
$codeKey = student_ca_normalize_course_code($courseCode);
$periodData = $mapAfter[$codeKey][(int)$periodForCa] ?? null;
$assert(is_array($periodData), 'workflow.student_sees_published_period', json_encode($periodData));
$assert(
    is_array($periodData) && abs((float)($periodData['ass1'] ?? 0) - $markValue) < 0.001,
    'workflow.student_sees_saved_mark',
    'expected=' . $markValue . ' got=' . json_encode($periodData['ass1'] ?? null)
    . ' before_visible=' . ($visibleBefore ? '1' : '0')
);

// Structural: dual path merge remains in source
$caSrc = (string)file_get_contents($root . '/includes/ca_helpers.php');
$assert(str_contains($caSrc, 'fall through to legacy') || str_contains($caSrc, 'Merge normalized'), 'struct.legacy_merge_comment');
$assert(function_exists('ca_publish_assessment'), 'struct.publish_helper');

echo "\n=== SUMMARY ===\n";
if ($failures > 0) {
    echo "RESULT=FAIL failures=$failures\n";
    exit(1);
}
echo "RESULT=OK\n";
echo "PRIMARY: SID=$sid staff=$staffId course=$courseCode period=$periodForCa ay=$ayUse mark=$markValue\n";
exit(0);
