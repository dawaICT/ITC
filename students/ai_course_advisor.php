<?php
declare(strict_types=1);

$page_title = 'AI Course Advisor';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/FeeGuard.php';
require_once __DIR__ . '/includes/EligibilityService.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';
require_once __DIR__ . '/includes/period_mode_helper.php';
require_once dirname(__DIR__) . '/includes/ai_portal.php';

function student_ai_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function student_ai_verify_csrf(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return true;
    }
    $token = (string)($_POST['csrf_token'] ?? '');
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function student_ai_profile(mysqli $db, string $sid): array
{
    $profile = [
        'student_id' => $sid,
        'name' => '',
        'program_code' => '',
        'program_name' => '',
        'study_mode' => '',
        'period_mode' => '',
    ];

    $sql = "SELECT s.Fname, s.Lname, sp.program_code, p.program_name, p.study_mode, p.period_mode
            FROM students s
            LEFT JOIN student_program sp ON sp.id = (
                SELECT MAX(sp2.id) FROM student_program sp2 WHERE sp2.Sid = s.SID
            )
            LEFT JOIN programs p ON p.program_code = sp.program_code
            WHERE s.SID = ?
            LIMIT 1";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $sid);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $profile['name'] = trim((string)($row['Fname'] ?? '') . ' ' . (string)($row['Lname'] ?? ''));
                $profile['program_code'] = (string)($row['program_code'] ?? '');
                $profile['program_name'] = (string)($row['program_name'] ?? '');
                $profile['study_mode'] = (string)($row['study_mode'] ?? '');
                $profile['period_mode'] = (string)($row['period_mode'] ?? '');
            }
        }
        $stmt->close();
    }

    return $profile;
}

function student_ai_build_context(mysqli $db, string $sid): array
{
    $service = new RegistrationDataService($db);
    $profile = student_ai_profile($db, $sid);
    $status = $service->getRegistrationStatus($sid);
    $term = $status['current_term'] ?? null;

    $year = isset($term['year_of_study']) ? (int)$term['year_of_study'] : 0;
    $semester = isset($term['semester']) ? (int)$term['semester'] : 0;
    $programCode = trim((string)($term['program_code'] ?? ''));

    // Add `period` alias for AI prompts — keep `semester` for UI and legacy callers.
    if (is_array($term) && isset($term['semester'])) {
        $term['period'] = $term['semester'];
    }
    if ($programCode === '') {
        $programCode = (string)$profile['program_code'];
    }

    $availableCourses = [];
    $registeredCourses = [];
    $paymentStatus = $status['payment_status'] ?? null;
    $totalCredits = 0;

    if ($year > 0) {
        if ($programCode !== '') {
            $availableCourses = $service->getAvailableCourses($programCode, $year, max(1, $semester));
        }
        $registeredCourses = $status['registered_courses'] ?? [];
        $totalCredits = (int)($status['total_credits'] ?? 0);
        if ($paymentStatus === null && $semester > 0) {
            $paymentStatus = $service->getPaymentStatus($sid, $year, $semester);
        }
    }

    $failedCourses = [];
    try {
        $failedCourses = EligibilityService::getFailedCourses($db, $sid);
    } catch (Throwable $e) {
        error_log('students/ai_course_advisor failed-course context: ' . $e->getMessage());
    }

    $rawPmCtx = strtolower(trim((string)(getStudentProgramPeriodMode($db, $sid) ?: ($profile['period_mode'] ?? ''))));
    $periodLabelCtx = wuc_period_label_from_structure($rawPmCtx !== '' ? $rawPmCtx : 'semester');

    $hasPeriodReg  = (bool)($status['has_semester_registration'] ?? false);
    $hasCourseReg  = (bool)($status['has_course_registration']  ?? false);
    $canAddCourses = (bool)($status['can_register_courses']     ?? false);
    $periodLabelFull = (string)($status['period_label'] ?? '');

    return [
        'period_label' => $periodLabelCtx,
        'period_label_full' => $periodLabelFull,
        'student'      => $profile,
        'registration' => [
            'period_registration_status' => $hasPeriodReg  ? 'registered for current period' : 'not yet registered for current period',
            'course_registration_status' => $hasCourseReg  ? 'courses have been selected'    : 'no courses selected yet',
            'course_registration_window' => $canAddCourses ? 'open — student may add or drop courses' : 'closed — contact the registrar to make changes',
            'current_term' => $term,
        ],
        'available_courses'        => array_slice(array_values($availableCourses), 0, 40),
        'registered_courses'       => array_slice(array_values($registeredCourses), 0, 40),
        'total_registered_credits' => $totalCredits,
        'payment_status'           => $paymentStatus,
        'failed_courses'           => array_slice(array_values($failedCourses), 0, 20),
    ];
}

