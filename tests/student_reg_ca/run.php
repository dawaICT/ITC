<?php
/**
 * Durable regression test: student course registration + CA results paths.
 *
 * Drives shipped services/helpers (RegistrationDataService, StudentAcademicWorkflowService,
 * continuous_assessment_helpers) against the live DB — not reimplemented oracles.
 *
 * Usage (project root):
 *   C:\xampp\php\php.exe tests/student_reg_ca/run.php [SID]
 */
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
chdir($projectRoot);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once $projectRoot . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "FAIL: no mysqli \$db\n");
    exit(2);
}

$failures = 0;
$assert = static function (bool $cond, string $label, string $detail = '') use (&$failures): void {
    if ($cond) {
        echo "PASS: $label" . ($detail !== '' ? " | $detail" : '') . "\n";
        return;
    }
    $failures++;
    echo "FAIL: $label" . ($detail !== '' ? " | $detail" : '') . "\n";
};

$sid = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($sid === '') {
    $prefer = $db->query(
        "SELECT s.SID FROM students s
         INNER JOIN student_login sl ON s.SID = sl.Sid
         INNER JOIN student_program sp ON s.SID = sp.Sid
         INNER JOIN semester_registration sr ON sr.student_id = s.SID
         LIMIT 1"
    );
    $row = $prefer ? $prefer->fetch_assoc() : null;
    $sid = $row['SID'] ?? '';
}
$assert($sid !== '', 'resolve_test_sid', $sid !== '' ? $sid : 'none');
if ($sid === '') {
    exit(1);
}

echo "SID=$sid\n";
echo "RUN_TS=" . date('c') . "\n";

require_once $projectRoot . '/students/includes/period_mode_helper.php';
require_once $projectRoot . '/students/includes/RegistrationDataService.php';
require_once $projectRoot . '/students/includes/AcademicSessionService.php';
require_once $projectRoot . '/students/includes/StudentAcademicWorkflowService.php';
require_once $projectRoot . '/students/includes/continuous_assessment_helpers.php';
require_once $projectRoot . '/includes/short_course_student.php';
require_once $projectRoot . '/includes/ca_helpers.php';

// ---------------------------------------------------------------------------
// Registration path (courseReg.php)
// ---------------------------------------------------------------------------
try {
    $regData = new RegistrationDataService($db);
    $sessionService = new AcademicSessionService($db);
    $periodMode = getStudentProgramPeriodMode($db, $sid);
    $normalized = normalizeProgramPeriodMode($periodMode !== '' ? $periodMode : 'semester');
    $session = $sessionService->getCurrentSession(
        in_array($normalized, ['term', 'semester'], true) ? $normalized : null
    );
    $assert(is_array($session), 'reg.session', 'ay=' . ($session['academic_year'] ?? '') . " mode=$normalized");

    $ay = trim((string)($session['academic_year'] ?? ''));
    $periodNum = (int)($session['period_number'] ?? $session['semester_term'] ?? $session['semester'] ?? 0);
    $termContext = $regData->resolveRegistrationTermContext(
        $sid,
        null,
        $ay !== '' ? $ay : null,
        $periodNum > 0 ? $periodNum : null,
        $periodMode !== '' ? $periodMode : null,
        true
    );
    $assert($termContext === null || is_array($termContext), 'reg.term_context', is_array($termContext)
        ? ('sem=' . ($termContext['semester'] ?? '') . ' yos=' . ($termContext['year_of_study'] ?? '')
            . ' prog=' . ($termContext['program_code'] ?? '') . ' ay=' . ($termContext['academic_year'] ?? ''))
        : 'null');

    $selectable = $regData->getSelectableCoursesForTerm($sid, $termContext);
    $assert(is_array($selectable) && isset($selectable['courses']), 'reg.selectable', 'count='
        . count($selectable['courses'] ?? []));

    if (is_array($termContext) && (int)($termContext['semester'] ?? 0) > 0) {
        $wf = new StudentAcademicWorkflowService($db);
        $inserted = $wf->ensureYearCoursesEnrolled($sid);
        $assert(is_int($inserted), 'reg.ensureYearCoursesEnrolled', "inserted=$inserted");

        $yos = (int)($termContext['year_of_study'] ?? 1);
        $registered = $regData->getRegisteredCourses(
            $sid,
            $yos,
            0,
            null,
            (string)($termContext['academic_year'] ?? $ay),
            'year'
        );
        $assert(is_array($registered), 'reg.registered_year_courses', 'count=' . count($registered));
    } else {
        $assert(true, 'reg.ensureYearCoursesEnrolled', 'skipped_no_period_reg');
        $assert(true, 'reg.registered_year_courses', 'skipped');
    }
} catch (Throwable $e) {
    $assert(false, 'reg.core', $e->getMessage());
}

