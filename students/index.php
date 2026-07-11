<?php
require_once __DIR__ . '/../includes/portal_config.php';

// Keep the explicit development impersonation hook ahead of authentication.
if (defined('APP_ENV') && APP_ENV === 'development' && isset($_GET['dev'], $_GET['dev_as']) && $_GET['dev'] == '1') {
    require_once __DIR__ . '/../includes/session_guard.php';
    wuc_guard_start_session('student-dashboard-dev');
    $_SESSION['Sid'] = (string) $_GET['dev_as'];
}

require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/../includes/elearning_access.php';
require_once __DIR__ . '/../includes/short_course_student.php';
require_once __DIR__ . '/../includes/academic_risk_engine.php';
require_once __DIR__ . '/includes/period_mode_helper.php';
require_once __DIR__ . '/includes/student_fee_records.php';
require_once __DIR__ . '/includes/StudentAcademicWorkflowService.php';
require_once __DIR__ . '/includes/student_document_notifications.php';
require_once __DIR__ . '/../includes/portal_alerts.php';

if ($db->connect_error) {
    error_log("Database connection failed: " . $db->connect_error);
    die("System error. Please try again later.");
}

if (!preg_match('/^[A-Za-z0-9\/\-_]+$/', $_SESSION['Sid'])) {
    error_log("Invalid student ID format: " . $_SESSION['Sid']);
    session_destroy();
    header('Location: ../student_login.php');
    exit();
}

$student_id = $_SESSION['Sid'];
$is_dev_mode = defined('APP_ENV') && APP_ENV === 'development';

$studentRec = null;
$isShortCourseStudent = false;

$query_1 = "SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.profile_image, sp.startYear, sp.endYear,
                   sp.program_code, p.program_name, p.program_duration, p.period_mode,
                   p.academic_structure, p.duration_value, p.duration_unit, p.uses_terms, p.uses_semesters, p.is_short_course, p.is_transport_exception, p.examination_type
            FROM students s
            INNER JOIN student_program sp ON s.SID = sp.Sid
            INNER JOIN programs p ON sp.program_code = p.program_code
            LEFT JOIN program_courses pc ON pc.program_code = sp.program_code
            WHERE s.SID = ?
              AND (sp.status IS NULL OR sp.status = '' OR LOWER(sp.status) = 'active')
              AND COALESCE(p.is_active, 1) = 1
            GROUP BY s.SID, s.Fname, s.Lname, s.email, s.mobile, s.profile_image, sp.id, sp.startYear, sp.endYear,
                     sp.program_code, p.program_name, p.program_duration, p.period_mode, p.study_mode,
                     p.academic_structure, p.duration_value, p.duration_unit, p.uses_terms, p.uses_semesters, p.is_short_course, p.is_transport_exception, p.examination_type
            ORDER BY COUNT(pc.course_code) DESC, sp.id DESC
            LIMIT 1";

$stmt_1 = $db->prepare($query_1);
if ($stmt_1) {
    $stmt_1->bind_param("s", $student_id);
    $stmt_1->execute();
    $results_1 = $stmt_1->get_result();
    $studentRec = $results_1 ? $results_1->fetch_object() : null;
    $stmt_1->close();
    if ($studentRec) {
        $studentRec->period_mode = getStudentProgramPeriodMode($db, (string)$student_id);
    }
}

// Short-course-only students have no student_program row. Admit them using their
// short course as the displayed "program" instead of blocking at the gate.
if (!$studentRec) {
    $scEnrolments = sc_student_enrolments($db, (string)$student_id);

    if ($scEnrolments) {
        $stmt_sc = $db->prepare(
            "SELECT SID, Fname, Lname, email, mobile, profile_image
             FROM students WHERE SID = ? LIMIT 1"
        );
        if ($stmt_sc) {
            $stmt_sc->bind_param("s", $student_id);
            $stmt_sc->execute();
            $res_sc = $stmt_sc->get_result();
            $studentRec = $res_sc ? $res_sc->fetch_object() : null;
            $stmt_sc->close();
        }
    }

    if ($studentRec) {
        $isShortCourseStudent = true;
        $courseLabels = array_map(static function (array $e): string {
            return trim(($e['course_code'] ?? '') . ' - ' . ($e['course_name'] ?? ''), ' -');
        }, $scEnrolments);
        $latestEnrolment = $scEnrolments[0];

        $studentRec->program_code     = '';
        $studentRec->program_name     = $courseLabels
            ? implode(', ', $courseLabels)
            : 'Short Course Programme';
        $studentRec->program_duration = null;
        $studentRec->short_course_duration = sc_format_duration(
            $latestEnrolment['duration_value'] ?? 0,
            $latestEnrolment['duration_unit'] ?? ''
        ) ?: null;
        $studentRec->period_mode      = 'short_course';
        $studentRec->startYear        = null;
        $studentRec->endYear          = null;
    }
}

if (!$studentRec) {
    $error_title = "Student Account or Program Not Found";
    $error_message = "Your student ID <strong>" . htmlspecialchars($student_id) . "</strong> was not found, and has no academic program or short course assigned.";
    include __DIR__ . '/includes/error_template.php';
    exit();
}

$stats = [
    'balance'     => 0.0,
    'status'      => 'Not Registered',
    'reg_details' => null,
    'courses'     => 0
];

$programCode = $studentRec->program_code ?? '';
$totalFees = 0;
$totalPaid = 0;
$feesAvailable = false;
$latestRegistrationForFees = null;
$currentFeeSummary = student_fee_current_program_summary($db, (string)$student_id);

if (!empty($currentFeeSummary['registration'])) {
    $stats['status']      = 'Registered';
    $stats['reg_details'] = $currentFeeSummary['registration'];
    $latestRegistrationForFees = $currentFeeSummary['registration'];
}