function student_ai_fallback_advice(array $context, string $question): string
{
    $term = $context['registration']['current_term'] ?? [];
    $available = $context['available_courses'] ?? [];
    $registered = $context['registered_courses'] ?? [];
    $payment = $context['payment_status'] ?? [];
    $failed = $context['failed_courses'] ?? [];

    $lines = [];
    $lines[] = "AI advisory fallback summary";
    $lines[] = "";
    $lines[] = "Your question: " . ($question !== '' ? $question : 'General course registration advice');

    if (empty($context['registration']['has_semester_registration'])) {
        $lines[] = "- No active semester/term registration was found. Complete semester registration before selecting courses.";
    } else {
        $lines[] = "- Current term: Year " . (string)($term['year_of_study'] ?? 'not set') . ", Semester/Term " . (string)($term['period'] ?? $term['semester'] ?? 'not set') . ".";
    }

    $lines[] = "- Available courses found: " . count($available) . ".";
    $lines[] = "- Registered courses found: " . count($registered) . ".";
    $lines[] = "- Registered credits: " . (string)($context['total_registered_credits'] ?? 0) . ".";

    if (is_array($payment) && $payment !== []) {
        $lines[] = "- Payment threshold: " . (!empty($payment['meets_threshold']) ? 'met' : 'not met or not confirmed') . ".";
        $lines[] = "- Estimated balance: ZMW " . number_format((float)($payment['balance'] ?? 0), 2) . ".";
    }

    if ($failed) {
        $codes = array_map(static fn($row): string => (string)($row['course_code'] ?? ''), $failed);
        $lines[] = "- Failed/carryover courses to review: " . implode(', ', array_filter($codes)) . ".";
    }

    $lines[] = "";
    $lines[] = "Use this as guidance only. Final registration and finance decisions remain with the official portal records and the relevant office.";

    return implode("\n", $lines);
}