// ---------------------------------------------------------------------------
// createRegistration dual student-id column (structural + live schema check)
// ---------------------------------------------------------------------------
try {
    $helpersSrc = (string)file_get_contents($projectRoot . '/students/includes/RegistrationDataService.php');
    $assert(
        str_contains($helpersSrc, "getExistingSemRegCols('student_id', 'Sid', 'SID', 'student')")
            || str_contains($helpersSrc, 'getExistingSemRegCols("student_id", "Sid", "SID", "student")'),
        'structural.createRegistration_dual_sid_write'
    );

    $srCols = [];
    if ($m = $db->query('SHOW COLUMNS FROM semester_registration')) {
        while ($row = $m->fetch_assoc()) {
            $srCols[strtolower((string)$row['Field'])] = (string)$row['Field'];
        }
        $m->free();
    }
    $hasBoth = isset($srCols['student_id']) && (isset($srCols['sid']) || isset($srCols['SID']));
    // case: columns map lowercases keys so SID -> sid
    $hasBoth = isset($srCols['student_id']) && isset($srCols['sid']);
    $assert($hasBoth, 'schema.semester_registration_dual_sid_cols', implode(',', array_keys($srCols)));
} catch (Throwable $e) {
    $assert(false, 'structural.reg_dual_sid', $e->getMessage());
}