if ($programCode && $latestRegistrationForFees) {
    // Keep dashboard and fees.php aligned: due comes from the configured
    // fee_structure period; paid comes from both payments ledgers.
    $totalFees = (float)$currentFeeSummary['total_due'];
    $totalPaid = (float)$currentFeeSummary['total_paid'];
    $feesAvailable = !empty($currentFeeSummary['has_fees']);
    // The helper bills only the latest registered period and merges both payment ledgers.
} elseif (!empty($isShortCourseStudent)) {
    // Short-course students owe their course fee(s), not program/semester fees.
    $scFees = sc_student_fee_summary($db, (string)$student_id);
    $totalFees = (float)$scFees['total_due'];
    $feesAvailable = $scFees['has_fees'];
}

$outstanding        = $feesAvailable ? max(0, $totalFees - $totalPaid) : null;
$stats['balance']   = $outstanding ?? 0.0;

// Override with new Fees System account totals if present
$newAccStmt = $db->prepare("SELECT balance, total_payable, amount_paid FROM student_fee_accounts WHERE student_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
if ($newAccStmt) {
    $newAccStmt->bind_param('s', $student_id);
    $newAccStmt->execute();
    $newAccRes = $newAccStmt->get_result();
    if ($newAccRow = $newAccRes->fetch_assoc()) {
        $totalFees = (float)$newAccRow['total_payable'];
        $totalPaid = (float)$newAccRow['amount_paid'];
        $outstanding = (float)$newAccRow['balance'];
        $feesAvailable = true;
        $stats['balance'] = $outstanding;
    }
    $newAccStmt->close();
}

$Records = [];
$annTableRes = $db->query("SHOW TABLES LIKE 'announcement'");
if ($annTableRes && $annTableRes->num_rows > 0) {
    $stmt_announcements = $db->prepare("SELECT title, descript, created FROM announcement ORDER BY created DESC LIMIT 3");
    if ($stmt_announcements) {
        $stmt_announcements->execute();
        $announcementResult = $stmt_announcements->get_result();
        while ($row = $announcementResult->fetch_object()) {
            $Records[] = $row;
        }
        $stmt_announcements->close();
    }
}
if ($annTableRes) { $annTableRes->free(); }

function student_dashboard_table_exists(mysqli $db, string $table): bool {
    $safeTable = $db->real_escape_string($table);
    if ($result = $db->query("SHOW TABLES LIKE '{$safeTable}'")) {
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
    return false;
}

function student_dashboard_get_columns(mysqli $db, string $table): array {
    $columns = [];
    $safeTable = $db->real_escape_string($table);
    if ($result = $db->query("SHOW COLUMNS FROM `{$safeTable}`")) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = strtolower($row['Field']);
        }
        $result->free();
    }
    return $columns;
}

function student_dashboard_column_exists(mysqli $db, string $table, string $column): bool {
    $safeTable = $db->real_escape_string($table);
    $safeColumn = $db->real_escape_string($column);
    if ($result = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'")) {
        $exists = $result->num_rows > 0;
        $result->free();
        return $exists;
    }
    return false;
}

$registeredCourseCodes = getStudentEnrolledCourses($db, $student_id);
$registeredCourseCodes = array_values(array_unique(array_filter(array_map(static function ($code) {
    return trim((string)$code);
}, is_array($registeredCourseCodes) ? $registeredCourseCodes : []))));

$stats['courses'] = count($registeredCourseCodes);

// Short-course students aren't in course_registration; count their short-course
// enrolments so the dashboard "Courses" stat reflects what they're actually doing.
if (!empty($isShortCourseStudent) && $stats['courses'] === 0) {
    $stats['courses'] = count(sc_student_enrolments($db, (string)$student_id));
}

// â”€â”€ Today's classes (course_schedule for the student's registered courses) â”€â”€
$todayClasses = [];
if ($registeredCourseCodes && student_dashboard_table_exists($db, 'course_schedule')) {
    $cols = student_dashboard_get_columns($db, 'course_schedule');
    
    // Day column
    $dayCol = in_array('day_of_week', $cols, true) ? 'day_of_week' : (in_array('day', $cols, true) ? 'day' : '');
    
    // Status / Active column
    $activeCol = in_array('is_active', $cols, true) ? 'is_active' : (in_array('status', $cols, true) ? 'status' : '');
    
    if ($dayCol) {
        $todayName = date('l');
        $placeholders = implode(',', array_fill(0, count($registeredCourseCodes), '?'));
        
        // Build SELECT columns and JOINS dynamically
        $selectCols = ["cs.course_code", "c.course_name"];
        $joins = ["LEFT JOIN courses c ON c.course_code = cs.course_code"];
        $orderBy = "";

        if (in_array('schedule_type', $cols, true)) {
            $selectCols[] = "cs.schedule_type";
        } else {
            $selectCols[] = "'' AS schedule_type";
        }
        
        // Check for time slot and classrooms joins
        $hasTimeSlotId = in_array('time_slot_id', $cols, true) && student_dashboard_table_exists($db, 'time_slots');
        $hasClassroomId = in_array('classroom_id', $cols, true) && student_dashboard_table_exists($db, 'classrooms');
        
        // Title
        if (in_array('title', $cols, true)) {
            $selectCols[] = "cs.title";
        } elseif ($hasTimeSlotId) {
            $selectCols[] = "ts.slot_name AS title";
        } else {
            $selectCols[] = "'' AS title";
        }
        
        // Start Time
        if (in_array('start_time', $cols, true)) {
            $selectCols[] = "cs.start_time";
            $orderBy = "cs.start_time ASC";
        } elseif ($hasTimeSlotId) {
            $selectCols[] = "TIME_FORMAT(ts.start_time, '%H:%i') AS start_time";
            $orderBy = "ts.start_time ASC";
        } else {
            $selectCols[] = "'' AS start_time";
        }
        
        // End Time
        if (in_array('end_time', $cols, true)) {
            $selectCols[] = "cs.end_time";
        } elseif ($hasTimeSlotId) {
            $selectCols[] = "TIME_FORMAT(ts.end_time, '%H:%i') AS end_time";
        } else {
            $selectCols[] = "'' AS end_time";
        }
        
        // Room
        if (in_array('room', $cols, true)) {
            $selectCols[] = "cs.room";
        } elseif ($hasClassroomId) {
            $selectCols[] = "cl.room_code AS room";
        } else {
            $selectCols[] = "'' AS room";
        }
        
        // Apply joins
        if ($hasTimeSlotId) {
            $joins[] = "LEFT JOIN time_slots ts ON ts.id = cs.time_slot_id";
        }
        if ($hasClassroomId) {
            $joins[] = "LEFT JOIN classrooms cl ON cl.id = cs.classroom_id";
        }
        
        // Build WHERE clause
        $whereClauses = ["cs.course_code IN ($placeholders)"];
        $params = $registeredCourseCodes;
        $types = str_repeat('s', count($registeredCourseCodes));
        
        if ($dayCol === 'day_of_week' && in_array('day', $cols, true)) {
            $whereClauses[] = "(cs.day = ? OR cs.day_of_week = ?)";
            $params[] = $todayName;
            $params[] = $todayName;
            $types .= 'ss';
        } else {
            $whereClauses[] = "cs.{$dayCol} = ?";
            $params[] = $todayName;
            $types .= 's';
        }
        
        if ($activeCol === 'is_active') {
            $whereClauses[] = "cs.is_active = 1";
        } elseif ($activeCol === 'status') {
            $whereClauses[] = "cs.status = 'active'";
        }
        
        $sqlToday = "SELECT " . implode(', ', $selectCols) . " 
                     FROM course_schedule cs " . implode(' ', $joins) . "
                     WHERE " . implode(' AND ', $whereClauses) . ($orderBy ? " ORDER BY {$orderBy}" : "") . "
                     LIMIT 6";
                     
        if ($stmt_today = $db->prepare($sqlToday)) {
            $stmt_today->bind_param($types, ...$params);
            if ($stmt_today->execute()) {
                $resToday = $stmt_today->get_result();
                while ($row = $resToday->fetch_assoc()) {
                    $todayClasses[] = $row;
                }
            }
            $stmt_today->close();
        }
    }
}

