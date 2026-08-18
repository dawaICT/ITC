<?php
/**
 * Durable regression test for core student-module query/data paths.
 *
 * Exercises the same services/helpers the live pages use (not reimplemented
 * oracles). Exit 0 only when all assertions pass without mysqli SQL fatals.
 *
 * Usage (from project root):
 *   C:\xampp\php\php.exe tests/student_module/run_core.php [SID]
 */
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
chdir($projectRoot);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once $projectRoot . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "FAIL: no mysqli \$db from db/connect.php\n");
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

// Resolve a known good SID (prefer CLI arg, else active program student).
$sid = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($sid === '') {
    $prefer = $db->query(
        "SELECT s.SID FROM students s
         INNER JOIN student_login sl ON s.SID = sl.Sid
         INNER JOIN student_program sp ON s.SID = sp.Sid
         WHERE (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')
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

require_once $projectRoot . '/includes/elearning_access.php';
require_once $projectRoot . '/includes/short_course_student.php';
require_once $projectRoot . '/includes/fees_helpers.php';
require_once $projectRoot . '/students/includes/period_mode_helper.php';
require_once $projectRoot . '/students/includes/student_fee_records.php';
require_once $projectRoot . '/students/includes/StudentAcademicWorkflowService.php';
require_once $projectRoot . '/students/includes/RegistrationDataService.php';
require_once $projectRoot . '/students/includes/AcademicSessionService.php';

// ---------------------------------------------------------------------------
// 1) Dashboard identity query (students/index.php)
// ---------------------------------------------------------------------------
try {
    $query_1 = "SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.profile_image, sp.startYear, sp.endYear,
                       sp.program_code, p.program_name, p.program_duration, p.period_mode,
                       p.academic_structure, p.duration_value, p.duration_unit, p.uses_terms, p.uses_semesters,
                       p.is_short_course, p.is_transport_exception, p.examination_type
                FROM students s
                INNER JOIN student_program sp ON s.SID = sp.Sid
                INNER JOIN programs p ON sp.program_code = p.program_code
                LEFT JOIN program_courses pc ON pc.program_code = sp.program_code
                WHERE s.SID = ?
                  AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')
                  AND COALESCE(p.is_active, 1) = 1
                GROUP BY s.SID, s.Fname, s.Lname, s.email, s.mobile, s.profile_image, sp.id, sp.startYear, sp.endYear,
                         sp.program_code, p.program_name, p.program_duration, p.period_mode, p.study_mode,
                         p.academic_structure, p.duration_value, p.duration_unit, p.uses_terms, p.uses_semesters,
                         p.is_short_course, p.is_transport_exception, p.examination_type
                ORDER BY COUNT(pc.course_code) DESC, sp.id DESC
                LIMIT 1";
    $stmt = $db->prepare($query_1);
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $studentRec = $stmt->get_result()->fetch_object();
    $stmt->close();

    $isShort = false;
    if (!$studentRec) {
        $sc = sc_student_enrolments($db, $sid);
        if ($sc) {
            $stmt = $db->prepare('SELECT SID, Fname, Lname FROM students WHERE SID = ? LIMIT 1');
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $studentRec = $stmt->get_result()->fetch_object();
            $stmt->close();
            $isShort = (bool)$studentRec;
        }
    }
    $assert($studentRec !== null, 'index.student_identity', $studentRec
        ? trim(($studentRec->Fname ?? '') . ' ' . ($studentRec->Lname ?? '')) . ' prog=' . ($studentRec->program_code ?? ($isShort ? 'short' : ''))
        : 'missing');

    if ($studentRec && !$isShort) {
        $mode = getStudentProgramPeriodMode($db, $sid);
        $assert($mode !== '', 'index.period_mode', $mode);
    }

    $feeSummary = student_fee_current_program_summary($db, $sid);
    $assert(is_array($feeSummary), 'index.fee_summary', 'due=' . ($feeSummary['total_due'] ?? 0)
        . ' paid=' . ($feeSummary['total_paid'] ?? 0));

    // announcement is optional — must not fatal when missing
    $annRes = $db->query("SHOW TABLES LIKE 'announcement'");
    if ($annRes && $annRes->num_rows > 0) {
        $db->query('SELECT title, descript, created FROM announcement ORDER BY created DESC LIMIT 3');
    }
    if ($annRes) {
        $annRes->free();
    }
    $assert(true, 'index.announcement_guard');

    $courses = getStudentEnrolledCourses($db, $sid);
    $assert(is_array($courses), 'index.enrolled_courses', 'count=' . count($courses));
} catch (Throwable $e) {
    $assert(false, 'index.core', $e->getMessage());
}

// ---------------------------------------------------------------------------
// 2) Fees helpers + statement fetch (students/fees.php + accounts/fees_statement.php)
// ---------------------------------------------------------------------------
try {
    $wf = new StudentAcademicWorkflowService($db);
    $period = $wf->getActiveAcademicPeriod($sid);
    $assert(is_array($period), 'fees.active_period', json_encode([
        'ok' => $period['ok'] ?? false,
        'label' => $period['period_label'] ?? '',
    ]));

    $elig = !empty($period['ok']) ? $wf->checkFeeEligibility($sid, $period) : null;
    $assert($elig === null || is_array($elig), 'fees.eligibility', $elig
        ? ('paid=' . ($elig['amount_paid'] ?? 0) . ' bal=' . ($elig['balance'] ?? 0))
        : 'no_period');

    $paid = student_fee_completed_payments($db, $sid, null, 50);
    $assert(is_array($paid) && isset($paid['total_paid']), 'fees.completed_payments', 'total=' . ($paid['total_paid'] ?? 0)
        . ' rows=' . count($paid['records'] ?? []));

    // Fee line items with optional entity_type (mirrors fees.php after fix)
    $hasEntity = false;
    $er = $db->query("SHOW COLUMNS FROM fee_structure LIKE 'entity_type'");
    if ($er && $er->num_rows > 0) {
        $hasEntity = true;
    }
    if ($er) {
        $er->free();
    }
    $where = 'program_code = ?';
    if ($hasEntity) {
        $where .= " AND entity_type = 'program'";
    }
    $prog = (string)($feeSummary['program_code'] ?? '');
    if ($prog !== '') {
        $stmt = $db->prepare("SELECT COUNT(*) AS n FROM fee_structure WHERE $where");
        $stmt->bind_param('s', $prog);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        $assert(true, 'fees.fee_structure_guarded', 'entity_type_used=' . ($hasEntity ? '1' : '0') . " rows=$n");
    } else {
        $assert(true, 'fees.fee_structure_guarded', 'no_program_code');
    }

    // Shipped fees_statement_fetch_account from includes/fees_helpers.php
    $accStmt = $db->prepare("SELECT id, course_id FROM student_fee_accounts WHERE student_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $accStmt->bind_param('s', $sid);
    $accStmt->execute();
    $accRow = $accStmt->get_result()->fetch_assoc();
    $accStmt->close();
    if ($accRow) {
        $fetched = fees_statement_fetch_account($db, $sid);
        $assert($fetched !== null, 'fees.statement_fetch_active_account', 'account_id=' . $accRow['id']
            . ' course_id=' . $accRow['course_id']
            . ' course_code=' . ($fetched['course_code'] ?? ''));
    } else {
        $assert(true, 'fees.statement_fetch_active_account', 'no_active_account_skip');
    }
} catch (Throwable $e) {
    $assert(false, 'fees.core', $e->getMessage());
}

// Explicit orphaned-account regression: active fee account whose course_id
// does not exist in courses must still be fetchable via shipped LEFT JOIN.
try {
    $orphanSql = "SELECT sfa.student_id, sfa.id, sfa.course_id
                    FROM student_fee_accounts sfa
                    LEFT JOIN courses c ON c.id = sfa.course_id
                   WHERE sfa.status = 'active' AND c.id IS NULL
                   LIMIT 5";
    $orphans = [];
    if ($res = $db->query($orphanSql)) {
        while ($row = $res->fetch_assoc()) {
            $orphans[] = $row;
        }
        $res->free();
    }
    if ($orphans === []) {
        $assert(true, 'fees.orphaned_course_account', 'none_in_db');
    } else {
        foreach ($orphans as $orphan) {
            $fetched = fees_statement_fetch_account($db, (string)$orphan['student_id']);
            $assert(
                $fetched !== null && (int)($fetched['id'] ?? 0) > 0,
                'fees.orphaned_course_account',
                'sid=' . $orphan['student_id'] . ' course_id=' . $orphan['course_id']
                    . ' label=' . ($fetched['course_code'] ?? '')
            );
        }
    }
} catch (Throwable $e) {
    $assert(false, 'fees.orphaned_course_account', $e->getMessage());
}

// ---------------------------------------------------------------------------
// 3) myCourses paths
// ---------------------------------------------------------------------------
try {
    $srCols = [];
    if ($m = $db->query('SHOW COLUMNS FROM semester_registration')) {
        while ($row = $m->fetch_assoc()) {
            $srCols[strtolower($row['Field'])] = $row['Field'];
        }
        $m->free();
    }
    $srSid = $srCols['student_id'] ?? ($srCols['sid'] ?? 'student_id');
    $sql = "SELECT * FROM semester_registration WHERE `{$srSid}` = ? ORDER BY id DESC LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $semReg = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $assert(true, 'myCourses.semester_registration', $semReg ? ('id=' . ($semReg['id'] ?? '')) : 'none');

    $crCols = [];
    if ($m = $db->query('SHOW COLUMNS FROM course_registration')) {
        while ($row = $m->fetch_assoc()) {
            $crCols[strtolower($row['Field'])] = $row['Field'];
        }
        $m->free();
    }
    $crSid = $crCols['sid'] ?? ($crCols['student_id'] ?? 'Sid');
    $crCode = $crCols['course_code'] ?? 'course_code';
    $sql = "SELECT cr.`{$crCode}` AS course_code, c.course_name
            FROM course_registration cr
            LEFT JOIN courses c ON TRIM(UPPER(c.course_code)) = TRIM(UPPER(cr.`{$crCode}`))
            WHERE cr.`{$crSid}` = ?
            LIMIT 50";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $n = 0;
    $res = $stmt->get_result();
    while ($res->fetch_assoc()) {
        $n++;
    }
    $stmt->close();
    $assert(true, 'myCourses.course_registration', "rows=$n");
} catch (Throwable $e) {
    $assert(false, 'myCourses.core', $e->getMessage());
}

// ---------------------------------------------------------------------------
// 4) courseReg paths
// ---------------------------------------------------------------------------
try {
    $regData = new RegistrationDataService($db);
    $sessionService = new AcademicSessionService($db);
    $periodMode = getStudentProgramPeriodMode($db, $sid);
    $normalized = normalizeProgramPeriodMode($periodMode !== '' ? $periodMode : 'semester');
    $session = $sessionService->getCurrentSession(
        in_array($normalized, ['term', 'semester'], true) ? $normalized : null
    );
    $assert(is_array($session), 'courseReg.session', 'ay=' . ($session['academic_year'] ?? '')
        . ' mode=' . $normalized);

    $ay = trim((string)($session['academic_year'] ?? ''));
    $periodNum = (int)($session['period_number'] ?? $session['semester_term'] ?? $session['semester'] ?? 0);
    $ctx = $regData->resolveRegistrationTermContext(
        $sid,
        null,
        $ay !== '' ? $ay : null,
        $periodNum > 0 ? $periodNum : null,
        $periodMode !== '' ? $periodMode : null,
        true
    );
    $assert($ctx === null || is_array($ctx), 'courseReg.term_context', is_array($ctx)
        ? ('sem=' . ($ctx['semester'] ?? '') . ' prog=' . ($ctx['program_code'] ?? ''))
        : 'null');

    $selectable = $regData->getSelectableCoursesForTerm($sid, $ctx);
    $assert(is_array($selectable) && isset($selectable['courses']), 'courseReg.selectable', 'count='
        . count($selectable['courses'] ?? []));
} catch (Throwable $e) {
    $assert(false, 'courseReg.core', $e->getMessage());
}

// Structural checks on shipped sources
$feesSrc = (string)file_get_contents($projectRoot . '/students/fees.php');
$assert(
    str_contains($feesSrc, "feeCols['entity_type']")
        && str_contains($feesSrc, 'SHOW COLUMNS FROM `fee_structure`'),
    'structural.fees_entity_type_guarded'
);

$helpersSrc = (string)file_get_contents($projectRoot . '/includes/fees_helpers.php');
$assert(
    str_contains($helpersSrc, 'LEFT JOIN courses c ON sfa.course_id = c.id'),
    'structural.statement_left_join_courses'
);
$assert(
    !preg_match('/INNER JOIN courses c ON sfa\.course_id = c\.id/', $helpersSrc),
    'structural.statement_no_inner_join_courses'
);

echo "\n=== SUMMARY ===\n";
if ($failures > 0) {
    echo "RESULT=FAIL failures=$failures\n";
    exit(1);
}
echo "RESULT=OK\n";
echo "PRIMARY: SID=$sid\n";
exit(0);