// ---------------------------------------------------------------------------
// CA path (continuousAssessment.php)
// ---------------------------------------------------------------------------
try {
    $student = student_ca_fetch_student_profile($db, $sid);
    $assert(is_array($student) && trim((string)($student['Fname'] ?? '')) !== '', 'ca.profile',
        trim(($student['Fname'] ?? '') . ' ' . ($student['Lname'] ?? ''))
        . ' prog=' . ($student['program_code'] ?? ''));

    $isShort = isShortCourseStudent($db, $sid);
    $programStructure = getStudentProgramPeriodMode($db, $sid);
    $assert($programStructure !== '' || $isShort, 'ca.period_mode', "mode=$programStructure short=" . ($isShort ? '1' : '0'));

    $yearOptions = student_ca_academic_year_options($db, $sid, date('Y'));
    $assert(is_array($yearOptions) && $yearOptions !== [], 'ca.year_options', implode(',', $yearOptions));

    $selectedYear = $yearOptions[0];
    $sessionService = new AcademicSessionService($db);
    $currentSession = $sessionService->getCurrentSession($programStructure);
    $sessionAy = trim((string)($currentSession['academic_year'] ?? ''));
    if ($sessionAy !== '' && in_array($sessionAy, $yearOptions, true)) {
        $selectedYear = $sessionAy;
    }

    $yos = student_ca_resolve_year_of_study($db, $sid, $selectedYear);
    $assert($yos !== '', 'ca.year_of_study', $yos);

    $periodConfig = student_ca_period_columns($db, (string)($student['program_code'] ?? ''), $programStructure);
    $periods = $periodConfig['periods'] ?? [];
    $assert(is_array($periods) && $periods !== [], 'ca.period_config', 'periods=' . implode(',', $periods));

    $regData = new RegistrationDataService($db);
    $courses = [];
    $records = [];
    $componentsMap = [];
    if (!$isShort && trim((string)($student['program_code'] ?? '')) !== '') {
        $courses = $regData->getRegisteredCourses($sid, (int)$yos, 0, null, $selectedYear, 'year');
        $assert(is_array($courses), 'ca.registered_courses', 'count=' . count($courses));

        $componentsMap = student_ca_fetch_period_components_map($db, $sid, $selectedYear, $yos, $periods);
        $assert(is_array($componentsMap), 'ca.components_map', 'courses_with_marks=' . count($componentsMap));

        $records = student_ca_build_annual_records($courses, $componentsMap, $periods, $yos);
        $assert(is_array($records), 'ca.annual_records', 'rows=' . count($records));

        $summary = student_ca_compute_annual_summary($records, $periods);
        $assert(isset($summary['course_count']), 'ca.summary', json_encode($summary));

        // If published marks exist for registered courses, records must show them
        $pubStmt = $db->prepare("SELECT Course_Code, Total_CA, A1, A2, T1 FROM semester_assessment WHERE Sid = ? AND status = 'Published'");
        $pubStmt->bind_param('s', $sid);
        $pubStmt->execute();
        $pubRes = $pubStmt->get_result();
        $published = [];
        while ($row = $pubRes->fetch_assoc()) {
            $published[] = $row;
        }
        $pubStmt->close();

        $regCodes = [];
        foreach ($courses as $c) {
            $regCodes[student_ca_normalize_course_code((string)($c['course_code'] ?? ''))] = true;
        }

        $visibleMarks = 0;
        foreach ($records as $rec) {
            foreach ($periods as $pn) {
                if (student_ca_period_has_mark($rec->periods[$pn] ?? null)) {
                    $visibleMarks++;
                    break;
                }
            }
        }

        $pubOnReg = 0;
        foreach ($published as $prow) {
            $code = student_ca_normalize_course_code((string)$prow['Course_Code']);
            if (isset($regCodes[$code])) {
                $pubOnReg++;
            }
        }
        $assert(
            $pubOnReg === 0 || $visibleMarks > 0,
            'ca.published_marks_visible',
            "published_on_reg=$pubOnReg visible_courses=$visibleMarks"
        );
    } else {
        $assert(true, 'ca.registered_courses', $isShort ? 'short_course_gate' : 'no_program_gate');
        $assert(true, 'ca.components_map', 'skipped');
        $assert(true, 'ca.annual_records', 'skipped');
        $assert(true, 'ca.summary', 'skipped');
        $assert(true, 'ca.published_marks_visible', 'skipped');
    }

    $shortRows = student_ca_load_short_course_records($db, $sid);
    $assert(is_array($shortRows), 'ca.short_course_records', 'count=' . count($shortRows));
} catch (Throwable $e) {
    $assert(false, 'ca.core', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

// ---------------------------------------------------------------------------
// Unit: student_ca_derive_period_total (shipped helper)
// ---------------------------------------------------------------------------
try {
    $assert(
        ca_calculate_total_ca(['A1' => 10, 'A2' => 10, 'T1' => 20]) === 13.33,
        'unit.entry_total_is_average'
    );
    $assert(
        student_ca_compute_final_ca([120.0, 95.0]) === 100.0,
        'unit.annual_display_ceiling'
    );
    $weighted = ca_calculate_course_total($db, $sid, 'DCSE-101', ['A1' => 80, 'A2' => 70, 'T1' => 90]);
    $assert($weighted === 82.5, 'live.course_weighted_total', 'got=' . json_encode($weighted));
    $assert(function_exists('student_ca_derive_period_total'), 'unit.derive_exists');
    $assert(
        student_ca_derive_period_total(13.33, 12.0, 10.0, 18.0) === 13.33,
        'unit.derive_prefers_stored'
    );
    $derived = student_ca_derive_period_total(null, 12.0, 10.0, 18.0);
    $assert(
        $derived !== null && abs($derived - 13.33) < 0.001,
        'unit.derive_from_components',
        'got=' . json_encode($derived)
    );
    $assert(
        student_ca_derive_period_total(null, null, null, null) === null,
        'unit.derive_empty'
    );

    // Live path: components without Total_CA still produce final_ca via map build
    $tmpCode = 'ZZ-TEST-CA';
    $db->query("DELETE FROM semester_assessment WHERE Sid = '" . $db->real_escape_string($sid) . "' AND Course_Code = '{$tmpCode}'");
    $ins = $db->prepare(
        "INSERT INTO semester_assessment (Sid, Course_Code, A1, A2, T1, Total_CA, status, semester, Year)
         VALUES (?, ?, 10, 12, 16, NULL, 'Published', '2', '2026')"
    );
    $ins->bind_param('ss', $sid, $tmpCode);
    $ins->execute();
    $ins->close();

    // Temporarily include tmp course in map by calling fetch then build with synthetic course list
    $map = student_ca_fetch_period_components_map($db, $sid, '2026', '1', [1, 2, 3]);
    $assert(
        isset($map[student_ca_normalize_course_code($tmpCode)][2]['total'])
            && $map[student_ca_normalize_course_code($tmpCode)][2]['total'] !== null,
        'live.null_total_ca_derives',
        json_encode($map[student_ca_normalize_course_code($tmpCode)][2] ?? null)
    );
    $built = student_ca_build_annual_records(
        [['course_code' => $tmpCode, 'course_name' => 'Temp']],
        $map,
        [1, 2, 3],
        '1'
    );
    $assert(
        count($built) === 1 && $built[0]->final_ca === 13.0,
        'live.null_total_final_ca',
        'final=' . json_encode($built[0]->final_ca ?? null)
    );
    $db->query("DELETE FROM semester_assessment WHERE Sid = '" . $db->real_escape_string($sid) . "' AND Course_Code = '{$tmpCode}'");

    // Assessments fallback: hydrate ass1 then ass2 then test sequentially via the
    // shipped student_ca_fetch_assessments_fallback_map loop — period total must
    // recompute after each component (not freeze at the first mark).
    if (function_exists('student_ca_fetch_assessments_fallback_map')
        && student_ca_table_exists($db, 'assessments')
    ) {
        $fbCode = 'ZZ-FB-CA';
        $db->query(
            "DELETE FROM assessments WHERE (SID = '" . $db->real_escape_string($sid)
            . "' OR SID = '" . $db->real_escape_string($sid) . "') AND course_code = '{$fbCode}'"
        );
        $components = [
            ['assignment', '1', '10'],
            ['assignment', '2', '12'],
            ['test', '1', '16'],
        ];
        $insFb = $db->prepare(
            "INSERT INTO assessments (SID, course_code, semester, assess_type, assess_num, marks, year)
             VALUES (?, ?, '2', ?, ?, ?, '2026')"
        );
        foreach ($components as [$type, $num, $marks]) {
            $insFb->bind_param('sssss', $sid, $fbCode, $type, $num, $marks);
            $insFb->execute();
        }
        $insFb->close();

        $fbMap = student_ca_fetch_assessments_fallback_map($db, $sid, '2026', '1', [1, 2, 3]);
        $fbKey = student_ca_normalize_course_code($fbCode);
        $fbPeriod = $fbMap[$fbKey][2] ?? null;
        $assert(
            is_array($fbPeriod)
                && ($fbPeriod['ass1'] ?? null) == 10.0
                && ($fbPeriod['ass2'] ?? null) == 12.0
                && ($fbPeriod['test'] ?? null) == 16.0,
            'live.assessments_fallback_components',
            json_encode($fbPeriod)
        );
        $fbTotal = $fbPeriod['total'] ?? null;
        $expectedTotal = student_ca_derive_period_total(null, 10.0, 12.0, 16.0);
        $assert(
            $fbTotal !== null
                && $expectedTotal !== null
                && abs((float)$fbTotal - (float)$expectedTotal) < 0.001
                && abs((float)$fbTotal - 10.0) > 0.01,
            'live.assessments_fallback_total_not_frozen',
            'total=' . json_encode($fbTotal) . ' expected=' . json_encode($expectedTotal)
            . ' (must not freeze at first component=10)'
        );
        // Structural: fallback must pass null as stored total when recomputing
        $assert(
            (bool)preg_match(
                '/student_ca_derive_period_total\(\s*null\s*,/s',
                (string)file_get_contents($projectRoot . '/students/includes/continuous_assessment_helpers.php')
            ),
            'structural.fallback_recompute_null_stored'
        );

        $db->query(
            "DELETE FROM assessments WHERE course_code = '{$fbCode}' AND SID = '"
            . $db->real_escape_string($sid) . "'"
        );
    } else {
        $assert(true, 'live.assessments_fallback_components', 'assessments_table_or_fn_skipped');
        $assert(true, 'live.assessments_fallback_total_not_frozen', 'skipped');
        $assert(true, 'structural.fallback_recompute_null_stored', 'skipped');
    }
} catch (Throwable $e) {
    $assert(false, 'unit.derive', $e->getMessage());
    @$db->query("DELETE FROM semester_assessment WHERE Course_Code = 'ZZ-TEST-CA'");
    @$db->query("DELETE FROM assessments WHERE course_code = 'ZZ-FB-CA'");
}

// Annual cap is enforced per student/course/year across academic periods.
try {
    $capCourse = 'ZZ-CAP-CA';
    $db->begin_transaction();
    $insCap = $db->prepare(
        "INSERT INTO semester_assessment (Sid, Course_Code, A1, Total_CA, status, semester, Year, program_type)
         VALUES (?, ?, 100, 100, 'Pending', '1', '2099', 'term')"
    );
    $insCap->bind_param('ss', $sid, $capCourse);
    $insCap->execute();
    $insCap->close();
    $capCheck = ca_validate_annual_total($db, $sid, $capCourse, '2', '2099', 120.0);
    $assert(empty($capCheck['ok']) && ($capCheck['annual_total'] ?? null) === 110.0, 'live.annual_cap_rejects_110');
    $db->rollback();
} catch (Throwable $e) {
    try { $db->rollback(); } catch (Throwable $_) {}
    $assert(false, 'live.annual_cap_rejects_110', $e->getMessage());
}

// Structural: CA helpers still guard semester_assessment
$caSrc = (string)file_get_contents($projectRoot . '/students/includes/continuous_assessment_helpers.php');
$assert(
    str_contains($caSrc, "student_ca_table_exists(\$db, 'semester_assessment')")
        || str_contains($caSrc, 'student_ca_table_exists($db, "semester_assessment")'),
    'structural.ca_table_guard'
);
$assert(str_contains($caSrc, 'student_ca_derive_period_total'), 'structural.derive_helper_present');

echo "\n=== SUMMARY ===\n";
if ($failures > 0) {
    echo "RESULT=FAIL failures=$failures\n";
    exit(1);
}
echo "RESULT=OK\n";
echo "PRIMARY: SID=$sid\n";
exit(0);
