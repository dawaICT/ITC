<?php
// ===== SECURITY & SETUP =====
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/DatabaseConnection.php';
require_once __DIR__ . '/includes/AcademicSessionService.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';
require_once __DIR__ . '/includes/StudentDataService.php';
require_once __DIR__ . '/includes/period_mode_helper.php';
require_once __DIR__ . '/includes/FeeGuard.php';
require_once __DIR__ . '/includes/StudentAcademicWorkflowService.php';
require_once dirname(__DIR__) . '/includes/short_course_student.php';
require_once dirname(__DIR__) . '/includes/page_meta.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Database connection
try {
    $dbConnection = DatabaseConnection::getInstance();
    $mysqli = $dbConnection->getMysqli();
    if (!$mysqli || !$mysqli->ping()) {
        throw new Exception("Database unavailable");
    }
} catch (Exception $e) {
    http_response_code(500);
    die('<div class="error-container"><h2>Service Unavailable</h2><p>Please try again later</p></div>');
}

// Initialize services
try {
    $sessionService = new AcademicSessionService($mysqli);
    $regDataService = new RegistrationDataService($mysqli);
    $studentDataService = new StudentDataService($mysqli);
    $workflowService = new StudentAcademicWorkflowService($mysqli);
} catch (Exception $e) {
    http_response_code(500);
    die('<div class="error-container"><h2>System Error</h2><p>Contact administrator</p></div>');
}

// ===== HELPER FUNCTIONS =====

function semester_registration_columns(mysqli $mysqli): array
{
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }

    $cols = [];
    if ($meta = $mysqli->query("SHOW COLUMNS FROM semester_registration")) {
        while ($c = $meta->fetch_assoc()) {
            $cols[strtolower((string)$c['Field'])] = (string)$c['Field'];
        }
        $meta->free();
    }

    return $cols;
}

/**
 * Returns 'transfer', 'returning', or 'new'.
 */
function getStudentType(mysqli $mysqli, string $studentId): string
{
    // `is_transfer` is not present in every install. mysqli throws on an unknown
    // column (which fatals the whole page), so probe for it before selecting it
    // and degrade gracefully when it is absent.
    $hasIsTransfer = false;
    if ($probe = @$mysqli->query("SHOW COLUMNS FROM students LIKE 'is_transfer'")) {
        $hasIsTransfer = $probe->num_rows > 0;
        $probe->free();
    }

    if ($hasIsTransfer) {
        $stmt = $mysqli->prepare("SELECT is_transfer FROM students WHERE SID = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!empty($row['is_transfer'])) {
                return 'transfer';
            }
        }
    }

    $cols = semester_registration_columns($mysqli);
    $sidChecks = [];
    foreach (['student_id', 'sid'] as $candidate) {
        if (isset($cols[$candidate])) {
            $sidChecks[] = "`{$cols[$candidate]}` = ?";
        }
    }
    if (!$sidChecks) {
        return 'new';
    }

    $stmt = $mysqli->prepare('SELECT COUNT(*) AS cnt FROM semester_registration WHERE (' . implode(' OR ', $sidChecks) . ')');
    if ($stmt) {
        $params = array_fill(0, count($sidChecks), $studentId);
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
        return $cnt > 0 ? 'returning' : 'new';
    }

    return 'new';
}

/**
 * Returns latest semester_registration row for the period, or null.
 */
