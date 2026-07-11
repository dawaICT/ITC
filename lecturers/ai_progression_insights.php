<?php
declare(strict_types=1);

$page_title = 'AI Progression Insights';
require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/student_progression_report.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';
require_once dirname(__DIR__) . '/includes/elearning_access.php';

function lecturer_insights_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function lecturer_insights_format_period(string $val, array $labels): string
{
    if (empty($labels)) {
        return "Semester " . $val;
    }
    $parts = [];
    foreach ($labels as $label) {
        $parts[] = $label . " " . $val;
    }
    return implode(' / ', array_unique($parts));
}

function lecturer_insights_fallback(array $report): string
{
    $stats = $report['stats'] ?? [];
    $rows = $report['rows'] ?? [];
    $lines = [];
    $lines[] = "Progression insights fallback";
    $lines[] = "";
    $lines[] = "Summary:";
    $lines[] = "- Flagged students/course rows: " . (int)($stats['flagged'] ?? 0);
    $lines[] = "- Failed rows: " . (int)($stats['failed'] ?? 0);
    $lines[] = "- Inactive rows: " . (int)($stats['inactive'] ?? 0);
    $lines[] = "- Critical rows: " . (int)($stats['both'] ?? 0);
    $lines[] = "";

    if (!$rows) {
        $lines[] = "No failed or inactive progression rows were found for your assigned courses.";
    } else {
        $lines[] = "Priority follow-up list:";
        foreach (array_slice($rows, 0, 10) as $index => $row) {
            $student = trim((string)($row['student_id'] ?? '') . ' ' . (string)($row['student_name'] ?? ''));
            $course = trim((string)($row['course_code'] ?? '') . ' ' . (string)($row['course_name'] ?? ''));
            $risk = (string)($row['risk_level'] ?? 'Review');
            $flags = implode(', ', $row['flags'] ?? []);
            $lines[] = ($index + 1) . ". {$student} - {$course} - {$risk}" . ($flags !== '' ? " ({$flags})" : '');
        }
        $lines[] = "";
        $lines[] = "Suggested actions: verify records, contact students through official channels, review CA/exam evidence, and escalate persistent finance/registration issues to the relevant office.";
    }

    return implode("\n", $lines);
}

$staffId = (string)($_SESSION['staff_id'] ?? '');
$assignedCourses = getLecturerCourseDetails($db, $staffId);