// â”€â”€ Fees progress (paid share of billed total for the registered term) â”€â”€
$feePercentPaid = null;
if ($feesAvailable && $totalFees > 0) {
    $feePercentPaid = max(0, min(100, ($totalPaid / $totalFees) * 100));
}

$studentPeriodStructure = normalizeProgramPeriodMode($studentRec->period_mode ?? 'semester');
$periodLabel      = wuc_period_label_from_structure($studentPeriodStructure);
$periodLabelShort = wuc_period_short_label_from_structure($studentPeriodStructure);
$periodLabelLower = strtolower($periodLabel);

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$studentName = $studentRec->Fname ?? 'Student';
$profileImage = $studentRec->profile_image ?? '';
$safeProfileImage = preg_match('/^[A-Za-z0-9._-]+$/', $profileImage) ? $profileImage : '';
$imagePath = '../uploads/profile/' . $safeProfileImage;
$hasImage = !empty($safeProfileImage) && $safeProfileImage !== 'default.jpg';
$initials = strtoupper(substr((string)($studentRec->Fname ?? 'S'), 0, 1) . substr((string)($studentRec->Lname ?? 'T'), 0, 1));
$balanceClass = !$feesAvailable ? 'neutral' : (($outstanding ?? 0.0) > 0 ? 'red' : 'green');
$balanceDisplay = $feesAvailable ? 'ZMW ' . number_format((float)$stats['balance'], 2) : '&mdash;';
$balanceTitle = $feesAvailable ? '' : 'Register for a semester to see your balance.';
$statusClass = $stats['status'] === 'Registered' ? 'green' : 'amber';
$announcementCount = count($Records);

$currentPeriodText = 'Not registered';
if ($stats['status'] === 'Registered' && !empty($stats['reg_details'])) {
    $rd = $stats['reg_details'];
    $currentPeriodText = 'Year ' . ($rd['year_of_study'] ?? '-')
        . ' / ' . $periodLabelShort . ' ' . ($rd['semester'] ?? '-')
        . ' / ' . ($rd['academic_year'] ?? '-');
}

// Short-course students sit outside semester/term registration. Reflect their
// actual enrolment state instead of a misleading "Not Registered", so the
// dashboard doesn't push them toward registration.php (which then tells them
// registration doesn't apply to short courses).
$isShortCourse = !empty($isShortCourseStudent) || ($studentRec->period_mode ?? '') === 'short_course';
if ($isShortCourse) {
    $stats['status']   = 'Short Course';
    $statusClass       = 'green';
    $currentPeriodText = 'Short course enrolment';
    if (!empty($studentRec->intake)) {
        $currentPeriodText = 'Intake: ' . $studentRec->intake;
    }
} else {
    $dashWorkflow = new StudentAcademicWorkflowService($db);
    $dashPeriod = $dashWorkflow->getActiveAcademicPeriod((string)$student_id);
    $dashRegistration = $dashPeriod['ok']
        ? $dashWorkflow->checkStudentRegistration((string)$student_id, $dashPeriod)
        : ['is_registered' => false, 'period_label' => ''];
    $dashFee = $dashPeriod['ok']
        ? $dashWorkflow->checkFeeEligibility((string)$student_id, $dashPeriod)
        : null;

    if ($dashRegistration['is_registered']) {
        $stats['status'] = 'Registered';
        $currentPeriodText = $dashRegistration['period_label'] !== ''
            ? 'Registered for ' . $dashRegistration['period_label']
            : $currentPeriodText;
        $statusClass = 'green';
    }
    if ($dashFee) {
        $feePercentPaid = (float)$dashFee['payment_percentage'];
        if ($dashFee['total_fee'] > 0) {
            $totalFees = (float)$dashFee['total_fee'];
            $totalPaid = (float)$dashFee['amount_paid'];
            $outstanding = max(0.0, (float)$dashFee['balance']);
            $stats['balance'] = $outstanding;
            $feesAvailable = true;
            $balanceClass = $outstanding > 0 ? 'red' : 'green';
            $balanceDisplay = 'ZMW ' . number_format($outstanding, 2);
        }
    }
}