function checkSemesterRegistration(
    mysqli $mysqli,
    string $studentId,
    string $academicYear,
    string $semester,
    string $periodType = 'semester'
): ?array {
    $cols = semester_registration_columns($mysqli);

    $sidCols = [];
    foreach (['student_id', 'sid'] as $candidate) {
        if (isset($cols[$candidate])) {
            $sidCols[] = $cols[$candidate];
        }
    }
    if (!$sidCols) {
        return null;
    }
    $semCol = $cols['semester'] ?? ($cols['semester_term'] ?? 'semester');
    $academicYearCol = $cols['academic_year'] ?? null;
    $periodTypeCol = $cols['period_type'] ?? null;

    $sidSql = implode(' OR ', array_map(static fn(string $column): string => "`{$column}` = ?", $sidCols));
    $sql = "SELECT * FROM semester_registration WHERE ({$sidSql}) AND `{$semCol}` = ?";
    $types = str_repeat('s', count($sidCols)) . 's';
    $params = array_fill(0, count($sidCols), $studentId);
    $params[] = $semester;

    if ($periodTypeCol) {
        $sql .= " AND `{$periodTypeCol}` = ?";
        $types .= "s";
        $params[] = wuc_legacy_period_type($periodType);
    }

    if ($academicYearCol && $academicYear !== '') {
        $sql .= " AND (`{$academicYearCol}` = ? OR `{$academicYearCol}` LIKE ? OR ? LIKE CONCAT(`{$academicYearCol}`, '%'))";
        $types .= "sss";
        $params[] = $academicYear;
        $params[] = substr($academicYear, 0, 4) . '%';
        $params[] = $academicYear;
    }

    $sql .= " ORDER BY id DESC LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $refs = [$types];
    foreach ($params as $i => &$param) {
        $refs[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Returns the primary key value from semester_registration row.
 */
function getSemRegId(array $row): string
{
    if (isset($row['id'])) {
        return (string)$row['id'];
    }
    if (isset($row['reg_id'])) {
        return (string)$row['reg_id'];
    }
    throw new RuntimeException(
        "semester_registration row has neither 'id' nor 'reg_id'. Check your schema."
    );
}

/**
 * Assess whether registration window is open/late using academic_periods fields.
 * Returns:
 * [
 *   'is_open' => bool,
 *   'is_late' => bool,
 *   'notice' => string,
 *   'start_date' => ?string,
 *   'end_date' => ?string
 * ]
 */
function assessRegistrationWindow(array $currentSession): array
{
    $today = new DateTimeImmutable(date('Y-m-d'));
    $startRaw = (string)($currentSession['start_date'] ?? '');
    $endRaw = (string)($currentSession['end_date'] ?? '');
    $status = strtolower(trim((string)($currentSession['status'] ?? '')));

    $startDate = null;
    $endDate = null;
    try {
        if ($startRaw !== '') {
            $startDate = new DateTimeImmutable($startRaw);
        }
    } catch (Throwable $e) {
        $startDate = null;
    }
    try {
        if ($endRaw !== '') {
            $endDate = new DateTimeImmutable($endRaw);
        }
    } catch (Throwable $e) {
        $endDate = null;
    }

    $isOpen = true;
    $isLate = false;
    $notice = '';

    if ($status === 'closed') {
        $isOpen = false;
        $isLate = false;
        $notice = 'Registration period is closed for this term.';
    } elseif ($startDate && $today < $startDate) {
        $isOpen = false;
        $notice = 'Registration has not opened yet.';
    } elseif ($endDate && $today > $endDate) {
        $isOpen = false;
        $isLate = true;
        $notice = 'Registration period has ended. You are registering late.';
    }

    return [
        'is_open' => $isOpen,
        'is_late' => $isLate,
        'notice' => $notice,
        'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
        'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
    ];
}

/**
 * Build all registration state used by UI and request handling.
 */
function buildRegistrationState(
    mysqli $mysqli,
    RegistrationDataService $regDataService,
    string $studentId,
    string $currentAcademicYear,
    string $currentSem,
    string $periodType,
    array $studentDetails,
    array $windowState
): array {
    $studentType = getStudentType($mysqli, $studentId);
    $semesterRegistration = checkSemesterRegistration($mysqli, $studentId, $currentAcademicYear, $currentSem, $periodType);
    $isSemesterRegistered = !empty($semesterRegistration);
    $hasCourseReg = false;
    $isLateRegistration = !empty($windowState['is_late']);
    $requiresSystemsOffice = false;
    $forceRepeatRegistration = false;

    if ($isLateRegistration) {
        if ($studentType === 'returning') {
            $forceRepeatRegistration = true;
        } else {
            $requiresSystemsOffice = true;
        }
    }

    if ($isSemesterRegistered) {
        // Course completion must be scoped to this exact semester_registration
        // row so stale, unlinked course_registration rows do not mark progress complete.
        try {
            $registeredCourses = $regDataService->getRegisteredCourses(
                $studentId,
                (int)($semesterRegistration['year_of_study'] ?? ($studentDetails['year_of_study'] ?? 1)),
                (int)($semesterRegistration['semester'] ?? $currentSem),
                (int)getSemRegId($semesterRegistration),
                $currentAcademicYear
            );
            $hasCourseReg = !empty($registeredCourses);
        } catch (RuntimeException $e) {
            error_log('registration.php course registration status check failed for ' . $studentId . ': ' . $e->getMessage());
            $hasCourseReg = false;
        }
    }

    $currentStep = 1;
    if ($isSemesterRegistered) {
        $currentStep = $hasCourseReg ? 3 : 2;
    }

    return [
        'studentType' => $studentType,
        'semesterRegistration' => $semesterRegistration,
        'isSemesterRegistered' => $isSemesterRegistered,
        'hasCourseReg' => $hasCourseReg,
        'isLateRegistration' => $isLateRegistration,
        'requiresSystemsOffice' => $requiresSystemsOffice,
        'forceRepeatRegistration' => $forceRepeatRegistration,
        'currentStep' => $currentStep,
    ];
}

// ===== SESSION & STUDENT SETUP =====
$studentId = $_SESSION['Sid'] ?? $_SESSION['student_id'] ?? null;
if (!$studentId) {
    $_SESSION['errorMessage'] = "Student ID not found. Please log in again.";
    header('Location: ../student_login.php');
    exit;
}

$studentDetails = $studentDataService->getStudentWithProgram($studentId);
if (!$studentDetails) {
    $_SESSION['errorMessage'] = "Student information unavailable.";
    header('Location: ../student_login.php');
    exit;
}

// ===== SHORT COURSE STUDENTS =====
// Short-course students do not go through semester/term registration;
// show them their short course enrolment status and schedule.
$isShortCourseStudentUser = isShortCourseStudent($mysqli, (string)$studentId);
$scEnrolments = ($isShortCourseStudentUser || empty($studentDetails['program_code']))
    ? sc_student_enrolments($mysqli, (string)$studentId)
    : [];

if ($scEnrolments) {
    $scFullName = trim((string)($studentDetails['full_name'] ?? ''));
    $scStatusBadge = static function (string $status): string {
        $map = [
            'enrolled'  => 'info',
            'active'    => 'primary',
            'completed' => 'success',
            'withdrawn' => 'secondary',
            'expired'   => 'danger',
        ];
        return $map[strtolower($status)] ?? 'secondary';
    };
    $scDate = static function ($value): string {
        if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime((string)$value);
        return $ts ? date('d M Y', $ts) : '—';
    };
    $scStatusText = static function ($value): string {
        $value = strtolower(trim((string)$value));
        return $value !== '' ? ucfirst($value) : 'Unknown';
    };
    $scField = static function (string $label, string $value): void {
        echo '<div class="col-sm-6 col-md-4">'
            . '<div class="text-uppercase small fw-bold text-secondary mb-1">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div class="fw-semibold text-dark" style="overflow-wrap:anywhere;">' . $value . '</div>'
            . '</div>';
    };
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Short Course Enrolment - ITC</title>
<?php wuc_portal_favicon_links(); ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="/wucportal/css/admin-style.css">
        <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
        <link rel="stylesheet" href="../css/consistent-styles.css">
        <link rel="stylesheet" href="../css/debug.css">
    </head>
    <body class="bg-light student-registration-theme">
        <?php require_once __DIR__ . '/includes/navbar.php'; ?>
        <div class="content-wrapper">
            <div class="container">
                <div class="row">
                    <div class="col-12">
                        <div class="page-header d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="page-title"><i class="fas fa-certificate text-warning me-2"></i>My Short Course</h2>
                                <nav aria-label="breadcrumb">
                                    <ol class="breadcrumb mb-0 small">
                                        <li class="breadcrumb-item">Academics</li>
                                        <li class="breadcrumb-item active">My Short Course</li>
                                    </ol>
                                </nav>
                            </div>
                            <div class="stat-badges">
                                <span class="stat-badge"><i class="fas fa-layer-group me-1"></i><?= count($scEnrolments) ?> Course(s)</span>
                            </div>
                        </div>

                        <p class="text-secondary mb-3">
                            <?php if ($scFullName !== ''): ?>Hi <?= htmlspecialchars($scFullName, ENT_QUOTES, 'UTF-8') ?> &mdash; <?php endif; ?>
                            your duration-based registration is managed by the admissions office and follows each course's actual dates, not a semester. Below is your current enrolment status.
                        </p>

                <?php foreach ($scEnrolments as $en):
                    $status = (string)($en['status'] ?? '');
                    $statusLower = strtolower(trim($status));
                    $courseStatus = strtolower(trim((string)($en['course_status'] ?? 'active')));
                    $duration = (int)($en['duration_value'] ?? 0) > 0
                        ? (int)$en['duration_value'] . ' ' . ucfirst((string)($en['duration_unit'] ?? ''))
                        : '—';
                    $delivery = trim((string)($en['delivery_mode'] ?? '')) !== ''
                        ? ucfirst((string)$en['delivery_mode'])
                        : '—';
                    $hasSchedule = !empty($en['start_date']) || !empty($en['end_date']);
                    // Derive an expected finish from start_date (or, lacking that, the
                    // enrolment date) + duration, so the card stays informative even when
                    // the admin left the course's explicit dates blank.
                    $startForDerive = !empty($en['start_date'])
                        ? (string)$en['start_date']
                        : (string)($en['enrollment_date'] ?? '');
                    $expectedEnd = sc_derive_end_date(
                        $startForDerive,
                        $en['duration_value'] ?? 0,
                        $en['duration_unit'] ?? '',
                        $en['end_date'] ?? null
                    );
                    $isCompleted = $statusLower === 'completed';
                    $todayTs = strtotime(date('Y-m-d'));
                    $startTs = !empty($en['start_date']) ? strtotime((string)$en['start_date']) : null;
                    $endTs = !empty($en['end_date']) ? strtotime((string)$en['end_date']) : ($expectedEnd ? strtotime($expectedEnd) : null);
                    $completionDate = $en['completion_date'] ?? null;
                    $completionLabel = 'Completion Date';
                    if ($isCompleted && empty($completionDate) && !empty($en['enrollment_updated_at'])) {
                        $completionDate = $en['enrollment_updated_at'];
                        $completionLabel = 'Completion Recorded';
                    }
                    $statusIssues = [];
                    if ($statusLower === 'enrolled' && $startTs && $startTs <= $todayTs) {
                        $statusIssues[] = 'Course has started but enrolment is still marked Enrolled.';
                    }
                    $rawEndTs = !empty($en['end_date']) ? strtotime((string)$en['end_date']) : null;
                    if (in_array($statusLower, ['enrolled', 'active'], true)
                        && $rawEndTs && $rawEndTs < $todayTs
                    ) {
                        $statusIssues[] = 'Course end date has passed but enrolment is not marked Completed or Expired.';
                    }
                    // The catalogue row (short_courses) is missing both dates. Only warn
                    // the student if we ALSO can't derive an end date from duration +
                    // enrollment_date - otherwise the "Expected Completion" field above
                    // already tells them what they need.
                    if ($statusLower === 'active'
                        && !$startTs
                        && empty($en['end_date'])
                        && empty($expectedEnd)
                    ) {
                        $statusIssues[] =
                            'Course schedule not yet published - admissions has not set '
                            . 'this course\'s start/end dates. Contact the Admissions '
                            . 'Office if this affects you.';
                    }
                    if ($isCompleted && empty($completionDate)) {
                        $statusIssues[] = 'Completed enrolment is missing a completion date.';
                    }
                    if (!$isCompleted && !empty($en['completion_date'])) {
                        $statusIssues[] = 'Completion date exists but enrolment is not marked Completed.';
                    }
                    if ($courseStatus !== '' && $courseStatus !== 'active') {
                        $statusIssues[] = 'Course catalogue status is ' . ucfirst($courseStatus) . '.';
                    }
                ?>
                <section class="card border-0 shadow-sm mb-3" style="border-radius: 12px;">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            <h2 class="h5 fw-bold text-dark mb-0">
                                <?= htmlspecialchars((string)($en['course_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                &mdash; <?= htmlspecialchars((string)($en['course_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </h2>
                            <span class="badge bg-<?= $scStatusBadge($status) ?> text-uppercase">
                                <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="row g-3">
                            <?php
                            $scField('Course Code', htmlspecialchars((string)($en['course_code'] ?? ''), ENT_QUOTES, 'UTF-8'));
                            $scField('Enrolment Status', htmlspecialchars($scStatusText($status), ENT_QUOTES, 'UTF-8'));
                            $scField('Course Status', htmlspecialchars($scStatusText($courseStatus), ENT_QUOTES, 'UTF-8'));
                            $scField('Duration', htmlspecialchars($duration, ENT_QUOTES, 'UTF-8'));
                            $scField('Delivery Mode', htmlspecialchars($delivery, ENT_QUOTES, 'UTF-8'));
                            $scField('Enrolled On', $scDate($en['enrollment_date'] ?? null));
                            if ($hasSchedule) {
                                $scField('Course Dates', $scDate($en['start_date'] ?? null) . ' &ndash; ' . $scDate($en['end_date'] ?? null));
                            }
                            if ($isCompleted) {
                                $scField($completionLabel, $scDate($completionDate));
                            } else {
                                $scField('Expected Completion', $expectedEnd ? $scDate($expectedEnd) : '—');
                            }
                            $scField(
                                'Certificate',
                                !empty($en['certificate_issued'])
                                    ? '<span class="text-success"><i class="fas fa-check-circle me-1"></i>Issued</span>'
                                    : ($isCompleted
                                        ? '<span class="text-warning"><i class="fas fa-clock me-1"></i>Pending certificate</span>'
                                        : '<span class="text-muted">Not issued yet</span>')
                            );
                            ?>
                        </div>
                        <?php if ($statusIssues): ?>
                            <div class="alert alert-warning mt-3 mb-0 py-2">
                                <div class="fw-bold mb-1"><i class="fas fa-triangle-exclamation me-1"></i>Record needs review</div>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($statusIssues as $issue): ?>
                                        <li><?= htmlspecialchars($issue, ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endforeach; ?>

                <a href="index.php" class="btn btn-dark" style="border-radius: 10px;">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
                    </div>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// Real year of study: getStudentWithProgram() does not return it, so without
// this every fee and course lookup below ran against Year 1 regardless of the
// student's actual level.
$studentDetails['year_of_study'] = max(1, $studentDataService->getStudentYearOfStudy((string)$studentId));

// ===== NORMALISE ACADEMIC YEAR =====
$studentPeriodMode = getStudentProgramPeriodMode($mysqli, (string)$studentId);
$currentSession = $sessionService->getCurrentSession($studentPeriodMode);
if (!$currentSession) {
    http_response_code(500);
    die('<div class="error-container"><h2>System Error</h2><p>Academic session not configured.</p></div>');
}
$rawAcademicYear = $currentSession['academic_year'] ?? '2025';
$currentAcademicYear = $rawAcademicYear;                   // for DB
$displayAcademicYear = htmlspecialchars($rawAcademicYear); // for UI

$currentSem = (string)($currentSession['semester_term'] ?? '1');
$periodLabel = getPeriodLabel($mysqli, (string)$studentId);
$windowState = assessRegistrationWindow($currentSession);

$activePeriod = $workflowService->getActiveAcademicPeriod((string)$studentId);
$feeEligibility = $activePeriod['ok']
    ? $workflowService->checkFeeEligibility((string)$studentId, $activePeriod)
    : null;
$registrationCheck = $activePeriod['ok']
    ? $workflowService->checkStudentRegistration((string)$studentId, $activePeriod)
    : ['is_registered' => false, 'registered_courses' => [], 'period_label' => ''];
$periodLabelFull = $registrationCheck['period_label'] !== ''
    ? $registrationCheck['period_label']
    : ($activePeriod['period_label'] ?? ($periodLabel . ' ' . $currentSem . ', Academic Year ' . $currentAcademicYear));

// ===== HANDLE ACADEMIC PERIOD REGISTRATION POST =====
$message = '';
$messageType = '';
$state = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['register_period', 'register_semester'], true)) {
    $studentType = getStudentType($mysqli, $studentId);
    $isLateRegistration = !empty($windowState['is_late']);
    $requiresSystemsOffice = false;
    $forceRepeatRegistration = false;

    if ($isLateRegistration) {
        if ($studentType === 'returning') {
            $forceRepeatRegistration = true;
        } else {
            $requiresSystemsOffice = true;
        }
    }

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "Security token invalid. Please refresh the page.";
        $messageType = 'danger';
    } elseif ($requiresSystemsOffice) {
        $message = "Late registration requires Systems Office approval for intake/semester/year update. Please contact the Systems Office.";
        $messageType = 'warning';
    } elseif (!$windowState['is_open'] && !$isLateRegistration) {
        $message = $windowState['notice'] !== '' ? $windowState['notice'] : "Registration is currently unavailable.";
        $messageType = 'warning';
    } elseif (checkSemesterRegistration($mysqli, $studentId, $currentAcademicYear, $currentSem, $studentPeriodMode)) {
        $_SESSION['reg_flash'] = [
            'type' => 'info',
            'text' => "You are already registered for {$periodLabelFull}.",
        ];
        header('Location: registration.php');
        exit;
    } elseif ($feeEligibility && !$feeEligibility['is_eligible']) {
        $message = $feeEligibility['reason'];
        $messageType = 'warning';
    } else {
        try {
            $studentType = getStudentType($mysqli, $studentId);
            $isLateRegistration = !empty($windowState['is_late']);
            $forceRepeatRegistration = $isLateRegistration && $studentType === 'returning';
            if ($studentType === 'transfer') {
                $regType = 'Transfer';
            } elseif ($forceRepeatRegistration) {
                $regType = 'Repeat';
            } else {
                $regType = 'Regular';
            }

            $result = $workflowService->registerStudentForPeriod((string)$studentId, [
                'registration_type' => $regType,
            ]);

            if (!$result['ok']) {
                $message = (string)$result['message'];
                $messageType = 'danger';
            } else {
                require_once __DIR__ . '/../includes/notification_integrations.php';
                wuc_notify_portal($mysqli, [
                    'user_id' => (string)$studentId,
                    'user_role' => 'student',
                    'module' => 'registration',
                    'alert_type' => 'registration_approved',
                    'severity' => 'info',
                    'title' => 'Registration complete',
                    'message' => (string)$result['message'],
                    'entity_type' => 'registration',
                    'entity_id' => (string)$studentId,
                    'action_url' => '/wucportal/students/registration.php',
                    'dedupe_days' => 3,
                ]);
                $_SESSION['reg_flash'] = [
                    'type' => 'success',
                    'text' => (string)$result['message'],
                ];
                header('Location: registration.php');
                exit;
            }
        } catch (Exception $e) {
            error_log('registration.php register_period failed for ' . $studentId . ': ' . $e->getMessage());
            $message = 'Registration could not be saved. Please try again or contact ICT if the problem continues.';
            $messageType = 'danger';
        }
    }
}

// Flash message from the post-redirect-get cycle above.
if (isset($_SESSION['reg_flash']['text'])) {
    $message = (string)$_SESSION['reg_flash']['text'];
    $messageType = in_array($_SESSION['reg_flash']['type'] ?? '', ['success', 'info', 'warning', 'danger'], true)
        ? $_SESSION['reg_flash']['type']
        : 'info';
    unset($_SESSION['reg_flash']);
}

if ($state === null) {
    $state = buildRegistrationState(
        $mysqli,
        $regDataService,
        $studentId,
        $currentAcademicYear,
        $currentSem,
        $studentPeriodMode,
        $studentDetails,
        $windowState
    );
}

if ($state['isSemesterRegistered'] && $studentId !== '') {
    try {
        $syncedCourses = $workflowService->ensureYearCoursesEnrolled((string)$studentId);
        if ($syncedCourses > 0) {
            $state = buildRegistrationState(
                $mysqli,
                $regDataService,
                $studentId,
                $currentAcademicYear,
                $currentSem,
                $studentPeriodMode,
                $studentDetails,
                $windowState
            );
        }
    } catch (Throwable $e) {
        error_log('registration.php ensureYearCoursesEnrolled: ' . $e->getMessage());
    }
}

$studentType = $state['studentType'];
$semesterRegistration = $state['semesterRegistration'];
$isSemesterRegistered = $state['isSemesterRegistered'];
$hasCourseReg = $state['hasCourseReg'];
$isLateRegistration = $state['isLateRegistration'];
$requiresSystemsOffice = $state['requiresSystemsOffice'];
$forceRepeatRegistration = $state['forceRepeatRegistration'];
$currentStep = $state['currentStep'];

// Resolve semester reg PK once
$semRegPk = '';
if ($isSemesterRegistered && $semesterRegistration) {
    try {
        $semRegPk = getSemRegId($semesterRegistration);
    } catch (RuntimeException $e) {
        error_log($e->getMessage());
        $message = "Warning: Could not resolve registration ID. Contact support.";
        $messageType = 'warning';
    }
}

$programName = (string)($studentDetails['program_name'] ?? 'N/A');
$programDisplay = function_exists('mb_strimwidth')
    ? mb_strimwidth($programName, 0, 34, '...')
    : (strlen($programName) > 34 ? substr($programName, 0, 31) . '...' : $programName);
$courseRegistrationUrl = $semRegPk !== '' ? 'courseReg.php?id=' . urlencode($semRegPk) : 'courseReg.php';
$registrationTypeFromRow = $isSemesterRegistered ? ($semesterRegistration['student_type'] ?? null) : null;
$registrationTypeLabel = $registrationTypeFromRow !== null && trim((string)$registrationTypeFromRow) !== ''
    ? ucfirst(strtolower((string)$registrationTypeFromRow))
    : ($forceRepeatRegistration ? 'Repeat' : ($studentType === 'transfer' ? 'Transfer' : 'Regular'));
$paymentStatus = $feeEligibility;
$requiredFeePct = $feeEligibility ? (float)$feeEligibility['required_percentage'] : 50.0;
$paymentPercent = $feeEligibility ? max(0.0, (float)$feeEligibility['payment_percentage']) : null;
$paymentBarClass = 'is-low';
if ($paymentPercent !== null && $paymentPercent >= 100.0) {
    $paymentBarClass = 'is-complete';
} elseif ($paymentPercent !== null && $paymentPercent + 1e-6 >= $requiredFeePct) {
    $paymentBarClass = 'is-ok';
}
$paymentBarWidth = $paymentPercent !== null ? min(100.0, $paymentPercent) : 0.0;
$feeEligible = $feeEligibility ? (bool)$feeEligibility['is_eligible'] : false;

$selectableCourses = [
    'context' => null,
    'already_registered' => false,
    'program_missing' => false,
    'registered_courses' => [],
    'courses' => [],
    'count' => 0,
];
if ($isSemesterRegistered && $studentId !== '') {
    $termContext = null;
    if ($semRegPk !== '') {
        $termContext = $regDataService->resolveRegistrationTermContext(
            (string)$studentId,
            (int)$semRegPk,
            null,
            null,
            null,
            false
        );
    }
    if (!$termContext) {
        $termContext = $regDataService->resolveRegistrationTermContext(
            (string)$studentId,
            null,
            $currentAcademicYear,
            (int)$currentSem,
            $studentPeriodMode,
            false
        );
    }
    $selectableCourses = $regDataService->getSelectableCoursesForTerm((string)$studentId, $termContext);
}
$selectableCourseCount = (int)($selectableCourses['count'] ?? 0);
$selectableCourseCodes = array_flip(array_map(
    static fn(array $course): string => (string)($course['course_code'] ?? ''),
    $selectableCourses['courses'] ?? []
));

$registrationHistory = [];
if ($studentId !== '') {
    // Build the history query against whatever columns actually exist in this
    // install's semester_registration table (schema drifts here), aliasing to the
    // names the view below expects so missing columns degrade to NULL.
    $cols = semester_registration_columns($mysqli);
    $sidCols   = [];
    foreach (['student_id', 'sid'] as $candidate) {
        if (isset($cols[$candidate])) {
            $sidCols[] = $cols[$candidate];
        }
    }
    if (!$sidCols) {
        $registrationHistory = [];
    } else {
    $ayCol     = $cols['academic_year'] ?? null;
    $semCol    = $cols['semester'] ?? null;
    $periodCol = $cols['period_type'] ?? null;
    $stypeCol  = $cols['student_type'] ?? null;
    $dateCol   = $cols['date_registered'] ?? ($cols['registration_date'] ?? null);
    $orderCol  = $cols['id'] ?? null;

    $select = [
        ($ayCol     ? "`{$ayCol}`"     : 'NULL') . ' AS academic_year',
        ($periodCol ? "`{$periodCol}`" : 'NULL') . ' AS period_type',
        ($semCol    ? "`{$semCol}`"    : 'NULL') . ' AS semester',
        ($stypeCol  ? "`{$stypeCol}`"  : 'NULL') . ' AS student_type',
        ($dateCol   ? "`{$dateCol}`"   : 'NULL') . ' AS date_registered',
    ];

    $whereParts = array_map(static fn(string $column): string => "`{$column}` = ?", $sidCols);
    $where  = '(' . implode(' OR ', $whereParts) . ')';
    $types  = str_repeat('s', count($sidCols));
    $params = array_fill(0, count($sidCols), $studentId);

    // Exclude the current period row only when the identifying columns exist.
    if ($ayCol && $semCol) {
        $periodExpr = $periodCol ? "COALESCE(`{$periodCol}`, 'semester')" : "'semester'";
        $where .= " AND NOT ( COALESCE(`{$ayCol}`, '') = ? AND `{$semCol}` = ? AND {$periodExpr} = ? )";
        $types .= 'sss';
        $params[] = $currentAcademicYear;
        $params[] = $currentSem;
        $params[] = $studentPeriodMode;
    }

    $order = $orderCol ? "ORDER BY `{$orderCol}` DESC" : '';
    $historySql = 'SELECT ' . implode(', ', $select)
                . " FROM semester_registration WHERE {$where} {$order} LIMIT 5";

    if ($historyStmt = $mysqli->prepare($historySql)) {
        $historyStmt->bind_param($types, ...$params);
        $historyStmt->execute();
        if ($historyRes = $historyStmt->get_result()) {
            while ($historyRow = $historyRes->fetch_assoc()) {
                $registrationHistory[] = $historyRow;
            }
        }
        $historyStmt->close();
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(wuc_portal_title($periodLabel . ' Registration')) ?></title>
<?php wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="../css/consistent-styles.css">
    <link rel="stylesheet" href="../css/debug.css">
    <style>
        .registration-page-shell {
            width: 100%;
            min-width: 0;
        }
        .reg-progress {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .6rem;
            margin-bottom: 1rem;
        }
        .reg-step {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .8rem .9rem;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
        }
        .reg-step .num {
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 50%;
            background: #f1f5f9;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .8rem;
            flex-shrink: 0;
        }
        .reg-step strong { display: block; font-size: .88rem; color: #111827; line-height: 1.2; }
        .reg-step span   { display: block; font-size: .76rem; color: #64748b; margin-top: 2px; }
        .reg-step.is-done    { background: #f0fdf4; border-color: #86efac; }
        .reg-step.is-done .num    { background: #16a34a; color: #fff; }
        .reg-step.is-current { background: #f5f1fc; border-color: #cdb9ec; }
        .reg-step.is-current .num { background: #6f42c1; color: #fff; }
        .reg-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
        }
        .reg-card h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #111827;
            margin: 0 0 .4rem;
        }
        .reg-card .lead {
            color: #64748b;
            font-size: .92rem;
            margin: 0 0 1.1rem;
            max-width: 62ch;
        }
        .reg-btn {
            min-height: 44px;
            border-radius: 10px;
            padding: .65rem 1.25rem;
            border: 0;
            background: linear-gradient(135deg, #6f42c1, #5a32a3);
            color: #fff;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
        }
        .reg-btn:hover { background: linear-gradient(135deg, #5a32a3, #4a2b9c); color: #fff; }
        .reg-btn:disabled { opacity: .55; cursor: not-allowed; }
        .reg-btn-outline {
            background: #fff;
            color: #6f42c1;
            border: 1px solid #cdb9ec;
        }
        .reg-btn-outline:hover { background: #f5f1fc; color: #5a32a3; }
        .reg-details {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1.25rem;
            margin: 0;
        }
        .reg-details dt {
            margin: 0 0 .25rem;
            color: #64748b;
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-weight: 700;
        }
        .reg-details dd {
            margin: 0;
            color: #111827;
            font-weight: 600;
            font-size: .92rem;
            overflow-wrap: anywhere;
        }
        .reg-footnote { color: #64748b; font-size: .82rem; margin: 1rem 0 0; }
        .reg-window {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .85rem 1rem;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #334155;
            font-size: .9rem;
            margin-bottom: 1rem;
        }
        .reg-window.is-late {
            background: #fffbeb;
            border-color: #fcd34d;
            color: #92400e;
        }
        .payment-progress {
            position: relative;
            height: .9rem;
            border-radius: 999px;
            background: #e5e7eb;
            overflow: hidden;
            margin: .75rem 0 .45rem;
        }
        .payment-progress .bar {
            height: 100%;
            width: 0;
            border-radius: inherit;
            background: #dc2626;
        }
        .payment-progress.is-ok .bar { background: #f59e0b; }
        .payment-progress.is-complete .bar { background: #16a34a; }
        .payment-progress .threshold {
            position: absolute;
            top: -2px;
            bottom: -2px;
            left: 50%;
            width: 2px;
            background: #111827;
            opacity: .75;
        }
        .history-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        .history-table th, .history-table td { padding: .65rem .5rem; border-bottom: 1px solid #e5e7eb; text-align: left; }
        .history-table th { color: #64748b; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; }
        @media (max-width: 720px) {
            .reg-progress { grid-template-columns: 1fr; }
            .reg-details  { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .reg-card     { padding: 1.1rem; }
        }
        @media (max-width: 420px) {
            .reg-details { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="bg-light student-registration-theme">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="content-wrapper">
        <div class="container">
        <main class="registration-page-shell">
            <div class="page-header d-flex justify-content-between align-items-center">
                <h2 class="page-title"><i class="fas fa-user-check"></i> <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?> Registration</h2>
                <div class="stat-badges">
                    <span class="stat-badge"><?= htmlspecialchars($displayAcademicYear, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="stat-badge"><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($currentSem, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="stat-badge bg-primary text-white">
                        <i class="fas fa-layer-group me-1"></i><?= $isSemesterRegistered ? ($hasCourseReg ? 'Courses Complete' : 'Registered') : 'Pending Setup' ?>
                    </span>
                    <?php if ($isSemesterRegistered && $selectableCourseCount > 0): ?>
                        <span class="stat-badge">
                            <i class="fas fa-book me-1"></i><?= (int)$selectableCourseCount ?> Course<?= $selectableCourseCount === 1 ? '' : 's' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item">Academics</li>
                    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?> Registration</li>
                </ol>
            </nav>

            <?php if (!empty($windowState['start_date']) || !empty($windowState['end_date'])): ?>
                <?php
                    $windowStart = !empty($windowState['start_date']) ? date('M d', strtotime((string)$windowState['start_date'])) : 'Open';
                    $windowEnd = !empty($windowState['end_date']) ? date('M d', strtotime((string)$windowState['end_date'])) : 'No close date';
                    $windowSuffix = '';
                    if ($isLateRegistration) {
                        $windowSuffix = $studentType === 'returning'
                            ? ' - Closed - registering as Repeat'
                            : ' - Closed - contact Systems Office';
                    }
                ?>
                <div class="reg-window <?= $isLateRegistration ? 'is-late' : '' ?>">
                    <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                    <span>Registration window: <?= htmlspecialchars($windowStart, ENT_QUOTES, 'UTF-8') ?> &mdash; <?= htmlspecialchars($windowEnd, ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars($windowSuffix, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <?php
            // Rule-based course recommendations (Sprint 6): retakes and missing
            // curriculum courses for this student's programme/year. Persisted to
            // ai_recommendations so guidance stays auditable.
            try {
                require_once dirname(__DIR__) . '/includes/course_recommendation_engine.php';
                $regCourseRecs = wuc_course_recommendations($mysqli, (string)($_SESSION['Sid'] ?? ''));
                if ($selectableCourseCount > 0 && $selectableCourseCodes) {
                    $filterToSelectable = static function (array $items) use ($selectableCourseCodes): array {
                        return array_values(array_filter(
                            $items,
                            static fn(array $item): bool => isset($selectableCourseCodes[(string)($item['course_code'] ?? '')])
                        ));
                    };
                    $regCourseRecs['missing'] = $filterToSelectable($regCourseRecs['missing'] ?? []);
                    $regCourseRecs['retake'] = $filterToSelectable($regCourseRecs['retake'] ?? []);
                    $regCourseRecs = $regDataService->applySelectableCourseNamesToRecommendations(
                        $regCourseRecs,
                        $selectableCourses
                    );
                } elseif ($isSemesterRegistered && !$hasCourseReg) {
                    $regCourseRecs['missing'] = [];
                    $regCourseRecs['retake'] = [];
                    if ($selectableCourseCount === 0 && empty($selectableCourses['program_missing'])) {
                        $regCourseRecs['notes'][] = 'No courses are configured for your registered '
                            . strtolower($periodLabel) . ' yet.';
                    }
                }
                wuc_course_recommendations_persist($mysqli, (string)($_SESSION['Sid'] ?? ''), $regCourseRecs);
                echo wuc_course_recommendations_render_card($regCourseRecs, false);
            } catch (Throwable $e) {
                error_log('students/registration.php course recommendations failed: ' . $e->getMessage());
            }
            ?>

            <?php if ($message): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?> d-flex align-items-start gap-2" role="alert">
                    <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'info' ? 'circle-info' : ($messageType === 'warning' ? 'exclamation-circle' : 'exclamation-triangle')) ?> mt-1"></i>
                    <div><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            <?php endif; ?>

            <div class="reg-progress" role="list" aria-label="Registration progress">
                <div class="reg-step <?= $isSemesterRegistered ? 'is-done' : 'is-current' ?>" role="listitem" <?= !$isSemesterRegistered ? 'aria-current="step"' : '' ?>>
                    <span class="num" aria-label="<?= $isSemesterRegistered ? 'complete' : 'in progress' ?>"><?= $isSemesterRegistered ? '<i class="fas fa-check" aria-hidden="true"></i>' : '1' ?></span>
                    <div>
                        <strong><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?> registration</strong>
                        <span><?= $isSemesterRegistered ? 'Complete' : 'Confirm your active term' ?></span>
                    </div>
                </div>
                <div class="reg-step <?= ($isSemesterRegistered && $hasCourseReg) ? 'is-done' : ($isSemesterRegistered ? 'is-current' : '') ?>" role="listitem" <?= ($isSemesterRegistered && !$hasCourseReg) ? 'aria-current="step"' : '' ?>>
                    <span class="num" aria-label="<?= ($isSemesterRegistered && $hasCourseReg) ? 'complete' : ($isSemesterRegistered ? 'in progress' : 'upcoming') ?>"><?= ($isSemesterRegistered && $hasCourseReg) ? '<i class="fas fa-check" aria-hidden="true"></i>' : '2' ?></span>
                    <div>
                        <strong>Course enrolment</strong>
                        <span><?= $hasCourseReg ? 'Auto-enrolled' : ($isSemesterRegistered ? 'Enrolling…' : 'Included with registration') ?></span>
                    </div>
                </div>
                <div class="reg-step <?= $hasCourseReg ? 'is-done' : '' ?>" role="listitem">
                    <span class="num" aria-label="<?= $hasCourseReg ? 'complete' : 'upcoming' ?>"><?= $hasCourseReg ? '<i class="fas fa-check" aria-hidden="true"></i>' : '3' ?></span>
                    <div>
                        <strong>Classes</strong>
                        <span><?= $hasCourseReg ? 'Timetable available' : 'After courses approved' ?></span>
                    </div>
                </div>
            </div>

            <section class="reg-card">
                <?php if (!$isSemesterRegistered): ?>
                    <h2>Register for this <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="lead">Confirms your active academic period and auto-enrols your programme courses. Fee clearance is required before registration.</p>

                    <?php if ($feeEligibility): ?>
                    <div class="alert alert-<?= $feeEligible ? 'success' : 'warning' ?> mb-3">
                        <div><strong>Total Fee:</strong> ZMW <?= htmlspecialchars(number_format((float)$feeEligibility['total_fee'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                        <div><strong>Amount Paid:</strong> ZMW <?= htmlspecialchars(number_format((float)$feeEligibility['amount_paid'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                        <div><strong>Balance:</strong> ZMW <?= htmlspecialchars(number_format((float)$feeEligibility['balance'], 2), ENT_QUOTES, 'UTF-8') ?></div>
                        <div><strong>Required Payment:</strong> <?= htmlspecialchars(number_format($requiredFeePct, 0), ENT_QUOTES, 'UTF-8') ?>%</div>
                        <div><strong>Current Payment:</strong> <?= htmlspecialchars(number_format((float)($paymentPercent ?? 0), 1), ENT_QUOTES, 'UTF-8') ?>%</div>
                        <div class="mt-1"><strong>Status:</strong> <?= $feeEligible ? 'Eligible for registration' : htmlspecialchars($feeEligibility['reason'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!$feeEligible): ?>
                        <div class="mt-2"><a href="fees.php" class="alert-link">View fees &amp; pay</a></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($isLateRegistration && $requiresSystemsOffice): ?>
                        <div class="alert alert-warning mb-3">
                            <strong>Needs Systems Office approval.</strong>
                            Request an intake, <?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?>, or year update before continuing.
                        </div>
                    <?php elseif ($isLateRegistration): ?>
                        <div class="alert alert-warning mb-3">
                            Late registration &mdash; returning students are submitted as <strong>Repeat</strong>.
                        </div>
                    <?php elseif (!$windowState['is_open']): ?>
                        <div class="alert alert-warning mb-3">
                            <?= htmlspecialchars($windowState['notice'] !== '' ? $windowState['notice'] : 'Registration is currently unavailable.', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" class="registration-submit-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="register_period">
                        <button type="submit" class="reg-btn" <?= ($requiresSystemsOffice || (!$windowState['is_open'] && !$isLateRegistration) || !$feeEligible) ? 'disabled' : '' ?>>
                            <i class="fas fa-check"></i> Register Now
                        </button>
                    </form>
                <?php elseif (!$hasCourseReg): ?>
                    <div class="alert alert-success mb-3 d-flex align-items-start gap-2" role="status">
                        <i class="fas fa-check-circle mt-1" aria-hidden="true"></i>
                        <div>
                            <strong>Registered for <?= htmlspecialchars($periodLabelFull, ENT_QUOTES, 'UTF-8') ?>.</strong>
                            Course enrolment is being processed — refresh if courses do not appear shortly.
                        </div>
                    </div>
                <?php else: ?>
                    <h2>Registered for <?= htmlspecialchars($periodLabelFull, ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="lead"><?= (int)count($registrationCheck['registered_courses'] ?? []) ?> course(s) auto-enrolled for this period.</p>
                    <?php if (!empty($registrationCheck['registered_courses'])): ?>
                    <ul class="list-group mb-3">
                        <?php foreach ($registrationCheck['registered_courses'] as $rc): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= htmlspecialchars((string)($rc['course_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="text-muted"><?= htmlspecialchars((string)($rc['course_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="myCourses.php" class="reg-btn reg-btn-outline"><i class="fas fa-book-open"></i> My Courses</a>
                        <a href="timetable.php" class="reg-btn"><i class="fas fa-calendar-alt"></i> Timetable</a>
                        <?php
                        require_once __DIR__ . '/includes/student_document_notifications.php';
                        foreach (student_document_notification_items($mysqli, (string)$studentId) as $docNotice):
                            $docHref = htmlspecialchars((string)($docNotice['href'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $docIcon = htmlspecialchars((string)($docNotice['icon'] ?? 'fa-file'), ENT_QUOTES, 'UTF-8');
                            $docLabel = htmlspecialchars((string)($docNotice['label'] ?? 'Open'), ENT_QUOTES, 'UTF-8');
                        ?>
                        <a href="<?= $docHref ?>" class="reg-btn reg-btn-outline"><i class="fas <?= $docIcon ?>"></i> <?= $docLabel ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($feeEligibility): ?>
                    <div class="reg-footnote">
                        <div class="payment-progress <?= htmlspecialchars($paymentBarClass, ENT_QUOTES, 'UTF-8') ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= htmlspecialchars((string)round($paymentPercent, 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Fee payment progress">
                            <div class="bar" style="width: <?= htmlspecialchars((string)$paymentBarWidth, ENT_QUOTES, 'UTF-8') ?>%;"></div>
                            <span class="threshold" aria-hidden="true"></span>
                        </div>
                        <strong><?= htmlspecialchars(number_format($paymentPercent ?? 0, 1), ENT_QUOTES, 'UTF-8') ?>% paid</strong>
                        &middot; <?= htmlspecialchars(number_format($requiredFeePct, 0), ENT_QUOTES, 'UTF-8') ?>% required
                        &middot; <?= $feeEligible ? 'Eligible' : 'Not eligible' ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="reg-card" aria-label="Registration details">
                <dl class="reg-details">
                    <div>
                        <dt>Programme</dt>
                        <dd title="<?= htmlspecialchars($programName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($programDisplay, ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <div>
                        <dt>Year</dt>
                        <dd>Year <?= (int)($studentDetails['year_of_study'] ?? 1) ?></dd>
                    </div>
                    <div>
                        <dt>Student Type</dt>
                        <dd><?= htmlspecialchars(ucfirst($studentType), ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <div>
                        <dt>Registration Type</dt>
                        <dd><?= htmlspecialchars($registrationTypeLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                </dl>
            </section>

            <?php if ($registrationHistory): ?>
                <section class="reg-card" aria-label="Registration history">
                    <h2>Registration History</h2>
                    <p class="lead">Previous academic registrations on your student record.</p>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle history-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Academic Year</th>
                                    <th>Period</th>
                                    <th>Student Type</th>
                                    <th>Date Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registrationHistory as $history): ?>
                                    <?php
                                        $historyType = trim((string)($history['student_type'] ?? ''));
                                        $historyDate = !empty($history['date_registered'])
                                            ? date('M d, Y', strtotime((string)$history['date_registered']))
                                            : 'N/A';
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($history['academic_year'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars(ucfirst((string)($history['period_type'] ?? 'semester')) . ' ' . (string)($history['semester'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($historyType !== '' ? ucfirst(strtolower($historyType)) : 'Regular', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($historyDate, ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.querySelector('.registration-submit-form');
            const btn = form ? form.querySelector('button[type="submit"]') : null;
            if (form && btn) {
                form.addEventListener('submit', function () {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    btn.disabled = true;
                });
            }
        });
    </script>
</body>
</html>