$assignedPeriodLabels = [];
$resTypes = $db->prepare("
    SELECT DISTINCT p.period_mode 
    FROM course_lecturer cl
    JOIN program_courses pc ON TRIM(cl.course_code) = TRIM(pc.course_code)
    JOIN programs p ON pc.program_code = p.program_code
    WHERE cl.staff_id = ? AND cl.status = 'active'
");
if ($resTypes) {
    $resTypes->bind_param('s', $staffId);
    $resTypes->execute();
    $res = $resTypes->get_result();
    while ($row = $res->fetch_assoc()) {
        $assignedPeriodLabels[] = ($row['period_mode'] === 'term') ? 'Term' : 'Semester';
    }
    $resTypes->close();
}

if (!empty($assignedCourses)) {
    $codes = array_map(fn($c) => $c['course_code'], $assignedCourses);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    
    $sqlShort = "SELECT COUNT(*) FROM short_courses WHERE course_code IN ($placeholders) AND status = 'active'";
    if ($stmtShort = $db->prepare($sqlShort)) {
        $stmtShort->bind_param($types, ...$codes);
        $stmtShort->execute();
        $resShort = $stmtShort->get_result();
        if ($resShort->fetch_row()[0] > 0) {
            $assignedPeriodLabels[] = 'Intake';
        }
        $stmtShort->close();
    }
}
$assignedPeriodLabels = array_unique(array_filter($assignedPeriodLabels));

$periodLabel = 'Semester';
if (count($assignedPeriodLabels) === 1) {
    $periodLabel = reset($assignedPeriodLabels);
} elseif (count($assignedPeriodLabels) > 1) {
    $periodLabel = implode(' / ', $assignedPeriodLabels);
}

$academicYears = [];
$resYears = $db->query("SELECT DISTINCT academic_year FROM student_program WHERE academic_year IS NOT NULL AND academic_year != '' UNION SELECT DISTINCT academic_year FROM semester_registration WHERE academic_year IS NOT NULL AND academic_year != '' ORDER BY academic_year DESC");
if ($resYears) {
    while ($row = $resYears->fetch_row()) {
        $academicYears[] = (string)$row[0];
    }
    $resYears->free();
}
if (empty($academicYears)) {
    $currentYear = (int)date('Y');
    for ($y = $currentYear - 2; $y <= $currentYear + 2; $y++) {
        $academicYears[] = (string)$y;
    }
} else {
    $currentYearStr = (string)date('Y');
    if (!in_array($currentYearStr, $academicYears, true)) {
        $academicYears[] = $currentYearStr;
        rsort($academicYears);
    }
}

$semesters = [];
if (!empty($assignedCourses)) {
    $codes = array_map(fn($c) => $c['course_code'], $assignedCourses);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    
    $sql3 = "SELECT DISTINCT semester FROM course_registration WHERE course_code IN ($placeholders) AND semester IS NOT NULL AND semester != ''";
    if ($stmt3 = $db->prepare($sql3)) {
        $stmt3->bind_param($types, ...$codes);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        while ($row = $res3->fetch_row()) {
            $sem = trim((string)$row[0]);
            if ($sem !== '') {
                $semesters[] = $sem;
            }
        }
        $stmt3->close();
    }
    
    $sql4 = "SELECT DISTINCT semester FROM exams WHERE course_code IN ($placeholders) AND semester IS NOT NULL AND semester != ''";
    if ($stmt4 = $db->prepare($sql4)) {
        $stmt4->bind_param($types, ...$codes);
        $stmt4->execute();
        $res4 = $stmt4->get_result();
        while ($row = $res4->fetch_row()) {
            $sem = trim((string)$row[0]);
            if ($sem !== '') {
                $semesters[] = $sem;
            }
        }
        $stmt4->close();
    }
    
    $semesters = array_unique($semesters);
    sort($semesters);
}
if (empty($semesters)) {
    $semesters = ['1', '2'];
}

$students = [];
if (!empty($assignedCourses)) {
    $codes = array_map(fn($c) => $c['course_code'], $assignedCourses);
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    
    $sqlStud = "
        SELECT DISTINCT student_id, first_name, last_name
        FROM (
            SELECT DISTINCT st.SID AS student_id, st.Fname AS first_name, st.Lname AS last_name
            FROM course_registration cr
            INNER JOIN students st ON cr.Sid = st.SID
            INNER JOIN course_lecturer lec ON cr.course_code = lec.course_code
            WHERE lec.staff_id = ? AND cr.course_code IN ($placeholders)
            
            UNION
            
            SELECT DISTINCT st.SID AS student_id, st.Fname AS first_name, st.Lname AS last_name
            FROM exams e
            INNER JOIN students st ON e.Sid = st.SID
            INNER JOIN course_lecturer lec ON e.Course_Code = lec.course_code
            WHERE lec.staff_id = ? AND e.Course_Code IN ($placeholders)
        ) as combined
        ORDER BY first_name, last_name
    ";
    
    if ($stmtStud = $db->prepare($sqlStud)) {
        $bindTypes = 's' . $types . 's' . $types;
        $bindArgs = array_merge([$staffId], $codes, [$staffId], $codes);
        $stmtStud->bind_param($bindTypes, ...$bindArgs);
        $stmtStud->execute();
        $resStud = $stmtStud->get_result();
        while ($row = $resStud->fetch_assoc()) {
            $students[] = $row;
        }
        $stmtStud->close();
    }
}
$filters = [
    'flag' => $_POST['flag'] ?? ($_GET['flag'] ?? 'all'),
    'q' => $_POST['q'] ?? ($_GET['q'] ?? ''),
    'academic_year' => $_POST['academic_year'] ?? ($_GET['academic_year'] ?? ''),
    'semester' => $_POST['semester'] ?? ($_GET['semester'] ?? ''),
    'course_code' => $_POST['course_code'] ?? ($_GET['course_code'] ?? ''),
    'program_code' => $_POST['program_code'] ?? ($_GET['program_code'] ?? ''),
];
$report = student_progression_report($db, $filters, 'lecturer', $staffId);
$aiStatus = wuc_ai_local_status();
$result = null;
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wuc_verify_csrf();
    $rate = wuc_ai_rate_limit('lecturer_progression_insights', 8, 3600);
    if (!$rate['ok']) {
        $errors[] = 'Too many AI requests. Please try again in about ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.';
    }

    if (!$errors) {
        $context = [
            'filters' => $filters,
            'stats' => $report['stats'],
            'assigned_courses' => $report['assigned_courses'],
            'rows' => array_slice($report['rows'], 0, 40),
            'generated_at' => $report['generated_at'],
            'rules' => [
                'lecturer_scope_only' => true,
                'do_not_change_marks_or_status' => true,
                'recommend_follow_up_not_discipline' => true,
            ],
        ];
        $contextJson = wuc_ai_context_json($context, 18000);
        $result = wuc_ai_generate($db, [
            'feature' => 'lecturer_progression_insights',
            'user_role' => 'lecturer',
            'user_id' => $staffId,
            'input_summary' => 'progression insights | ' . json_encode($filters, JSON_UNESCAPED_SLASHES),
            'context_hash' => hash('sha256', $contextJson),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are the ITC Portal lecturer progression-insights assistant. Summarize only the supplied lecturer-scoped report rows. Identify priority follow-up groups and practical academic support actions. Do not change marks, student statuses, fees, registrations, or official records.',
                ],
                [
                    'role' => 'user',
                    'content' => "Progression report JSON:\n{$contextJson}\n\nReturn concise insights, grouped risks, and recommended lecturer follow-up actions.",
                ],
            ],
            'fallback' => static function () use ($report): string {
                return lecturer_insights_fallback($report);
            },
        ]);
    }
}