$aiAcademicInsight = null;
try {
    $aiAcademicInsight = wuc_academic_risk_analyze_student($db, (string)$student_id, true);
} catch (Throwable $e) {
    error_log('students/index.php AI academic insight failed: ' . $e->getMessage());
}

$actionItems = [];
if (!$isShortCourse) {
    foreach (student_document_notification_items($db, (string)$student_id) as $docNotice) {
        $actionItems[] = $docNotice;
    }
}
if ($aiAcademicInsight && in_array($aiAcademicInsight['risk_level'], ['Medium', 'High'], true)) {
    $reasonKeys = is_array($aiAcademicInsight['reason_keys'] ?? null) ? $aiAcademicInsight['reason_keys'] : [];
    $studentGuidance = (string)($aiAcademicInsight['student_guidance'] ?? wuc_risk_student_guidance($reasonKeys, (string)$aiAcademicInsight['risk_level']));
    $actionItems[] = [
        'type' => $aiAcademicInsight['risk_level'] === 'High' ? 'danger' : 'warning',
        'icon' => 'fa-brain',
        'title' => 'AI academic insight',
        'text' => $aiAcademicInsight['risk_level'] . ' risk score: ' . $aiAcademicInsight['risk_score'] . '. ' . $studentGuidance,
        'href' => 'index.php',
        'label' => 'Review insight',
    ];
}
if ($stats['status'] !== 'Registered' && !$isShortCourse) {
    $actionItems[] = [
        'type' => 'warning',
        'icon' => 'fa-user-check',
        'title' => 'Complete your registration',
        'text' => 'Register for the current ' . $periodLabelLower . ' to unlock courses and services.',
        'href' => 'registration.php',
        'label' => 'Register now',
    ];
}
if ($feesAvailable && ($outstanding ?? 0.0) > 0) {
    $actionItems[] = [
        'type' => 'danger',
        'icon' => 'fa-credit-card',
        'title' => 'Outstanding balance',
        'text' => 'Your account has an outstanding balance of ZMW ' . number_format((float)$outstanding, 2) . '.',
        'href' => 'fees.php',
        'label' => 'View fees',
    ];
}
if ($stats['courses'] === 0 && !$isShortCourse) {
    $actionItems[] = [
        'type' => 'info',
        'icon' => 'fa-book',
        'title' => 'No courses registered',
        'text' => 'Complete period registration to auto-enrol your programme courses.',
        'href' => 'registration.php',
        'label' => 'Register',
    ];
}
if ($isShortCourse) {
    $actionItems[] = [
        'type' => 'info',
        'icon' => 'fa-certificate',
        'title' => 'Short course enrolment',
        'text' => 'Short courses are managed by the admissions office. View your enrolment status and dates.',
        'href' => 'registration.php',
        'label' => 'View enrolment',
    ];
}
if (empty($actionItems)) {
    $actionItems[] = [
        'type' => 'success',
        'icon' => 'fa-circle-check',
        'title' => 'Profile looks current',
        'text' => 'Your registration, courses, and payment status are available from this dashboard.',
        'href' => 'myCourses.php',
        'label' => 'View courses',
    ];
}

// Materials and the eLearning Hub live behind the eLearning portal grant
// (guard.php redirects ungrated students to elearning_login.php with an
// "access not assigned" error). Mirror the navbar's check so the dashboard
// only advertises links the student can actually open.
$hasElearningAccess = false;
if (function_exists('wuc_user_has_portal_access')) {
    $dashUserIdDb = (int)($_SESSION['user_id_db'] ?? 0);
    $hasElearningAccess = $dashUserIdDb > 0 && wuc_user_has_portal_access($db, $dashUserIdDb, 'elearning');
}