$sid = (string)($_SESSION['Sid'] ?? '');
$context = student_ai_build_context($db, $sid);
$rawPm = strtolower(trim((string)(getStudentProgramPeriodMode($db, $sid) ?: ($context['student']['period_mode'] ?? ''))));
$periodLabel = wuc_period_label_from_structure($rawPm !== '' ? $rawPm : 'semester');
$periodDisplay = (string)($context['period_label_full'] ?? '');
if ($periodDisplay === '') {
    $termCtx = $context['registration']['current_term'] ?? [];
    $periodNum = $termCtx['period'] ?? $termCtx['semester'] ?? null;
    $periodDisplay = $periodNum
        ? ($periodLabel . ' ' . $periodNum . (!empty($termCtx['academic_year']) ? ', Academic Year ' . $termCtx['academic_year'] : ''))
        : 'No period';
}
$aiStatus = wuc_ai_local_status();
$question = trim((string)($_POST['question'] ?? ''));
$answer = null;
$formError = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!student_ai_verify_csrf()) {
        $formError = 'Your request could not be verified. Refresh the page and try again.';
    } else {
        $question = wuc_ai_truncate($question, 1200);
        if ($question === '') {
            $question = 'Review my current course registration status and advise what I should check next.';
        }

        $rate = wuc_ai_rate_limit('student_course_advisor', 8, 3600);
        if (!$rate['ok']) {
            $formError = 'Too many AI requests. Please try again in about ' . max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.';
        } else {
            $contextJson = wuc_ai_context_json($context);
            $answer = wuc_ai_generate($db, [
                'feature' => 'student_course_advisor',
                'user_role' => 'student',
                'user_id' => $sid,
                'input_summary' => $question,
                'context_hash' => hash('sha256', $contextJson),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are the ITC (Industrial Training Centre) student course registration advisor on the ITC student portal in Lusaka, Zambia. '
                                   . 'ITC is a vocational training centre, not a university. '
                                   . 'You are read-only: never claim to register, drop, approve, clear finance, or change any record. '
                                   . 'Use only the portal data provided. If specific information is missing (e.g. exact dates), say so and direct the student to the relevant office or portal section. '
                                   . 'All fees and balances are in ZMW (Zambian Kwacha). '
                                   . 'Data field guide — translate data fields into plain English; never expose raw JSON key names or paths (like "registration.can_register_courses") in your response: '
                                   . '`period_label` is the correct word for academic periods ("Semester", "Term", "Module", or "Intake") — always use this word, never hard-code "semester" if the label differs. '
                                   . '`registration.current_term.period` is the period number — say e.g. "Term 1", not "period: 1". '
                                   . '`student.attendance_mode` ("Full Time"/"Part Time") is physical attendance, NOT the academic period structure. '
                                   . 'Writing style: clear, formal English appropriate for a technical training institution. '
                                   . 'Avoid emojis, decorative symbols, unnecessary bold text, excessive bullet points, and scripted phrases such as "Certainly", "I hope this helps", or "As an AI". '
                                   . 'Keep responses direct, respectful, and professionally appropriate. '
                                   . 'Never invent dates, grades, balances, or records not present in the context.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Student question:\n{$question}\n\nPortal context JSON:\n{$contextJson}",
                    ],
                ],
                'fallback' => static function () use ($context, $question): string {
                    return student_ai_fallback_advice($context, $question);
                },
            ]);
            // wuc_ai_sanitize() is applied inside wuc_ai_generate() globally.
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title . ' - ITC', ENT_QUOTES, 'UTF-8'); ?></title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
</head>
<body>
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="content-wrapper pt-3 pb-5">
    <div class="container-fluid px-3 px-lg-4 portal-dashboard ai-course-advisor-page">
        <div class="dashboard-header student-section mb-4">
            <div class="row align-items-center g-3">
                <div class="col">
                    <h1 class="dashboard-title">AI Course Advisor</h1>
                    <p class="text-muted mb-0">Review registration, course, finance, and carryover signals before you submit course choices.</p>
                </div>
                <div class="col-auto">
                    <span class="badge <?php echo $aiStatus['model_ready'] ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo $aiStatus['model_ready'] ? 'AI ready' : 'Fallback mode'; ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($formError !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo student_ai_h($formError); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xl-5">
                <section class="data-table-card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-wand-magic-sparkles me-2"></i>Ask for advice</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="ai_course_advisor.php">
                            <input type="hidden" name="csrf_token" value="<?php echo student_ai_h($_SESSION['csrf_token'] ?? ''); ?>">
                            <label for="question" class="form-label fw-semibold">Question</label>
                            <textarea class="form-control" id="question" name="question" rows="6" maxlength="1200" placeholder="Example: What courses should I check before submitting registration?"><?php echo student_ai_h($question); ?></textarea>
                            <div class="form-text">The advisor can explain records it can see. It cannot submit or change registration.</div>
                            <button type="submit" class="btn btn-primary mt-3">
                                <i class="fas fa-paper-plane me-2"></i>Generate advice
                            </button>
                        </form>
                    </div>
                </section>
            </div>

            <div class="col-xl-7">
                <section class="data-table-card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Advisor response</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($answer === null): ?>
                            <div class="text-muted py-4">
                                Ask a question to generate a course registration advisory based on your current portal data.
                            </div>
                        <?php else: ?>
                            <div class="alert <?php echo $answer['used_ai'] ? 'alert-info' : 'alert-warning'; ?> small">
                                <?php echo $answer['used_ai'] ? 'Generated by local AI model ' . student_ai_h($answer['model']) . '.' : student_ai_h(wuc_ai_fallback_notice($answer)); ?>
                            </div>
                            <div class="bg-light border rounded p-3"><?php echo wuc_ai_output_block($answer['text']); ?></div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>

        <div class="row g-4 mt-1">
            <div class="col-lg-4">
                <section class="data-table-card h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold mb-2">Current Period</div>
                        <?php $term = $context['registration']['current_term'] ?? []; ?>
                        <div class="fw-semibold">
                            <?php if (!empty($context['registration']['has_semester_registration'])): ?>
                                Year <?php echo student_ai_h($term['year_of_study'] ?? 'Not set'); ?>,
                                <?php echo student_ai_h($periodDisplay); ?>
                            <?php else: ?>
                                <?php echo student_ai_h($periodDisplay !== 'No period' ? $periodDisplay : 'Not registered for the current period'); ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">Program: <?php echo student_ai_h($context['student']['program_code'] ?? ''); ?></div>
                    </div>
                </section>
            </div>
            <div class="col-lg-4">
                <section class="data-table-card h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold mb-2">Courses</div>
                        <div class="fw-semibold"><?php echo count($context['registered_courses']); ?> registered / <?php echo count($context['available_courses']); ?> available</div>
                        <div class="text-muted small"><?php echo student_ai_h((string)$context['total_registered_credits']); ?> registered credits</div>
                    </div>
                </section>
            </div>
            <div class="col-lg-4">
                <section class="data-table-card h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold mb-2">Finance Signal</div>
                        <?php $payment = is_array($context['payment_status'] ?? null) ? $context['payment_status'] : []; ?>
                        <div class="fw-semibold"><?php echo !empty($payment['meets_threshold']) ? 'Threshold met' : 'Check with Accounts'; ?></div>
                        <div class="text-muted small">Balance: ZMW <?php echo number_format((float)($payment['balance'] ?? 0), 2); ?></div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>
</body>
</html>
