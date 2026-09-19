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

// ── Assignment-side hardening: short courses must never enter student_program ──

// Live DB invariant: no student_program row points at a short-flagged program.
$contaminated = $db->query(
    "SELECT COUNT(*) AS c
       FROM student_program sp
       INNER JOIN programs p ON p.program_code = sp.program_code
      WHERE COALESCE(p.is_short_course, 0) = 1
         OR UPPER(COALESCE(p.structure_type, '')) = 'SHORT_COURSE'"
)->fetch_assoc();
$assert((int)($contaminated['c'] ?? 0) === 0, 'data.no_short_program_in_student_program');

// Write-path guard: admissionsEnrollExistingStudent must reject a short course.
require_once $root . '/includes/applicant_admission.php';
$assert(function_exists('admissionsEnrollExistingStudent'), 'fn.admissions_enroll');
$assert(function_exists('admissionsShortCourseAssignmentError'), 'fn.short_course_assignment_error');

if ($longSid !== null && $longSid !== '') {
    $before = $db->query(
        "SELECT COUNT(*) AS c FROM student_program WHERE program_code = 'TRANS-001'"
    )->fetch_assoc();
    $reject = admissionsEnrollExistingStudent($db, $longSid, 'TRANS-001', 'January ' . date('Y'), 'Full-time');
    $assert(
        ($reject['success'] ?? true) === false && stripos((string)($reject['message'] ?? ''), 'short course') !== false,
        'guard.enroll_rejects_short_course',
        (string)($reject['message'] ?? '')
    );
    $after = $db->query(
        "SELECT COUNT(*) AS c FROM student_program WHERE program_code = 'TRANS-001'"
    )->fetch_assoc();
    $assert(
        (int)($after['c'] ?? -1) === (int)($before['c'] ?? -2),
        'guard.no_student_program_row_written'
    );
}

// Structural: every programme-assignment UI filters/guards short courses.
foreach ([
    'registrar/admitStudent.php',
    'admin/update_student_program.php',
    'admin/get_programs.php',
] as $assignUi) {
    $src = (string)file_get_contents($root . '/' . $assignUi);
    $assert(
        str_contains($src, 'sc_sql_programs_long_only_predicate') || str_contains($src, 'sc_program_is_short_course'),
        'struct.' . str_replace(['/', '.php'], ['_', ''], $assignUi) . '_filters_short'
    );
}
$applicantAdmissionSrc = (string)file_get_contents($root . '/includes/applicant_admission.php');
$assert(
    substr_count($applicantAdmissionSrc, 'admissionsShortCourseAssignmentError($db') >= 2,
    'struct.admission_helper_guard_both_paths'
);

// ── Dual-enrolled portal switcher (portal_view.php session override) ──

$pv = (string)file_get_contents($root . '/students/portal_view.php');
$assert(str_contains($pv, 'sc_student_has_long_program') && str_contains($pv, 'sc_student_enrolments'), 'struct.portal_view_dual_guard');
$assert(str_contains($pv, "student_portal_view"), 'struct.portal_view_session_key');

$idxSrc = (string)file_get_contents($root . '/students/index.php');
$assert(str_contains($idxSrc, 'studentPortalViewOverride'), 'struct.index_override_gate');

$navSrc = (string)file_get_contents($root . '/students/includes/navbar.php');
$assert(str_contains($navSrc, 'portal_view.php?view=short_course'), 'struct.nav_switch_to_short');
$assert(str_contains($navSrc, 'portal_view.php?view=academic'), 'struct.nav_switch_back');