$attentionItems = array_values(array_filter($actionItems, static function (array $item): bool {
    return ($item['type'] ?? '') !== 'success';
}));
if (!function_exists('student_dashboard_safe_notification_url')) {
    function student_dashboard_safe_notification_url(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '' || preg_match('/^\s*javascript:/i', $url)) {
            return '/wucportal/notifications.php';
        }
        if (preg_match('/^https?:\/\//i', $url) || $url[0] === '/') {
            return $url;
        }
        return '/wucportal/' . ltrim($url, '/');
    }
}
if (!function_exists('student_dashboard_alert_slug')) {
    function student_dashboard_alert_slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?: 'notice';
        $slug = trim($slug, '_');
        return substr($slug !== '' ? $slug : 'notice', 0, 48);
    }
}
if (!function_exists('student_dashboard_persist_attention_alerts')) {
    /**
     * @param array<int,array<string,mixed>> $items
     */
    function student_dashboard_persist_attention_alerts(mysqli $db, string $studentId, array $items): void
    {
        foreach ($items as $item) {
            $itemType = (string)($item['type'] ?? 'info');
            if ($itemType === 'success') {
                continue;
            }

            $title = trim((string)($item['title'] ?? 'Portal notification'));
            $message = trim((string)($item['text'] ?? ''));
            if ($title === '' || $message === '') {
                continue;
            }

            $slug = student_dashboard_alert_slug($title);
            $severity = 'info';
            if ($itemType === 'danger') {
                $severity = 'critical';
            } elseif ($itemType === 'warning') {
                $severity = 'warning';
            }

            wuc_portal_alert_upsert_current($db, [
                'user_id' => $studentId,
                'user_role' => 'student',
                'alert_type' => 'student_dashboard_' . $slug,
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'entity_type' => 'student_dashboard',
                'entity_id' => $slug,
                'action_url' => student_dashboard_safe_notification_url($item['href'] ?? null),
            ]);
        }
    }
}
student_dashboard_persist_attention_alerts($db, (string)$student_id, $attentionItems);
require_once __DIR__ . '/../includes/notification_integrations.php';
wuc_portal_alerts_sync_sources($db, (string)$student_id, 'student');
wuc_portal_alerts_sync_student_documents($db, (string)$student_id);
$portalUnreadAlerts = wuc_portal_alerts_for_user($db, (string)$student_id, 5, true, 'student');
$portalUnreadCount = wuc_portal_alerts_unread_count($db, (string)$student_id, 'student');
$portalNotificationItems = array_map(static function (array $alert): array {
    $severity = (string)($alert['severity'] ?? 'info');
    return [
        'type' => $severity === 'critical' ? 'danger' : ($severity === 'warning' ? 'warning' : 'info'),
        'icon' => $severity === 'critical' ? 'fa-triangle-exclamation' : ($severity === 'warning' ? 'fa-circle-exclamation' : 'fa-circle-info'),
        'title' => (string)($alert['title'] ?? 'Notification'),
        'text' => (string)($alert['message'] ?? ''),
        'href' => student_dashboard_safe_notification_url($alert['action_url'] ?? null),
        'label' => 'Open notification',
    ];
}, $portalUnreadAlerts);
$attentionCount = $portalUnreadCount > 0 ? $portalUnreadCount : count($attentionItems);
$notificationItems = $portalNotificationItems ?: ($attentionItems ?: $actionItems);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - ITC</title>
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/assets/css/main.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="css/dashboard.css?v=20260702-profile-square-v2">
</head>
<body class="bg-light student-dashboard-page">

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="dash-content content-wrapper portal-dashboard pt-3">

    <section class="welcome-hero hero-branded" aria-label="Welcome summary">
        <div class="welcome-hero-text">
            <span class="eyebrow">Student Dashboard</span>
            <h1><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($studentName) ?></h1>
            <p><?= htmlspecialchars($studentRec->program_name ?? 'Academic profile') ?></p>
            <div class="hero-chips" aria-label="Academic context">
                <span class="hero-chip"><i class="fas fa-id-card"></i> <?= htmlspecialchars($studentRec->SID ?? $student_id) ?></span>
                <?php if (!empty($stats['reg_details'])): ?>
                    <span class="hero-chip"><i class="fas fa-layer-group"></i> Year <?= htmlspecialchars((string)($stats['reg_details']['year_of_study'] ?? '-')) ?>, <?= htmlspecialchars($periodLabelShort) ?> <?= htmlspecialchars((string)($stats['reg_details']['semester'] ?? '-')) ?></span>
                    <span class="hero-chip"><i class="far fa-calendar"></i> <?= htmlspecialchars((string)($stats['reg_details']['academic_year'] ?? date('Y'))) ?></span>
                <?php elseif ($isShortCourse): ?>
                    <span class="hero-chip"><i class="fas fa-certificate"></i> Short course enrolment</span>
                <?php endif; ?>
                <span class="hero-chip"><i class="far fa-clock"></i> <?= htmlspecialchars(date('D, d M Y')) ?></span>
            </div>
        </div>
        <div class="welcome-hero-meta">
            <div class="dash-notification-wrap">
                <button type="button"
                        class="dash-notification-bell"
                        id="dashboardAttentionBell"
                        aria-label="Open needs attention notifications"
                        aria-expanded="false"
                        aria-controls="dashboardAttentionMenu">
                    <i class="fas fa-bell"></i>
                    <?php if ($attentionCount > 0): ?>
                        <span class="dash-notification-count"><?= htmlspecialchars((string)$attentionCount) ?></span>
                    <?php endif; ?>
                </button>
                <div class="dash-notification-menu" id="dashboardAttentionMenu" role="menu" aria-labelledby="dashboardAttentionBell">
                    <div class="dash-notification-header">
                        <strong><?= $attentionCount > 0 ? 'Needs Attention' : 'All Clear' ?></strong>
                        <span><?= htmlspecialchars((string)count($notificationItems)) ?> item<?= count($notificationItems) === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="dash-notification-list">
                        <?php foreach ($notificationItems as $item): ?>
                            <a class="dash-notification-item <?= htmlspecialchars($item['type']) ?>" role="menuitem" href="<?= htmlspecialchars($item['href']) ?>">
                                <span class="dash-notification-icon"><i class="fas <?= htmlspecialchars($item['icon']) ?>"></i></span>
                                <span class="dash-notification-copy">
                                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                                    <small><?= htmlspecialchars($item['text']) ?></small>
                                    <em><?= htmlspecialchars($item['label']) ?> <i class="fas fa-arrow-right"></i></em>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="p-2 border-top bg-light">
                        <a class="btn btn-sm btn-primary w-100" href="/wucportal/notifications.php">
                            <i class="fas fa-inbox me-1"></i> Open Notifications Center
                        </a>
                    </div>
                </div>
            </div>
            <span class="hero-badge <?= htmlspecialchars($statusClass) ?>">
                <i class="fas fa-calendar-check"></i>
                <?= htmlspecialchars($currentPeriodText) ?>
            </span>
        </div>
    </section>

    <!-- Quick Navigation -->
    <div class="dash-quicknav-wrap">
        <nav class="dash-quicknav" aria-label="Quick navigation">
            <a href="myCourses.php" class="quicknav-item"><i class="fas fa-book-open"></i><span>My Courses</span></a>
            <a href="registration.php" class="quicknav-item"><i class="fas fa-user-check"></i><span>Registration</span></a>
            <a href="fees.php" class="quicknav-item"><i class="fas fa-file-invoice-dollar"></i><span>Fees</span></a>
            <a href="/wucportal/notifications.php" class="quicknav-item"><i class="fas fa-bell"></i><span>Notifications</span></a>
            <a href="continuousAssessment.php" class="quicknav-item"><i class="fas fa-chart-bar"></i><span>Results</span></a>
            <a href="timetable.php" class="quicknav-item"><i class="fas fa-calendar-week"></i><span>Timetable</span></a>
            <?php if ($hasElearningAccess): ?>
            <a href="materials.php" class="quicknav-item"><i class="fas fa-folder-open"></i><span>Materials</span></a>
            <a href="elearning/index.php" class="quicknav-item"><i class="fas fa-graduation-cap"></i><span>eLearning Hub</span></a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Student Stats -->
    <section class="stats-grid" aria-label="Student statistics">
        <a href="fees.php" class="stat-card">
            <div class="stat-icon <?= htmlspecialchars($balanceClass) ?>"><i class="fas fa-money-bill-wave"></i></div>
            <div>
                <div class="stat-label">Outstanding Balance</div>
                <div class="stat-value <?= htmlspecialchars($balanceClass) ?>" title="<?= htmlspecialchars($balanceTitle) ?>"><?= $balanceDisplay ?></div>
                <div class="stat-sub"><?= $feesAvailable ? 'of ZMW ' . htmlspecialchars(number_format($totalFees, 2)) . ' billed' : 'No fee structure yet' ?></div>
            </div>
        </a>
        <a href="registration.php" class="stat-card">
            <div class="stat-icon <?= htmlspecialchars($statusClass) ?>"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="stat-label">Registration Status</div>
                <div class="stat-value"><?= htmlspecialchars($stats['status']) ?></div>
                <div class="stat-sub"><?= htmlspecialchars($currentPeriodText) ?></div>
            </div>
        </a>
        <a href="myCourses.php" class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-book-open"></i></div>
            <div>
                <div class="stat-label">Enrolled Courses</div>
                <div class="stat-value"><?= htmlspecialchars((string)$stats['courses']) ?></div>
                <div class="stat-sub"><?= !empty($isShortCourseStudent) ? 'short course enrolment' : 'registered this ' . htmlspecialchars($periodLabelLower) ?></div>
            </div>
        </a>
    </section>

    <!-- Main dashboard: profile, schedule, deadlines, and announcements -->
    <section class="dash-grid" aria-label="Student dashboard layout">

        <!-- LEFT: Profile -->
        <section aria-label="Student profile">

            <!-- Profile Card -->
            <?php if ($studentRec):
                $r = $studentRec;
                $periodMode = $r->period_mode ?? 'semester';

                if ($isShortCourse) {
                    $typeClass = 'short-course';
                    $typeLabel = 'Short Course';
                } else {
                    $typeClass = in_array($periodMode, ['term', 'semester'], true) ? $periodMode : 'neutral';
                    $typeLabel = getPeriodTypeBadgeText($db, (string)$student_id);
                }

                $durationLabel = null;
                if ($isShortCourse) {
                    if (!empty($r->short_course_duration)) {
                        $durationLabel = $r->short_course_duration;
                    } elseif (!empty($r->duration_value) && !empty($r->duration_unit)) {
                        $durationLabel = $r->duration_value . ' ' . $r->duration_unit;
                    }
                } elseif (!empty($r->program_duration)) {
                    $durationYears = (float)$r->program_duration;
                    $durationLabel = (floor($durationYears) == $durationYears ? (string)(int)$durationYears : (string)$durationYears)
                        . ' Year' . ($durationYears == 1.0 ? '' : 's');
                }
            ?>
            <article class="card">
                <div class="profile-header">
                    <div class="profile-avatar-wrap <?= $hasImage ? '' : 'no-image' ?>">
                        <?php if ($hasImage): ?>
                            <img class="profile-avatar"
                                 src="<?= htmlspecialchars($imagePath) ?>"
                                 alt="Profile photo"
                                 onerror="this.closest('.profile-avatar-wrap').classList.add('no-image');">
                        <?php endif; ?>
                        <div class="profile-avatar-fallback"><?= htmlspecialchars($initials) ?></div>
                    </div>
                    <div class="profile-identity">
                        <div class="profile-name"><?= htmlspecialchars($r->Fname . ' ' . $r->Lname) ?></div>
                        <div class="profile-id">
                            <?= htmlspecialchars($r->SID) ?>
                            <span class="badge-period <?= htmlspecialchars($typeClass) ?>"><?= htmlspecialchars($typeLabel) ?></span>
                        </div>
                    </div>
                </div>
                <div class="profile-details">
                    <div class="profile-row">
                        <span class="profile-row-label">Program</span>
                        <span class="profile-row-value"><?= htmlspecialchars($r->program_name ?? '-') ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-row-label">Mobile</span>
                        <span class="profile-row-value"><?= htmlspecialchars($r->mobile ?? '-') ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-row-label">Email</span>
                        <span class="profile-row-value"><?= htmlspecialchars($r->email ?? '-') ?></span>
                    </div>
                    <div class="profile-row">
                        <span class="profile-row-label">Duration</span>
                        <span class="profile-row-value"><?= $durationLabel !== null ? htmlspecialchars($durationLabel) : '&mdash;' ?></span>
                    </div>
                </div>
                <div class="profile-actions profile-actions--split">
                    <a href="editProfile.php?update=<?= urlencode($r->SID) ?>" class="btn-profile btn-profile-outline">
                        <i class="fas fa-camera"></i> Photo
                    </a>
                    <a href="editStudent.php?update=<?= urlencode($r->SID) ?>" class="btn-profile btn-profile-fill">
                        <i class="fas fa-edit"></i> Edit Profile
                    </a>
                    <button type="button" class="btn-profile btn-profile-outline btn-profile--digital-id" data-bs-toggle="modal" data-bs-target="#digitalIdModal">
                        <i class="fas fa-id-card"></i> Digital Student ID
                    </button>
                </div>
            </article>
            <?php else: ?>
            <article class="card card-body">
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <p>No student records found</p>
                </div>
            </article>
            <?php endif; ?>

            <!-- Fees Overview -->
            <article class="card card-collapsible">
                <div class="card-hdr">
                    <h3><i class="fas fa-file-invoice-dollar"></i> Fees Overview</h3>
                    <div class="card-hdr-actions">
                        <a href="fees.php" class="badge bg-primary text-decoration-none">Details</a>
                        <button class="card-toggle collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#dashboardFees"
                                aria-expanded="false"
                                aria-controls="dashboardFees">
                            <span class="card-toggle-label">Expand</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div id="dashboardFees" class="collapse">
                <div class="card-body">
                    <?php if ($feePercentPaid !== null): ?>
                        <div class="fee-progress-meta">
                            <span>Paid <strong>ZMW <?= htmlspecialchars(number_format($totalPaid, 2)) ?></strong></span>
                            <span><?= htmlspecialchars(number_format($feePercentPaid, 0)) ?>%</span>
                        </div>
                        <div class="fee-progress" role="progressbar"
                             aria-valuenow="<?= htmlspecialchars(number_format($feePercentPaid, 0)) ?>"
                             aria-valuemin="0" aria-valuemax="100"
                             aria-label="Share of billed fees paid">
                            <div class="fee-progress-bar <?= $feePercentPaid >= 50 ? 'good' : 'low' ?>" style="width: <?= htmlspecialchars(number_format($feePercentPaid, 0)) ?>%;"></div>
                        </div>
                        <div class="fee-progress-foot">
                            <span class="text-muted">Billed: ZMW <?= htmlspecialchars(number_format($totalFees, 2)) ?></span>
                            <span class="<?= ($outstanding ?? 0) > 0 ? 'fee-due' : 'fee-clear' ?>">
                                <?= ($outstanding ?? 0) > 0 ? 'Due: ZMW ' . htmlspecialchars(number_format((float)$outstanding, 2)) : 'Fully paid' ?>
                            </span>
                        </div>
                        <?php if ($feePercentPaid < 50 && empty($isShortCourseStudent)): ?>
                            <p class="fee-progress-hint"><i class="fas fa-circle-info"></i> Pay at least 50% of fees to stay eligible for CA marks and course registration.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-receipt"></i>
                            <p><?php
                                if ($feesAvailable) {
                                    echo 'No billed fees for your current term.';
                                } elseif (!empty($stats['reg_details'])) {
                                    echo 'No fee structure has been published for your current term yet.';
                                } else {
                                    echo 'Fees appear here once you register for a ' . htmlspecialchars($periodLabelLower) . '.';
                                }
                            ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
            </article>

            <?php if ($aiAcademicInsight): ?>
            <article class="card card-collapsible card-collapsible-nested">
                <div class="card-hdr">
                    <h3><i class="fas fa-brain"></i> AI Academic Insight</h3>
                    <div class="card-hdr-actions">
                        <span class="badge bg-<?= htmlspecialchars(wuc_academic_risk_level_class((string)($aiAcademicInsight['risk_level'] ?? 'Low'))) ?>"><?= htmlspecialchars((string)($aiAcademicInsight['risk_level'] ?? 'Low')) ?></span>
                        <button class="card-toggle collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#dashboardAiInsight"
                                aria-expanded="false"
                                aria-controls="dashboardAiInsight">
                            <span class="card-toggle-label">Expand</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div id="dashboardAiInsight" class="collapse">
                    <?= wuc_academic_risk_render_student_card($aiAcademicInsight) ?>
                </div>
            </article>
            <?php endif; ?>

            <?php
            // Rule-based course recommendations (Sprint 6): retakes + missing
            // curriculum courses. Compact mode renders nothing when clean.
            try {
                require_once dirname(__DIR__) . '/includes/course_recommendation_engine.php';
                $dashCourseRecs = wuc_course_recommendations($db, (string)$student_id);
                echo wuc_course_recommendations_render_card($dashCourseRecs, true);
            } catch (Throwable $e) {
                error_log('students/index.php course recommendations failed: ' . $e->getMessage());
            }

            // eLearning study checklist (Sprint 10): unread materials, incomplete
            // lessons, pending assignments and CA-linked revision topics.
            try {
                require_once dirname(__DIR__) . '/includes/elearning_insights_engine.php';
                $dashElInsights = wuc_el_student_insights($db, (string)$student_id);
                echo wuc_el_render_student_checklist($dashElInsights);
            } catch (Throwable $e) {
                error_log('students/index.php elearning insights failed: ' . $e->getMessage());
            }
            ?>

        </section><!-- /LEFT -->

        <!-- RIGHT: Campus schedule and announcements -->
        <section aria-label="Dashboard updates">

            <!-- Today's Classes -->
            <article class="card card-collapsible">
                <div class="card-hdr">
                    <h3><i class="fas fa-calendar-day"></i> Today's Classes</h3>
                    <div class="card-hdr-actions">
                        <a href="timetable.php" class="badge bg-primary text-decoration-none">Timetable</a>
                        <button class="card-toggle"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#dashboardTodayClasses"
                                aria-expanded="true"
                                aria-controls="dashboardTodayClasses">
                            <span class="card-toggle-label">Collapse</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div id="dashboardTodayClasses" class="collapse show">
                <div class="card-body card-body-scroll">
                    <?php if (!empty($todayClasses)): ?>
                        <ul class="today-class-list">
                            <?php foreach ($todayClasses as $cls): ?>
                                <?php
                                    $startTime = !empty($cls['start_time']) ? strtotime((string)$cls['start_time']) : false;
                                    $endTime = !empty($cls['end_time']) ? strtotime((string)$cls['end_time']) : false;
                                ?>
                                <li class="today-class-item">
                                    <span class="today-class-time">
                                        <strong><?= $startTime ? htmlspecialchars(date('H:i', $startTime)) : '&mdash;' ?></strong>
                                        <small><?= $endTime ? htmlspecialchars(date('H:i', $endTime)) : 'TBA' ?></small>
                                    </span>
                                    <span class="today-class-copy">
                                        <strong><?= htmlspecialchars($cls['course_code']) ?> &middot; <?= htmlspecialchars($cls['course_name'] ?? $cls['title'] ?? '') ?></strong>
                                        <small>
                                            <?php if (!empty($cls['room'])): ?><i class="fas fa-location-dot"></i> <?= htmlspecialchars($cls['room']) ?><?php endif; ?>
                                            <?php if (!empty($cls['schedule_type'])): ?><span class="today-class-type"><?= htmlspecialchars(ucfirst($cls['schedule_type'])) ?></span><?php endif; ?>
                                        </small>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-mug-hot"></i>
                            <p>No classes scheduled for today.</p>
                        </div>
                    <?php endif; ?>
                </div>
                </div>
            </article>

            <article class="card announcement-card card-collapsible">
                <div class="card-hdr">
                    <h3><i class="fas fa-bullhorn"></i> Announcements</h3>
                    <?php if ($announcementCount > 0): ?>
                    <div class="card-hdr-actions">
                        <span class="badge bg-primary"><?= htmlspecialchars((string)$announcementCount) ?> latest</span>
                        <button class="card-toggle collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#dashboardAnnouncements"
                                aria-expanded="false"
                                aria-controls="dashboardAnnouncements">
                            <span class="card-toggle-label">Expand</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($announcementCount > 0): ?>
                <div id="dashboardAnnouncements" class="collapse">
                    <div class="card-body announcement-collapse-body card-body-scroll">
                        <?php foreach ($Records as $ann): ?>
                            <article class="announcement-item">
                                <div class="announcement-title">
                                    <span class="announcement-badge"><i class="fas fa-info"></i></span>
                                    <?= htmlspecialchars($ann->title ?? 'Announcement') ?>
                                </div>
                                <span class="announcement-date">
                                    <?= !empty($ann->created) ? htmlspecialchars(date('M d, Y', strtotime($ann->created))) : '&mdash;' ?>
                                </span>
                                <div class="announcement-desc">
                                    <?= htmlspecialchars($ann->descript ?? '') ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="card-body">
                    <div class="empty-state">
                        <i class="fas fa-bell-slash"></i>
                        <p>No announcements at the moment.</p>
                    </div>
                </div>
                <?php endif; ?>
            </article>

            <!-- AI Tutor (campus assistant — eLearning tasks live on the eLearning dashboard) -->
            <article class="card ai-tutor-card card-collapsible">
                <div class="card-hdr card-hdr--on-dark">
                    <h3><i class="fas fa-brain"></i> AI Tutor</h3>
                    <button class="card-toggle collapsed card-toggle--light"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#dashboardAiTutor"
                            aria-expanded="false"
                            aria-controls="dashboardAiTutor">
                        <span class="card-toggle-label">Expand</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <div id="dashboardAiTutor" class="collapse">
                <div class="card-body ai-tutor-card-body">
                    <div class="ai-tutor-icon" aria-hidden="true">
                        <i class="fas fa-brain"></i>
                    </div>
                    <h4 class="ai-tutor-title">Study Sessions with AI Tutor</h4>
                    <p class="ai-tutor-copy">Get instant explanations, exam preparation, CA reviews, and syllabus guidance from your academic assistant.</p>
                    <button type="button" class="btn ai-tutor-btn" id="launchAiTutorBtn">
                        <i class="fas fa-comment"></i> Launch AI Tutor
                    </button>
                </div>
                </div>
            </article>

        </section><!-- /RIGHT -->

    </section><!-- /dash-grid -->