require_once __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard lecturer-workflow-page ai-progression-insights-page">
    <div class="dashboard-header lecturer-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title">AI Progression Insights</h1>
                <p class="text-muted mb-0">Summarize failed and inactive progression alerts for your assigned courses.</p>
            </div>
            <div class="col-auto d-flex gap-2">
                <a class="btn btn-outline-secondary" href="student_progression_report.php">
                    <i class="fas fa-table me-2"></i>Full Report
                </a>
                <span class="badge <?php echo $aiStatus['model_ready'] ? 'bg-success' : 'bg-secondary'; ?> align-self-center">
                    <?php echo $aiStatus['model_ready'] ? 'AI ready' : 'Fallback mode'; ?>
                </span>
            </div>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <?php foreach (array_unique($errors) as $error): ?>
                <div><?php echo lecturer_insights_h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small fw-semibold">Flagged</div>
            <div class="h3 mb-0"><?php echo number_format((int)$report['stats']['flagged']); ?></div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small fw-semibold">Failed</div>
            <div class="h3 mb-0 text-danger"><?php echo number_format((int)$report['stats']['failed']); ?></div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small fw-semibold">Inactive</div>
            <div class="h3 mb-0 text-secondary"><?php echo number_format((int)$report['stats']['inactive']); ?></div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small fw-semibold">Critical</div>
            <div class="h3 mb-0 text-warning"><?php echo number_format((int)$report['stats']['both']); ?></div>
        </div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <section class="data-table-card h-100">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter me-2"></i>Insight filters</h5></div>
                <div class="card-body">
                    <form method="post" action="ai_progression_insights.php">
                        <input type="hidden" name="csrf_token" value="<?php echo lecturer_insights_h($_SESSION['csrf_token'] ?? ''); ?>">
                        <div class="mb-3">
                            <label class="form-label">Flag</label>
                            <select class="form-select" name="flag">
                                <?php foreach (['all' => 'All alerts', 'failed' => 'Failed only', 'inactive' => 'Inactive only', 'both' => 'Failed + inactive'] as $value => $label): ?>
                                    <option value="<?php echo lecturer_insights_h($value); ?>" <?php echo $filters['flag'] === $value ? 'selected' : ''; ?>><?php echo lecturer_insights_h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Academic Year</label>
                                <select class="form-select" name="academic_year">
                                    <option value="">All years</option>
                                    <?php foreach ($academicYears as $year): ?>
                                        <option value="<?php echo lecturer_insights_h($year); ?>" <?php echo $filters['academic_year'] === $year ? 'selected' : ''; ?>><?php echo lecturer_insights_h($year); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo lecturer_insights_h($periodLabel); ?></label>
                                <select class="form-select" name="semester">
                                    <option value=""><?php echo 'All ' . strtolower(lecturer_insights_h($periodLabel)) . 's'; ?></option>
                                    <?php foreach ($semesters as $sem): ?>
                                        <option value="<?php echo lecturer_insights_h($sem); ?>" <?php echo $filters['semester'] === $sem ? 'selected' : ''; ?>>
                                            <?php echo lecturer_insights_h(lecturer_insights_format_period($sem, $assignedPeriodLabels)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Course</label>
                            <?php if (empty($assignedCourses)): ?>
                                <input class="form-control" name="course_code" value="<?php echo lecturer_insights_h($filters['course_code']); ?>" placeholder="No assigned courses found" readonly>
                            <?php else: ?>
                                <select class="form-select" name="course_code">
                                    <option value="">All assigned courses</option>
                                    <?php foreach ($assignedCourses as $course): ?>
                                        <?php $code = (string)$course['course_code']; ?>
                                        <option value="<?php echo lecturer_insights_h($code); ?>" <?php echo strcasecmp($filters['course_code'], $code) === 0 ? 'selected' : ''; ?>>
                                            <?php echo lecturer_insights_h($code . ' - ' . ($course['course_name'] ?: 'No Name')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Student</label>
                            <select class="form-select" name="q">
                                <option value="">All students</option>
                                <?php foreach ($students as $student): ?>
                                    <?php $sid = (string)$student['student_id']; ?>
                                    <option value="<?php echo lecturer_insights_h($sid); ?>" <?php echo strcasecmp($filters['q'], $sid) === 0 ? 'selected' : ''; ?>>
                                        <?php echo lecturer_insights_h($sid . ' - ' . $student['first_name'] . ' ' . $student['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-wand-magic-sparkles me-2"></i>Generate insights
                        </button>
                    </form>
                </div>
            </section>
        </div>

        <div class="col-xl-8">
            <section class="data-table-card h-100">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>AI insights</h5></div>
                <div class="card-body">
                    <?php if ($result === null): ?>
                        <div class="text-muted py-4">Generate insights to summarize the current progression alert report.</div>
                    <?php else: ?>
                        <div class="alert <?php echo $result['used_ai'] ? 'alert-info' : 'alert-warning'; ?> small">
                            <?php echo $result['used_ai'] ? 'Generated by local AI model ' . lecturer_insights_h($result['model']) . '.' : lecturer_insights_h(wuc_ai_fallback_notice($result)); ?>
                        </div>
                        <div class="bg-light border rounded p-3"><?php echo wuc_ai_output_block($result['text']); ?></div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>