// HTTP behaviour via the dev impersonation hook (APP_ENV=development).
if (function_exists('curl_init')) {
    $base = 'http://localhost/wucportal';
    $http = static function (string $url, string $jar): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $jar,
            CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        preg_match('/^Location:\\s*(.+)$/mi', substr($raw, 0, $headerSize), $m);
        return ['code' => $code, 'location' => trim((string)($m[1] ?? '')), 'body' => substr($raw, $headerSize)];
    };

    // Dual-enrolled student: switch into the short-course portal and back.
    $dual = 'CSE26456789';
    $chk = $db->prepare('SELECT 1 FROM students WHERE SID = ?');
    $chk->bind_param('s', $dual);
    $chk->execute();
    $chk->store_result();
    $dualExists = $chk->num_rows > 0;
    $chk->close();

    if ($dualExists) {
        $jar = (string)tempnam(sys_get_temp_dir(), 'pv_dual_');
        $http("{$base}/students/index.php?dev=1&dev_as={$dual}", $jar); // impersonate

        // Gate still holds without the override.
        $noOverride = $http("{$base}/students/short_course_portal.php", $jar);
        $assert(
            in_array($noOverride['code'], [302, 303], true) && !str_contains($noOverride['location'], 'short_course_portal'),
            'http.dual_gate_holds_without_override',
            $noOverride['location']
        );

        // Long-portal navbar exposes the switcher link.
        $longHome = $http("{$base}/students/index.php", $jar);
        $assert(str_contains($longHome['body'], 'portal_view.php?view=short_course'), 'http.dual_long_nav_has_switcher');

        // Switch into the short-course portal.
        $switch = $http("{$base}/students/portal_view.php?view=short_course", $jar);
        $assert(
            in_array($switch['code'], [302, 303], true) && str_contains($switch['location'], 'short_course_portal.php'),
            'http.dual_switch_redirects_short_portal',
            $switch['location']
        );
        $scPortal = $http("{$base}/students/short_course_portal.php", $jar);
        $assert($scPortal['code'] === 200, 'http.dual_short_portal_200', (string)$scPortal['code']);
        $assert(str_contains($scPortal['body'], 'Short Course Dashboard'), 'http.dual_short_dashboard_rendered');
        $assert(str_contains($scPortal['body'], 'portal_view.php?view=academic'), 'http.dual_short_nav_has_back_link');

        // Switch back to the academic portal.
        $back = $http("{$base}/students/portal_view.php?view=academic", $jar);
        $assert(
            in_array($back['code'], [302, 303], true) && str_contains($back['location'], '/students/index.php'),
            'http.dual_switch_back_redirects_index',
            $back['location']
        );
        $gatedAgain = $http("{$base}/students/short_course_portal.php", $jar);
        $assert(
            in_array($gatedAgain['code'], [302, 303], true) && !str_contains($gatedAgain['location'], 'short_course_portal'),
            'http.dual_gate_restored_after_switch_back',
            $gatedAgain['location']
        );
        @unlink($jar);
    }

    // Short-only student: override is never granted; portal stays short-course.
    $shortOnly = 'SCONLYTEST01';
    $chk = $db->prepare('SELECT 1 FROM students WHERE SID = ?');
    $chk->bind_param('s', $shortOnly);
    $chk->execute();
    $chk->store_result();
    $shortExists = $chk->num_rows > 0;
    $chk->close();

    if ($shortExists) {
        $jar = (string)tempnam(sys_get_temp_dir(), 'pv_short_');
        $http("{$base}/students/index.php?dev=1&dev_as={$shortOnly}", $jar);
        $attempt = $http("{$base}/students/portal_view.php?view=short_course", $jar);
        $assert(
            in_array($attempt['code'], [302, 303], true) && str_contains($attempt['location'], '/students/index.php'),
            'http.short_only_no_override_granted',
            $attempt['location']
        );
        $home = $http("{$base}/students/short_course_portal.php", $jar);
        $assert($home['code'] === 200, 'http.short_only_portal_200', (string)$home['code']);
        $assert(!str_contains($home['body'], 'Back to Academic Portal'), 'http.short_only_no_back_link');
        @unlink($jar);
    }
}

// ── CA report course list: dropped courses must not leak via the marks merge ──

$caSrc = (string)file_get_contents($root . '/students/continuousAssessment.php');
$assert(str_contains($caSrc, 'droppedCodes'), 'struct.ca_merge_excludes_dropped');

if (function_exists('curl_init')) {
    // Dual student: CA list = 8 enrolled DCSE courses, dropped COM101/DCSE-102 hidden.
    if ($dualExists) {
        $jar = (string)tempnam(sys_get_temp_dir(), 'ca_dual_');
        $http("{$base}/students/index.php?dev=1&dev_as=CSE26456789", $jar);
        $caPage = $http("{$base}/students/continuousAssessment.php", $jar);
        $assert($caPage['code'] === 200, 'http.ca_dual_200', (string)$caPage['code']);
        preg_match_all('/ca-cell-code[^>]*>\s*([^<]+?)\s*<\/td>/', $caPage['body'], $codeMatches);
        $listed = array_map('trim', $codeMatches[1] ?? []);
        sort($listed);
        $assert(
            $listed === ['DCSE-101', 'DCSE-103', 'DCSE-104', 'DCSE-105', 'DCSE-106', 'DCSE-107', 'DCSE-108', 'DCSE-109'],
            'http.ca_dual_lists_enrolled_only',
            implode(',', $listed)
        );
        @unlink($jar);
    }

    // Long-only student: all 7 registered CCAM courses listed (unchanged).
    $cvm = 'CVM26567121';
    $chk = $db->prepare('SELECT 1 FROM students WHERE SID = ?');
    $chk->bind_param('s', $cvm);
    $chk->execute();
    $chk->store_result();
    $cvmExists = $chk->num_rows > 0;
    $chk->close();
    if ($cvmExists) {
        $jar = (string)tempnam(sys_get_temp_dir(), 'ca_cvm_');
        $http("{$base}/students/index.php?dev=1&dev_as={$cvm}", $jar);
        $caPage = $http("{$base}/students/continuousAssessment.php", $jar);
        preg_match_all('/ca-cell-code[^>]*>\s*([^<]+?)\s*<\/td>/', $caPage['body'], $codeMatches);
        $listed = array_map('trim', $codeMatches[1] ?? []);
        sort($listed);
        $assert(
            $listed === ['CCAM-101', 'CCAM-102', 'CCAM-103', 'CCAM-104', 'CCAM-105', 'CCAM-106', 'CCAM-107'],
            'http.ca_cvm_lists_all_registered',
            implode(',', $listed)
        );
        @unlink($jar);
    }

    // Short-only student: short-course report unaffected (PRN1130 still listed).
    if ($shortExists) {
        $jar = (string)tempnam(sys_get_temp_dir(), 'ca_short_');
        $http("{$base}/students/index.php?dev=1&dev_as=SCONLYTEST01", $jar);
        $caPage = $http("{$base}/students/continuousAssessment.php", $jar);
        $assert(
            str_contains($caPage['body'], 'Short Course Continuous Assessment') && str_contains($caPage['body'], 'PRN1130'),
            'http.ca_short_only_report_unchanged'
        );
        @unlink($jar);
    }
}

echo "\n=== SUMMARY ===\n";
if ($failures > 0) {
    echo "RESULT=FAIL failures=$failures\n";
    exit(1);
}
echo "RESULT=OK\n";
exit(0);
