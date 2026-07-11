<?php
declare(strict_types=1);

$page_title = 'AI Reports';
require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/portal_access.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';
require_once dirname(__DIR__) . '/includes/student_progression_report.php';

wuc_require_portal_access($db, 'academic');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function admin_ai_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_ai_table_exists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    if ($res = @$db->query("SHOW TABLES LIKE '{$safe}'")) {
        $exists = $res->num_rows > 0;
        $res->free();
        return $exists;
    }
    return false;
}

function admin_ai_columns(mysqli $db, string $table): array
{
    $columns = [];
    if (admin_ai_table_exists($db, $table) && ($res = @$db->query("SHOW COLUMNS FROM `{$table}`"))) {
        while ($row = $res->fetch_assoc()) {
            $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
        }
        $res->free();
    }
    return $columns;
}

function admin_ai_contains(string $haystack, string $needle): bool
{
    return strpos($haystack, $needle) !== false;
}

function admin_ai_match_report(string $question): string
{
    $q = strtolower($question);
    if (admin_ai_contains($q, 'progress') || admin_ai_contains($q, 'risk') || admin_ai_contains($q, 'failed') || admin_ai_contains($q, 'inactive')) {
        return 'progression_alerts';
    }
    if (admin_ai_contains($q, 'finance') || admin_ai_contains($q, 'payment') || admin_ai_contains($q, 'paid') || admin_ai_contains($q, 'collection')) {
        return 'finance_collection';
    }
    if (admin_ai_contains($q, 'registered') && (admin_ai_contains($q, 'course') || admin_ai_contains($q, 'not registered'))) {
        return 'registration_without_courses';
    }
    if (admin_ai_contains($q, 'program') || admin_ai_contains($q, 'programme') || admin_ai_contains($q, 'student count')) {
        return 'students_by_program';
    }
    return 'students_by_program';
}

function admin_ai_report_students_by_program(mysqli $db): array
{
    $notes = [];

    // Build a unified enrolment source: a student counts toward a program if they
    // are recorded in the students master table OR the student_program assignment
    // table. UNION de-duplicates the same SID across both sources.
    $unionParts = [];
    $coll = ' COLLATE utf8mb4_general_ci';

    $st = admin_ai_columns($db, 'students');
    $stSid = $st['sid'] ?? null;
    $stProg = $st['program'] ?? ($st['program_code'] ?? null);
    if ($stSid && $stProg) {
        $unionParts[] = "SELECT `{$stSid}`{$coll} AS sid, `{$stProg}`{$coll} AS program_code
                         FROM students WHERE `{$stProg}` IS NOT NULL AND `{$stProg}` <> ''";
    }

    if (admin_ai_table_exists($db, 'student_program')) {
        $sp = admin_ai_columns($db, 'student_program');
        $spSid = $sp['sid'] ?? ($sp['student_id'] ?? null);
        $spProg = $sp['program_code'] ?? null;
        if ($spSid && $spProg) {
            $unionParts[] = "SELECT `{$spSid}`{$coll} AS sid, `{$spProg}`{$coll} AS program_code
                             FROM student_program WHERE `{$spProg}` IS NOT NULL AND `{$spProg}` <> ''";
        }
    }

    if ($unionParts === []) {
        return ['title' => 'Students by Program', 'rows' => [], 'notes' => ['no enrolment source tables/columns found']];
    }

    $enrolment = '(' . implode(' UNION ', $unionParts) . ')';
    $rows = [];

    // Primary: list ALL active programs (so zero-enrolment programs are visible
    // instead of silently dropping out of the report).
    if (admin_ai_table_exists($db, 'programs')) {
        $p = admin_ai_columns($db, 'programs');
        $pCode = $p['program_code'] ?? null;
        $pName = $p['program_name'] ?? null;
        $activeCol = $p['is_active'] ?? null;
        if ($pCode && $pName) {
            $activeWhere = $activeCol ? "WHERE (p.`{$activeCol}` = 1 OR p.`{$activeCol}` IS NULL)" : '';
            $sql = "SELECT p.`{$pCode}` AS program_code,
                           p.`{$pName}` AS program_name,
                           COUNT(DISTINCT e.sid) AS students
                    FROM programs p
                    LEFT JOIN {$enrolment} e
                           ON e.program_code = p.`{$pCode}`{$coll}
                    {$activeWhere}
                    GROUP BY p.`{$pCode}`, p.`{$pName}`
                    ORDER BY students DESC, program_code
                    LIMIT 60";
            if ($res = @$db->query($sql)) {
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $res->free();
            } else {
                $notes[] = 'programs-based query failed: ' . $db->error;
            }
        }
    }

    // Fallback: group purely by the enrolment source (e.g. programs table absent).
    if ($rows === []) {
        $sql = "SELECT e.program_code AS program_code,
                       e.program_code AS program_name,
                       COUNT(DISTINCT e.sid) AS students
                FROM {$enrolment} e
                GROUP BY e.program_code
                ORDER BY students DESC, program_code
                LIMIT 60";
        if ($res = @$db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
        }
    }

    // Context notes so the AI summary reflects reality (and isn't alarmist about
    // seed-only data).
    $totalStudents = 0;
    $withStudents = 0;
    foreach ($rows as $r) {
        $c = (int)$r['students'];
        $totalStudents += $c;
        if ($c > 0) {
            $withStudents++;
        }
    }
    if ($totalStudents === 0) {
        $notes[] = 'No students are currently enrolled in any program.';
    } elseif ($totalStudents <= 2) {
        $notes[] = "Only {$totalStudents} student record(s) exist in total — these figures reflect seed/test data, not live enrolment.";
    }
    $notes[] = "{$withStudents} of " . count($rows) . " active programs have at least one enrolled student.";

    return ['title' => 'Students by Program', 'rows' => $rows, 'notes' => $notes];
}