</main><!-- /dash-content -->

<!-- Digital Student ID Modal -->
<div class="modal fade digital-id-modal" id="digitalIdModal" tabindex="-1" aria-labelledby="digitalIdModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered digital-id-dialog">
    <div class="modal-content digital-id-content">
      <div class="modal-header border-0 digital-id-header">
        <h5 class="modal-title" id="digitalIdModalLabel"><i class="fas fa-shield-alt"></i> Official Digital Student ID</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body digital-id-body">
        <div class="student-id-card">
          <div class="id-header">
            <h6>Industrial Training Centre</h6>
            <small>DIGITAL CAMPUS SERVICES</small>
          </div>
          <div class="id-avatar-wrap">
            <?php if (!empty($hasImage)): ?>
                <img src="<?= htmlspecialchars($imagePath) ?>" alt="Photo">
            <?php else: ?>
                <div class="id-avatar-fallback"><?= htmlspecialchars($initials ?? 'ST') ?></div>
            <?php endif; ?>
          </div>
          <h5 class="id-student-name"><?= htmlspecialchars(($studentRec->Fname ?? '') . ' ' . ($studentRec->Lname ?? '')) ?></h5>
          <span class="badge id-status-badge"><i class="fas fa-check-circle"></i> Active Student</span>
          <div class="id-meta">
            <div class="id-meta-row">
              <span class="id-meta-label">Student ID:</span>
              <span class="id-meta-value"><?= htmlspecialchars($studentRec->SID ?? '') ?></span>
            </div>
            <div class="id-meta-row">
              <span class="id-meta-label">Program:</span>
              <span class="id-meta-value"><?= htmlspecialchars($studentRec->program_name ?? '-') ?></span>
            </div>
            <div class="id-meta-row">
              <span class="id-meta-label">Valid Thru:</span>
              <span class="id-meta-value"><?= !empty($studentRec->endYear) ? htmlspecialchars((string)$studentRec->endYear) : date('Y', strtotime('+3 years')) ?></span>
            </div>
          </div>
          <div class="id-qr">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=<?= urlencode('ITCSTUDENT:' . ($studentRec->SID ?? '')) ?>"
                 width="110" height="110" alt="Verification QR">
            <div class="id-qr-caption">SCAN FOR CAMPUS VERIFICATION</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/dashboard-ui.js?v=20260702"></script>
</body>
</html>