function admin_ai_report_finance_collection(mysqli $db): array
{
    if (!admin_ai_table_exists($db, 'payments')) {
        return ['title' => 'Finance Collection Summary', 'rows' => [], 'notes' => ['payments table missing']];
    }
    $cols = admin_ai_columns($db, 'payments');
    $amountCol = $cols['amount'] ?? null;
    if ($amountCol === null) {
        return ['title' => 'Finance Collection Summary', 'rows' => [], 'notes' => ['payments.amount column missing']];
    }
    $statusCol = $cols['status'] ?? null;
    $currencyCol = $cols['currency'] ?? null;
    $statusExpr = $statusCol ? "COALESCE(`{$statusCol}`, 'unknown')" : "'all'";
    $currencyExpr = $currencyCol ? "COALESCE(`{$currencyCol}`, 'default')" : "'default'";
    $rows = [];
    $sql = "SELECT {$statusExpr} AS status,
                   {$currencyExpr} AS currency,
                   COUNT(*) AS payments,
                   COALESCE(SUM(`{$amountCol}`), 0) AS amount
            FROM payments
            GROUP BY status, currency
            ORDER BY amount DESC
            LIMIT 30";
    if ($res = @$db->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return ['title' => 'Finance Collection Summary', 'rows' => $rows, 'notes' => []];
}

function admin_ai_report_registration_without_courses(mysqli $db): array
{
    if (!admin_ai_table_exists($db, 'semester_registration') || !admin_ai_table_exists($db, 'course_registration')) {
        return ['title' => 'Semester Registrations Without Course Registrations', 'rows' => [], 'notes' => ['registration tables missing']];
    }

    $sr = admin_ai_columns($db, 'semester_registration');
    $cr = admin_ai_columns($db, 'course_registration');
    $srSid = $sr['student_id'] ?? ($sr['sid'] ?? null);
    $srSem = $sr['semester'] ?? ($sr['semester_term'] ?? null);
    $srYear = $sr['year_of_study'] ?? ($sr['year'] ?? null);
    $crSid = $cr['sid'] ?? ($cr['student_id'] ?? null);
    $crSem = $cr['semester'] ?? ($cr['semester_term'] ?? null);
    $crYear = $cr['year'] ?? ($cr['year_of_study'] ?? null);
    $crCourse = $cr['course_code'] ?? ($cr['course'] ?? null);
    if (!$srSid || !$srSem || !$srYear || !$crSid || !$crSem || !$crYear || !$crCourse) {
        return ['title' => 'Semester Registrations Without Course Registrations', 'rows' => [], 'notes' => ['required registration columns missing']];
    }

    $programSelect = isset($sr['program_code']) ? "sr.`{$sr['program_code']}` AS program_code" : "'' AS program_code";
    $academicYearSelect = isset($sr['academic_year']) ? "sr.`{$sr['academic_year']}` AS academic_year" : "'' AS academic_year";
    $rows = [];
    $sql = "SELECT sr.`{$srSid}` AS student_id,
                   {$programSelect},
                   {$academicYearSelect},
                   sr.`{$srYear}` AS year_of_study,
                   sr.`{$srSem}` AS semester
            FROM semester_registration sr
            LEFT JOIN course_registration cr
              ON cr.`{$crSid}` = sr.`{$srSid}`
             AND cr.`{$crSem}` = sr.`{$srSem}`
             AND cr.`{$crYear}` = sr.`{$srYear}`
            WHERE cr.`{$crCourse}` IS NULL
            ORDER BY academic_year DESC, year_of_study DESC, semester DESC
            LIMIT 50";
    if ($res = @$db->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return ['title' => 'Semester Registrations Without Course Registrations', 'rows' => $rows, 'notes' => []];
}

function admin_ai_report_progression_alerts(mysqli $db): array
{
    $report = student_progression_report($db, [], 'admin');
    return [
        'title' => 'Progression Alerts',
        'rows' => array_slice($report['rows'], 0, 50),
        'stats' => $report['stats'],
        'notes' => [],
    ];
}

function admin_ai_run_report(mysqli $db, string $intent): array
{
    return match ($intent) {
        'progression_alerts' => admin_ai_report_progression_alerts($db),
        'finance_collection' => admin_ai_report_finance_collection($db),
        'registration_without_courses' => admin_ai_report_registration_without_courses($db),
        default => admin_ai_report_students_by_program($db),
    };
}

function admin_ai_report_fallback(array $report, string $question): string
{
    $lines = [];
    $lines[] = "Approved report fallback summary";
    $lines[] = "Question: " . $question;
    $lines[] = "Report: " . (string)($report['title'] ?? 'Report');
    $lines[] = "Rows returned: " . count($report['rows'] ?? []);
    if (!empty($report['stats']) && is_array($report['stats'])) {
        $lines[] = "Stats: " . json_encode($report['stats'], JSON_UNESCAPED_SLASHES);
    }
    if (!empty($report['notes'])) {
        $lines[] = "Notes: " . implode('; ', $report['notes']);
    }
    $lines[] = "";
    foreach (array_slice($report['rows'] ?? [], 0, 8) as $index => $row) {
        $lines[] = ($index + 1) . ". " . json_encode($row, JSON_UNESCAPED_SLASHES);
    }
    return implode("\n", $lines);
}

$question = trim((string)($_POST['question'] ?? ''));
$intent = '';
$report = null;
$summary = null;
$errors = [];
$aiStatus = wuc_ai_local_status();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $errors[] = 'Your request could not be verified. Refresh and try again.';
    }
    $question = wuc_ai_truncate($question, 500);
    if ($question === '') {
        $errors[] = 'Enter a report question.';
    }
    if (!$errors) {
        // Keep a short burst guard without making normal iterative reporting
        // unusable for most of an hour. Invalid submissions do not consume it.
        $rate = wuc_ai_rate_limit('admin_ai_reports', 10, 600);
        if (!$rate['ok']) {
            $errors[] = 'Too many AI report requests. Please try again in about ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.';
        }
    }

    if (!$errors) {
        $intent = admin_ai_match_report($question);
        $report = admin_ai_run_report($db, $intent);
        $context = [
            'question' => $question,
            'matched_report' => $intent,
            'report' => $report,
            'rules' => [
                'approved_reports_only' => true,
                'no_raw_sql_generation' => true,
                'summarize_only' => true,
            ],
        ];
        $contextJson = wuc_ai_context_json($context, 18000);
        $summary = wuc_ai_generate($db, [
            'feature' => 'admin_ai_reports',
            'user_role' => 'admin',
            'user_id' => (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'admin'),
            'input_summary' => $question . ' | ' . $intent,
            'context_hash' => hash('sha256', $contextJson),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are the ITC Portal admin reporting assistant. You may only summarize supplied approved report data. Never produce raw SQL, never ask for unrestricted database access, and clearly mention when a result is limited by missing schema or row limits.',
                ],
                [
                    'role' => 'user',
                    'content' => "Approved report context JSON:\n{$contextJson}\n\nSummarize the result, highlight risks, and list practical next actions.",
                ],
            ],
            'fallback' => static function () use ($report, $question): string {
                return admin_ai_report_fallback($report ?? [], $question);
            },
        ]);
    }
}

require __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard admin-ai-reports-page">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title">AI Reports</h1>
                <p class="text-muted mb-0">Ask natural-language questions mapped to approved portal reports.</p>
            </div>
            <div class="col-auto">
                <span class="badge <?php echo $aiStatus['model_ready'] ? 'bg-success' : 'bg-secondary'; ?>">
                    <?php echo $aiStatus['model_ready'] ? 'AI ready' : 'Fallback mode'; ?>
                </span>
            </div>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo admin_ai_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-4">
            <section class="data-table-card h-100">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-comments me-2"></i>Report question</h5></div>
                <div class="card-body">
                    <form method="post" action="ai_reports.php">
                        <input type="hidden" name="csrf_token" value="<?php echo admin_ai_h($_SESSION['csrf_token'] ?? ''); ?>">
                        <label class="form-label" for="question">Ask a report question</label>
                        <textarea class="form-control" id="question" name="question" rows="6" maxlength="500" placeholder="Example: Show students registered but not course registered"><?php echo admin_ai_h($question); ?></textarea>
                        <div class="form-text">Supported topics: students by program, finance collection, progression alerts, and semester registrations without course registrations.</div>
                        <button class="btn btn-primary mt-3" type="submit">
                            <i class="fas fa-wand-magic-sparkles me-2"></i>Run approved report
                        </button>
                    </form>
                </div>
            </section>
        </div>

        <div class="col-xl-8">
            <section class="data-table-card h-100">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Report summary</h5></div>
                <div class="card-body">
                    <?php if ($summary === null || $report === null): ?>
                        <div class="text-muted py-4">Run an approved report to see a summary and row preview.</div>
                    <?php else: ?>
                        <div class="alert <?php echo $summary['used_ai'] ? 'alert-info' : 'alert-warning'; ?> small">
                            Matched report: <?php echo admin_ai_h((string)$report['title']); ?>.
                            <?php echo $summary['used_ai'] ? 'Generated by ' . admin_ai_h((string)($summary['provider'] ?? 'AI')) . ' model ' . admin_ai_h($summary['model']) . '.' : admin_ai_h(wuc_ai_fallback_notice($summary)); ?>
                        </div>
                        <div class="bg-light border rounded p-3 mb-4"><?php echo wuc_ai_output_block($summary['text']); ?></div>

                        <h6 class="fw-semibold">Row preview</h6>
                        <?php if (empty($report['rows'])): ?>
                            <div class="alert alert-secondary mb-0">No rows returned.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <?php foreach (array_keys((array)$report['rows'][0]) as $column): ?>
                                                <th><?php echo admin_ai_h($column); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($report['rows'], 0, 12) as $row): ?>
                                            <tr>
                                                <?php foreach ((array)$row as $value): ?>
                                                    <td><?php echo admin_ai_h(is_scalar($value) ? (string)$value : json_encode($value)); ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
