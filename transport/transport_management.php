<?php
if ($_SERVER['REQUEST_METHOD'] === 'GET' && basename((string)($_SERVER['PHP_SELF'] ?? '')) === 'transport_management.php') {
    $legacyTabRoutes = [
        'trainee' => 'trainees.php',
        'session' => 'sessions.php',
        'check' => 'preuse_checks.php',
        'cohort' => 'cohorts.php',
        'fleet' => 'fleet.php',
        'instructor' => 'instructors.php',
        'client' => 'clients.php',
    ];
    $legacyTab = (string)($_GET['tab'] ?? '');
    if (isset($legacyTabRoutes[$legacyTab])) {
        $query = $_GET;
        unset($query['tab']);
        $target = '/wucportal/transport/' . $legacyTabRoutes[$legacyTab];
        if ($query) {
            $target .= '?' . http_build_query($query);
        }
        header('Location: ' . $target, true, 302);
        exit;
    }
}

require_once __DIR__ . "/includes/transport.php";
require_once __DIR__ . "/includes/transport_payment_guard.php";
require_once __DIR__ . "/includes/transport_eligibility.php"; // §7 admission eligibility
require_once __DIR__ . "/includes/transport_fees.php";        // §8 fee calculation
require_once __DIR__ . "/includes/transport_invoicing.php";   // §4.5/§10 invoices & receipts (BR011)

global $db;
if (!isset($db) || !($db instanceof mysqli)) {
    throw new RuntimeException('Transport Management requires an active database connection.');
}

if (empty($_SESSION['transport_csrf'])) {
    $_SESSION['transport_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['transport_csrf'];
$message = '';
$messageType = '';
$transportPages = [
    'overview' => [
        'route' => 'transport_management.php',
        'title' => 'Transport Operations',
        'subtitle' => 'Live training, fleet, instructor, compliance, and corporate client overview.',
        'icon' => 'fas fa-gauge-high',
        'card_title' => 'Transport Operations Snapshot',
    ],
    'trainee' => [
        'route' => 'trainees.php',
        'title' => 'Trainee Enrolment',
        'subtitle' => 'Enroll learners into active transport cohorts and track payment or medical clearance status.',
        'icon' => 'fas fa-user-plus',
        'card_title' => 'Enroll Trainee',
    ],
    'payments' => [
        'route' => 'payments.php',
        'title' => 'Payments & Booking',
        'subtitle' => 'Verify trainee payments and book paid learners into cohorts. Booking is blocked until payment is verified (BR001).',
        'icon' => 'fas fa-money-check-dollar',
        'card_title' => 'Payments & Booking',
    ],
    'session' => [
        'route' => 'sessions.php',
        'title' => 'Training Sessions',
        'subtitle' => 'Schedule instructor, vehicle, date, and contact-hour sessions without tab switching.',
        'icon' => 'fas fa-calendar-plus',
        'card_title' => 'Schedule Session',
    ],
    'check' => [
        'route' => 'preuse_checks.php',
        'title' => 'Pre-use Checks',
        'subtitle' => 'Record vehicle fitness checks, odometer readings, defects, and corrective action.',
        'icon' => 'fas fa-clipboard-check',
        'card_title' => 'Record Pre-use Check',
    ],
    'cohort' => [
        'route' => 'cohorts.php',
        'title' => 'Transport Cohorts',
        'subtitle' => 'Create cohorts, manage capacities, and monitor required RTSA contact hours.',
        'icon' => 'fas fa-layer-group',
        'card_title' => 'Create Cohort',
    ],
    'fleet' => [
        'route' => 'fleet.php',
        'title' => 'Fleet Management',
        'subtitle' => 'Maintain vehicles, service dates, fitness, insurance, and supported licence classes.',
        'icon' => 'fas fa-truck',
        'card_title' => 'Fleet Workspace',
    ],
    'instructor' => [
        'route' => 'instructors.php',
        'title' => 'Transport Instructors',
        'subtitle' => 'Maintain instructor licences, RTSA, TEVETA, ZCILT, and active assignment status.',
        'icon' => 'fas fa-id-card',
        'card_title' => 'Add Instructor',
    ],
    'client' => [
        'route' => 'clients.php',
        'title' => 'Corporate Clients',
        'subtitle' => 'Manage corporate training clients, billing contacts, enrolments, and balances.',
        'icon' => 'fas fa-building',
        'card_title' => 'Save Corporate Client',
    ],
    'fees' => [
        'route' => 'fees.php',
        'title' => 'Course Fees & Requirements',
        'subtitle' => 'Set approved, year-versioned fees per study mode, additional charges, and course entry requirements.',
        'icon' => 'fas fa-coins',
        'card_title' => 'Course Fees & Requirements',
    ],
];
$transportTabs = array_values(array_diff(array_keys($transportPages), ['overview']));
$transportPageTabs = [];
foreach ($transportPages as $tabKey => $pageConfig) {
    if ($tabKey !== 'overview') {
        $transportTabRoutes[$tabKey] = $pageConfig['route'];
    }
    $transportPageTabs[$pageConfig['route']] = $tabKey;
}
$currentTransportPage = basename((string)($_SERVER['PHP_SELF'] ?? 'transport_management.php'));
$requestedTab = (string)($_GET['tab'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $currentTransportPage === 'transport_management.php' && isset($transportTabRoutes[$requestedTab])) {
    $query = $_GET;
    unset($query['tab']);
    $target = '/wucportal/transport/' . $transportTabRoutes[$requestedTab];
    if ($query) {
        $target .= '?' . http_build_query($query);
    }
    header('Location: ' . $target, true, 302);
    exit;
}
$activeTab = defined('TRANSPORT_ACTIVE_SECTION')
    ? (string)TRANSPORT_ACTIVE_SECTION
    : ($transportPageTabs[$currentTransportPage] ?? (in_array($requestedTab, $transportTabs, true) ? $requestedTab : 'overview'));
if (!empty($_SESSION['transport_flash']) && is_array($_SESSION['transport_flash'])) {
    $message = (string)($_SESSION['transport_flash']['message'] ?? '');
    $messageType = (string)($_SESSION['transport_flash']['type'] ?? 'info');
    $activeTab = (string)($_SESSION['transport_flash']['tab'] ?? $activeTab);
    unset($_SESSION['transport_flash']);
}
$isOverviewPage = $activeTab === 'overview';
$activePage = $transportPages[$activeTab] ?? $transportPages['overview'];
$page_title = $activePage['title'];
$showLeftReports = $isOverviewPage || in_array($activeTab, ['cohort', 'trainee', 'session'], true);
$showRightReports = $isOverviewPage || in_array($activeTab, ['fleet', 'instructor', 'check', 'client'], true);

function tm_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tm_table_exists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function tm_fetch_all(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    if ($types === '') {
        $res = $db->query($sql);
        if (!$res) {
            error_log('Transport query failed: ' . $db->error);
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
        return $rows;
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('Transport prepare failed: ' . $db->error);
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && $row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function tm_person_full_name(string $firstName, string $lastName, string $fallback = ''): string
{
    $name = trim(preg_replace('/\s+/', ' ', trim($firstName) . ' ' . trim($lastName)) ?? '');
    return $name !== '' ? $name : trim($fallback);
}

function tm_fetch_student_by_sid(mysqli $db, string $studentId): ?array
{
    $studentId = trim($studentId);
    if ($studentId === '') {
        return null;
    }
    $rows = tm_fetch_all(
        $db,
        "SELECT SID, Fname, Lname, email, mobile, status, next_kin, next_kin_mobile FROM students WHERE SID = ? LIMIT 1",
        's',
        [$studentId]
    );
    return $rows[0] ?? null;
}

function tm_merge_trainee_candidate_rows(array &$candidates, array $rows, string $sourceType): void
{
    foreach ($rows as $row) {
        $sid = trim((string)($row['SID'] ?? ''));
        if ($sid === '') {
            continue;
        }

        if (!isset($candidates[$sid])) {
            $row['trainee_sources'] = [];
            $candidates[$sid] = $row;
        }

        $source = trim((string)($row['trainee_source'] ?? ''));
        if ($source === '') {
            $source = $sourceType;
        } else {
            $source = $sourceType . ': ' . $source;
        }

        if (!in_array($source, $candidates[$sid]['trainee_sources'], true)) {
            $candidates[$sid]['trainee_sources'][] = $source;
        }
    }
}

function tm_fetch_trainee_candidates(mysqli $db): array
{
    $candidates = [];

    if (tm_table_exists($db, 'short_course_enrollments') && tm_table_exists($db, 'short_courses')) {
        $rows = tm_fetch_all($db, "
            SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.status, s.next_kin, s.next_kin_mobile,
                   sc_src.trainee_source
            FROM students s
            INNER JOIN (
                SELECT sce.student_id,
                       GROUP_CONCAT(DISTINCT sc.course_code ORDER BY sc.course_code SEPARATOR ', ') AS trainee_source
                FROM short_course_enrollments sce
                INNER JOIN short_courses sc ON sc.id = sce.short_course_id
                WHERE sce.status IN ('enrolled','active','completed')
                GROUP BY sce.student_id
            ) sc_src ON sc_src.student_id COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci
            ORDER BY s.Lname, s.Fname, s.SID
        ");
        tm_merge_trainee_candidate_rows($candidates, $rows, 'Short course');
    }

    if (tm_table_exists($db, 'student_program')) {
        $rows = tm_fetch_all($db, "
            SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.status, s.next_kin, s.next_kin_mobile,
                   sp_src.trainee_source
            FROM students s
            INNER JOIN (
                SELECT sp.Sid,
                       GROUP_CONCAT(DISTINCT sp.program_code ORDER BY sp.program_code SEPARATOR ', ') AS trainee_source
                FROM student_program sp
                WHERE sp.program_code LIKE 'ITC-%'
                  AND COALESCE(LOWER(sp.status), 'active') NOT IN ('inactive','withdrawn','suspended')
                GROUP BY sp.Sid
            ) sp_src ON sp_src.Sid COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci
            ORDER BY s.Lname, s.Fname, s.SID
        ");
        tm_merge_trainee_candidate_rows($candidates, $rows, 'ITC programme');
    }

    if (tm_table_exists($db, 'student_program') && tm_table_exists($db, 'transport_programs')) {
        $rows = tm_fetch_all($db, "
            SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.status, s.next_kin, s.next_kin_mobile,
                   tp_src.trainee_source
            FROM students s
            INNER JOIN (
                SELECT sp.Sid,
                       GROUP_CONCAT(DISTINCT tp.program_code ORDER BY tp.program_code SEPARATOR ', ') AS trainee_source
                FROM student_program sp
                INNER JOIN transport_programs tp ON tp.program_code COLLATE utf8mb4_general_ci = sp.program_code COLLATE utf8mb4_general_ci
                WHERE COALESCE(LOWER(sp.status), 'active') NOT IN ('inactive','withdrawn','suspended')
                  AND tp.status = 'active'
                GROUP BY sp.Sid
            ) tp_src ON tp_src.Sid COLLATE utf8mb4_general_ci = s.SID COLLATE utf8mb4_general_ci
            ORDER BY s.Lname, s.Fname, s.SID
        ");
        tm_merge_trainee_candidate_rows($candidates, $rows, 'Transport programme');
    }

    if (tm_table_exists($db, 'transport_trainees')) {
        $rows = tm_fetch_all($db, "
            SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.status, s.next_kin, s.next_kin_mobile,
                   'existing transport record' AS trainee_source
            FROM transport_trainees t
            INNER JOIN students s ON s.SID COLLATE utf8mb4_general_ci = t.student_id COLLATE utf8mb4_general_ci
            WHERE t.student_id IS NOT NULL AND t.student_id <> ''
            ORDER BY s.Lname, s.Fname, s.SID
        ");
        tm_merge_trainee_candidate_rows($candidates, $rows, 'Transport');
    }

    // Fallback: any active student may be enrolled as a transport trainee.
    // Driving-school / transport trainees are frequently walk-ins rather than
    // existing programme students, so without this the candidate pool can be
    // empty and no trainee can be enrolled at all.
    if (tm_table_exists($db, 'students')) {
        $rows = tm_fetch_all($db, "
            SELECT s.SID, s.Fname, s.Lname, s.email, s.mobile, s.status, s.next_kin, s.next_kin_mobile,
                   'Student record' AS trainee_source
            FROM students s
            WHERE COALESCE(LOWER(NULLIF(TRIM(s.status), '')), 'active') NOT IN ('deleted','inactive','withdrawn','suspended')
            ORDER BY s.Lname, s.Fname, s.SID
        ");
        tm_merge_trainee_candidate_rows($candidates, $rows, 'Student');
    }

    foreach ($candidates as &$candidate) {
        $candidate['trainee_source'] = implode(' | ', $candidate['trainee_sources']);
    }
    unset($candidate);

    uasort($candidates, static function (array $a, array $b): int {
        $left = strtolower(trim((string)($a['Lname'] ?? '') . ' ' . (string)($a['Fname'] ?? '') . ' ' . (string)($a['SID'] ?? '')));
        $right = strtolower(trim((string)($b['Lname'] ?? '') . ' ' . (string)($b['Fname'] ?? '') . ' ' . (string)($b['SID'] ?? '')));
        return $left <=> $right;
    });

    return array_values($candidates);
}

function tm_fetch_trainee_candidate_by_sid(mysqli $db, string $studentId): ?array
{
    $student = tm_fetch_student_by_sid($db, $studentId);
    if (!$student) {
        return null;
    }

    foreach (tm_fetch_trainee_candidates($db) as $candidate) {
        if (strcasecmp((string)$candidate['SID'], $studentId) === 0) {
            return array_merge($student, [
                'trainee_source' => (string)($candidate['trainee_source'] ?? ''),
            ]);
        }
    }

    return null;
}

function tm_fetch_staff_by_staff_id(mysqli $db, string $staffId): ?array
{
    $staffId = trim($staffId);
    if ($staffId === '') {
        return null;
    }
    $rows = tm_fetch_all(
        $db,
        "SELECT staff_id, Fname, Lname, role, status, email, mobile FROM staff WHERE staff_id = ? LIMIT 1",
        's',
        [$staffId]
    );
    return $rows[0] ?? null;
}

function tm_staff_record_is_active(array $staffRecord): bool
{
    $status = strtolower(trim((string)($staffRecord['status'] ?? '')));
    if ($status === '') {
        return true;
    }
    return !in_array($status, ['deleted', 'inactive', 'disabled', 'suspended', 'terminated'], true);
}

function tm_staff_has_instructor_profile(mysqli $db, string $staffId, int $excludeId = 0): bool
{
    $staffId = trim($staffId);
    if ($staffId === '') {
        return false;
    }
    $stmt = tm_prepare($db, "SELECT 1 FROM transport_instructors WHERE staff_id = ? AND id <> ? LIMIT 1");
    $stmt->bind_param('si', $staffId, $excludeId);
    tm_execute($stmt, 'Unable to verify instructor staff assignment.');
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function tm_find_trainee_id_by_student(mysqli $db, string $studentId): int
{
    $studentId = trim($studentId);
    if ($studentId === '') {
        return 0;
    }
    $stmt = tm_prepare($db, "
        SELECT id
        FROM transport_trainees
        WHERE student_id COLLATE utf8mb4_general_ci = ?
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->bind_param('s', $studentId);
    tm_execute($stmt, 'Unable to verify existing trainee profile.');
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['id'] ?? 0);
}

/** The program_id behind a cohort (the "course" for eligibility + fees), or 0. */
function tm_cohort_program_id(mysqli $db, int $cohortId): int
{
    if ($cohortId <= 0) {
        return 0;
    }
    $stmt = tm_prepare($db, "SELECT program_id FROM transport_cohorts WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $cohortId);
    tm_execute($stmt, 'Unable to resolve the cohort program.');
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['program_id'] ?? 0);
}

function tm_student_has_cohort_enrollment(mysqli $db, string $studentId, int $cohortId, int $excludeEnrollmentId = 0): bool
{
    $studentId = trim($studentId);
    if ($studentId === '' || $cohortId <= 0) {
        return false;
    }

    $sql = "
        SELECT 1
        FROM transport_enrollments e
        INNER JOIN transport_trainees t ON t.id = e.trainee_id
        WHERE t.student_id COLLATE utf8mb4_general_ci = ?
          AND e.cohort_id = ?
    ";
    $types = 'si';
    $params = [$studentId, $cohortId];
    if ($excludeEnrollmentId > 0) {
        $sql .= " AND e.id <> ?";
        $types .= 'i';
        $params[] = $excludeEnrollmentId;
    }
    $sql .= " LIMIT 1";

    $stmt = tm_prepare($db, $sql);
    $stmt->bind_param($types, ...$params);
    tm_execute($stmt, 'Unable to verify existing trainee enrollment.');
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function tm_fetch_enrollment_identity(mysqli $db, int $enrollmentId, int $traineeId): ?array
{
    if ($enrollmentId <= 0 || $traineeId <= 0) {
        return null;
    }
    $stmt = tm_prepare($db, "
        SELECT e.id AS enrollment_id, e.cohort_id, t.id AS trainee_id, t.student_id
        FROM transport_enrollments e
        INNER JOIN transport_trainees t ON t.id = e.trainee_id
        WHERE e.id = ? AND t.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $enrollmentId, $traineeId);
    tm_execute($stmt, 'Unable to verify trainee enrollment ownership.');
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function tm_student_emergency_contact(array $student): string
{
    $kin = trim((string)($student['next_kin'] ?? ''));
    $kinPhone = trim((string)($student['next_kin_mobile'] ?? ''));
    if ($kin !== '' && $kinPhone !== '') {
        return $kin . ' - ' . $kinPhone;
    }
    return $kin !== '' ? $kin : $kinPhone;
}

function tm_scalar(mysqli $db, string $sql): float
{
    $res = $db->query($sql);
    if (!$res) {
        error_log('Transport scalar failed: ' . $db->error);
        return 0;
    }
    $row = $res->fetch_row();
    $res->free();
    return isset($row[0]) ? (float)$row[0] : 0;
}

function tm_prepare(mysqli $db, string $sql): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log('Transport prepare failed: ' . $db->error);
        throw new RuntimeException('Unable to prepare the transport database operation.');
    }
    return $stmt;
}

function tm_execute(mysqli_stmt $stmt, string $message = 'Unable to save the transport record.'): void
{
    if (!$stmt->execute()) {
        error_log('Transport execute failed: ' . $stmt->error);
        throw new RuntimeException($message);
    }
}

function tm_redirect_self(): void
{
    $target = strtok((string)($_SERVER['REQUEST_URI'] ?? 'transport_management.php'), '?') ?: 'transport_management.php';
    if (!headers_sent()) {
        header('Location: ' . $target);
        exit;
    }
    echo '<script>window.location.href=' . json_encode($target) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . tm_h($target) . '"></noscript>';
    exit;
}

function tm_normalize_date(?string $value, string $label, bool $required = false): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        if ($required) {
            throw new RuntimeException($label . ' is required.');
        }
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException($label . ' must be a valid date.');
    }
    return $value;
}

function tm_normalize_month(?string $value, string $label, bool $required = false): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        if ($required) {
            throw new RuntimeException($label . ' is required.');
        }
        return null;
    }

    $month = DateTimeImmutable::createFromFormat('Y-m', $value);
    if ($month && $month->format('Y-m') === $value) {
        return $month->format('Y-m-01');
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($date && $date->format('Y-m-d') === $value) {
        return $date->format('Y-m-01');
    }

    throw new RuntimeException($label . ' must be a valid month.');
}

function tm_normalize_datetime(?string $value, string $label, bool $required = false): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        if ($required) {
            throw new RuntimeException($label . ' is required.');
        }
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    if (!$date) {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    }
    if (!$date) {
        throw new RuntimeException($label . ' must be a valid date and time.');
    }
    return $date->format('Y-m-d H:i:s');
}

function tm_session_context(mysqli $db, int $cohortId, int $instructorId, ?int $vehicleId): array
{
    $stmt = tm_prepare($db, "
        SELECT c.id AS cohort_id, c.campus_id AS cohort_campus_id, c.start_date, c.end_date,
               c.status AS cohort_status, i.id AS instructor_id, i.campus_id AS instructor_campus_id,
               i.status AS instructor_status, i.rtsa_expiry, i.teveta_expiry
        FROM transport_cohorts c
        INNER JOIN transport_instructors i ON i.id = ?
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $instructorId, $cohortId);
    tm_execute($stmt, 'Unable to validate the session resources.');
    $context = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$context) {
        throw new RuntimeException('Select a valid cohort and instructor for this session.');
    }

    $context['vehicle'] = null;
    if ($vehicleId !== null) {
        $stmt = tm_prepare($db, "SELECT id, campus_id, registration_no, vehicle_type, status, fitness_expiry, insurance_expiry FROM transport_vehicles WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $vehicleId);
        tm_execute($stmt, 'Unable to validate the selected vehicle.');
        $context['vehicle'] = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$context['vehicle']) {
            throw new RuntimeException('Select a valid vehicle for this session.');
        }
    }
    return $context;
}

function tm_validate_session_rules(array $context, string $sessionType, string $sessionDate, string $status, float &$contactHours, string $startTime, string $endTime): void
{
    $elapsedHours = (strtotime($endTime) - strtotime($startTime)) / 3600;
    if ($elapsedHours <= 0 || $elapsedHours > 24) {
        throw new RuntimeException('Session end time must be after start time and within the same day.');
    }
    if ($contactHours <= 0) {
        $contactHours = round($elapsedHours, 2);
    }
    if ($contactHours > $elapsedHours + 0.001) {
        throw new RuntimeException('Contact hours cannot exceed the scheduled session duration.');
    }
    $contactHours = round($contactHours, 2);

    if ($status === 'cancelled') {
        return;
    }
    if (in_array((string)$context['cohort_status'], ['completed', 'cancelled'], true)) {
        throw new RuntimeException('Sessions cannot be scheduled for a completed or cancelled cohort.');
    }
    if ($sessionDate < (string)$context['start_date'] || (!empty($context['end_date']) && $sessionDate > (string)$context['end_date'])) {
        throw new RuntimeException('Session date must fall within the cohort start and end dates.');
    }
    if ((string)$context['instructor_status'] !== 'active') {
        throw new RuntimeException('The selected instructor is not active.');
    }
    if ((int)$context['instructor_campus_id'] !== (int)$context['cohort_campus_id']) {
        throw new RuntimeException('The instructor and cohort must belong to the same campus.');
    }
    foreach (['rtsa_expiry' => 'RTSA licence', 'teveta_expiry' => 'TEVETA accreditation'] as $field => $label) {
        if (!empty($context[$field]) && (string)$context[$field] < $sessionDate) {
            throw new RuntimeException("The instructor's {$label} expires before the session date.");
        }
    }
    if ($status === 'completed' && $sessionDate > date('Y-m-d')) {
        throw new RuntimeException('A future session cannot be marked as completed.');
    }

    $vehicle = $context['vehicle'] ?? null;
    if ($sessionType === 'practical' && !$vehicle) {
        throw new RuntimeException('A roadworthy vehicle is required for a practical session.');
    }
    if ($vehicle) {
        if (!in_array((string)$vehicle['status'], ['available', 'assigned'], true)) {
            throw new RuntimeException('The selected vehicle is not available for training.');
        }
        if ((int)$vehicle['campus_id'] !== (int)$context['cohort_campus_id']) {
            throw new RuntimeException('The vehicle and cohort must belong to the same campus.');
        }
        foreach (['fitness_expiry' => 'fitness certificate', 'insurance_expiry' => 'insurance'] as $field => $label) {
            if (empty($vehicle[$field]) || (string)$vehicle[$field] < $sessionDate) {
                throw new RuntimeException('Vehicle ' . $label . ' must be valid on the session date.');
            }
        }
    }
}

function tm_record_exists(mysqli $db, string $table, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    $allowed = [
        'transport_campuses',
        'transport_programs',
        'transport_cohorts',
        'transport_trainees',
        'transport_enrollments',
        'transport_instructors',
        'transport_vehicles',
        'transport_sessions',
        'transport_preuse_checks',
        'transport_corporate_clients',
        'transport_route_plans',
        'transport_incident_reports',
        'transport_parts_inventory',
    ];
    if (!in_array($table, $allowed, true)) {
        return false;
    }
    $stmt = tm_prepare($db, "SELECT 1 FROM `{$table}` WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    tm_execute($stmt, 'Unable to verify the selected record.');
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function tm_validate_email(string $email, string $label): string
{
    $email = trim($email);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException($label . ' must be a valid email address.');
    }
    return $email;
}

function tm_validate_phone(string $phone, string $label): string
{
    $phone = trim($phone);
    if ($phone !== '' && !preg_match('/^[0-9+() .-]{6,40}$/', $phone)) {
        throw new RuntimeException($label . ' contains invalid characters.');
    }
    return $phone;
}

function tm_validate_max_length(string $value, int $maxLength, string $label): string
{
    $value = trim($value);
    if (mb_strlen($value) > $maxLength) {
        throw new RuntimeException($label . ' is too long.');
    }
    return $value;
}

function tm_post_id(string $field = 'id'): int
{
    return max(0, (int)($_POST[$field] ?? 0));
}

function tm_select(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

function tm_checked($value): string
{
    return (int)$value === 1 ? ' checked' : '';
}

function tm_count_where_id(mysqli $db, string $table, string $column, int $id): int
{
    $allowed = [
        'transport_enrollments' => ['trainee_id', 'cohort_id', 'corporate_client_id'],
        'transport_sessions' => ['cohort_id', 'instructor_id', 'vehicle_id'],
        'transport_preuse_checks' => ['vehicle_id', 'instructor_id'],
        'transport_telematics_logs' => ['vehicle_id'],
        'transport_maintenance_logs' => ['vehicle_id'],
        'transport_fuel_logs' => ['vehicle_id'],
        'transport_route_plans' => ['vehicle_id'],
        'transport_incident_reports' => ['vehicle_id', 'instructor_id'],
        'transport_parts_inventory' => ['vehicle_id'],
    ];
    if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
        throw new RuntimeException('Invalid dependency check requested.');
    }
    $stmt = tm_prepare($db, "SELECT COUNT(*) AS c FROM `{$table}` WHERE `{$column}` = ?");
    $stmt->bind_param('i', $id);
    tm_execute($stmt, 'Unable to check related transport records.');
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $count;
}

function tm_tab_pane_class(string $tab, string $activeTab): string
{
    return 'transport-section-pane';
}

function tm_date_badge(?string $date): string
{
    if (!$date) {
        return '<span class="badge bg-secondary">Not set</span>';
    }
    $today = new DateTimeImmutable('today');
    $value = new DateTimeImmutable($date);
    $days = (int)$today->diff($value)->format('%r%a');
    if ($days < 0) {
        return '<span class="badge bg-danger">Expired ' . tm_h($date) . '</span>';
    }
    if ($days <= 30) {
        return '<span class="badge bg-warning text-dark">Due ' . tm_h($date) . '</span>';
    }
    if ($days <= 60) {
        return '<span class="badge bg-info text-dark">Soon ' . tm_h($date) . '</span>';
    }
    return '<span class="badge bg-success">' . tm_h($date) . '</span>';
}

$requiredTables = [
    'transport_campuses',
    'transport_programs',
    'transport_cohorts',
    'transport_trainees',
    'transport_enrollments',
    'transport_vehicles',
    'transport_instructors',
    'transport_sessions',
    'transport_preuse_checks',
    'transport_telematics_logs',
    'transport_maintenance_logs',
    'transport_fuel_logs',
    'transport_route_plans',
    'transport_incident_reports',
    'transport_parts_inventory',
    'transport_corporate_clients',
    'transport_corporate_bookings',
];

$needsInstall = false;
foreach ($requiredTables as $table) {
    if (!tm_table_exists($db, $table)) {
        $needsInstall = true;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfValid = isset($_POST['csrf_token']) && hash_equals($csrfToken, (string)$_POST['csrf_token']);
    if (!$csrfValid) {
        $message = 'Security token mismatch. Please refresh and try again.';
        $messageType = 'danger';
    } elseif ($needsInstall) {
        $message = 'Install the Transport Management tables before saving data.';
        $messageType = 'warning';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $actionTabs = [
            'add_trainee' => 'trainee',
            'update_trainee' => 'trainee',
            'delete_trainee' => 'trainee',
            'submit_payment_proof' => 'payments',
            'verify_payment' => 'payments',
            'reject_payment' => 'payments',
            'book_trainee' => 'payments',
            'add_cohort' => 'cohort',
            'update_cohort' => 'cohort',
            'delete_cohort' => 'cohort',
            'add_session' => 'session',
            'update_session' => 'session',
            'delete_session' => 'session',
            'add_vehicle' => 'fleet',
            'update_vehicle' => 'fleet',
            'delete_vehicle' => 'fleet',
            'add_telematics_log' => 'fleet',
            'add_maintenance_log' => 'fleet',
            'add_fuel_log' => 'fleet',
            'add_route_plan' => 'fleet',
            'update_route_status' => 'fleet',
            'add_incident_report' => 'fleet',
            'update_incident_status' => 'fleet',
            'add_part' => 'fleet',
            'update_part' => 'fleet',
            'use_part' => 'fleet',
            'add_preuse_check' => 'check',
            'update_preuse_check' => 'check',
            'delete_preuse_check' => 'check',
            'add_instructor' => 'instructor',
            'update_instructor' => 'instructor',
            'delete_instructor' => 'instructor',
            'add_client' => 'client',
            'update_client' => 'client',
            'delete_client' => 'client',
            'add_course_fee' => 'fees',
            'toggle_course_mode' => 'fees',
            'add_additional_fee' => 'fees',
            'toggle_additional_fee' => 'fees',
            'update_program_requirements' => 'fees',
        ];
        $activeTab = $actionTabs[$action] ?? $activeTab;
        $transportTransactionOpen = false;
        try {
            if ($action === 'add_client') {
                $name = tm_validate_max_length((string)($_POST['client_name'] ?? ''), 180, 'Corporate client name');
                $contact = tm_validate_max_length((string)($_POST['contact_person'] ?? ''), 140, 'Contact person');
                $phone = tm_validate_phone((string)($_POST['phone'] ?? ''), 'Client phone');
                $email = tm_validate_email((string)($_POST['email'] ?? ''), 'Client email');
                $address = tm_validate_max_length((string)($_POST['billing_address'] ?? ''), 255, 'Billing address');

                if ($name === '') {
                    throw new RuntimeException('Corporate client name is required.');
                }
                if (mb_strlen($email) > 140) {
                    throw new RuntimeException('Client email is too long.');
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_corporate_clients (client_name, contact_person, phone, email, billing_address) VALUES (?,?,?,?,?)");
                $stmt->bind_param('sssss', $name, $contact, $phone, $email, $address);
                tm_execute($stmt, 'Unable to save the corporate client.');
                $stmt->close();
                $message = 'Corporate client saved.';
                $messageType = 'success';
            }

            if ($action === 'add_vehicle') {
                $campusId = (int)($_POST['campus_id'] ?? 0);
                $assetTag = trim((string)($_POST['asset_tag'] ?? ''));
                $registrationNo = strtoupper(trim((string)($_POST['registration_no'] ?? '')));
                $vehicleType = (string)($_POST['vehicle_type'] ?? 'other');
                $makeModel = trim((string)($_POST['make_model'] ?? ''));
                $supportedClass = trim((string)($_POST['supported_license_class'] ?? ''));
                $mileage = max(0, (int)($_POST['current_mileage'] ?? 0));
                $status = (string)($_POST['vehicle_status'] ?? 'available');
                $fitnessExpiry = tm_normalize_date($_POST['fitness_expiry'] ?? '', 'Fitness expiry');
                $insuranceExpiry = tm_normalize_date($_POST['insurance_expiry'] ?? '', 'Insurance expiry');
                $nextServiceDue = tm_normalize_date($_POST['next_service_due'] ?? '', 'Next service date');
                $notes = trim((string)($_POST['vehicle_notes'] ?? ''));

                $allowedTypes = ['light_vehicle','rigid_truck','articulated_truck','coach','motorcycle','forklift','simulator','trailer','other'];
                $allowedStatus = ['available','assigned','maintenance','unavailable'];
                if ($campusId <= 0 || $registrationNo === '') {
                    throw new RuntimeException('Campus and registration number are required for fleet records.');
                }
                if (!tm_record_exists($db, 'transport_campuses', $campusId)) {
                    throw new RuntimeException('Select a valid campus for this vehicle.');
                }
                if (!preg_match('/^[A-Z0-9 -]{2,40}$/', $registrationNo)) {
                    throw new RuntimeException('Registration number may only use letters, numbers, spaces, and dashes.');
                }
                if (!in_array($vehicleType, $allowedTypes, true)) {
                    $vehicleType = 'other';
                }
                if (!in_array($status, $allowedStatus, true)) {
                    $status = 'available';
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_vehicles (campus_id, asset_tag, registration_no, vehicle_type, make_model, supported_license_class, current_mileage, status, fitness_expiry, insurance_expiry, next_service_due, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('isssssisssss', $campusId, $assetTag, $registrationNo, $vehicleType, $makeModel, $supportedClass, $mileage, $status, $fitnessExpiry, $insuranceExpiry, $nextServiceDue, $notes);
                tm_execute($stmt, 'Unable to save the fleet record. Check that the registration number is not already in use.');
                $stmt->close();
                $message = 'Vehicle added to the transport fleet.';
                $messageType = 'success';
            }

            if ($action === 'add_instructor') {
                $campusId = (int)($_POST['campus_id'] ?? 0);
                $staffId = trim((string)($_POST['staff_id'] ?? ''));
                $staffRecord = tm_fetch_staff_by_staff_id($db, $staffId);
                if (!$staffRecord) {
                    throw new RuntimeException('Select a valid staff ID from the admin staff database.');
                }
                if (!tm_staff_record_is_active($staffRecord)) {
                    throw new RuntimeException('Select an active staff member for the instructor profile.');
                }
                if (tm_staff_has_instructor_profile($db, $staffId)) {
                    throw new RuntimeException('This staff member already has a transport instructor profile. Update the existing profile instead.');
                }
                $fullName = tm_person_full_name((string)$staffRecord['Fname'], (string)$staffRecord['Lname'], $staffId);
                $licenseClasses = trim((string)($_POST['license_classes'] ?? ''));
                $rtsaNo = trim((string)($_POST['rtsa_license_no'] ?? ''));
                $rtsaExpiry = tm_normalize_date($_POST['rtsa_expiry'] ?? '', 'RTSA expiry');
                $tevetaNo = trim((string)($_POST['teveta_accreditation_no'] ?? ''));
                $tevetaExpiry = tm_normalize_date($_POST['teveta_expiry'] ?? '', 'TEVETA expiry');
                $zciltNo = trim((string)($_POST['zcilt_member_no'] ?? ''));
                $status = (string)($_POST['instructor_status'] ?? 'active');
                $notes = trim((string)($_POST['instructor_notes'] ?? ''));

                if ($campusId <= 0 || $staffId === '') {
                    throw new RuntimeException('Campus and staff ID are required.');
                }
                if (!tm_record_exists($db, 'transport_campuses', $campusId)) {
                    throw new RuntimeException('Select a valid campus for this instructor.');
                }
                if (!in_array($status, ['active','inactive','on_leave'], true)) {
                    $status = 'active';
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_instructors (staff_id, campus_id, full_name, license_classes, rtsa_license_no, rtsa_expiry, teveta_accreditation_no, teveta_expiry, zcilt_member_no, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('sisssssssss', $staffId, $campusId, $fullName, $licenseClasses, $rtsaNo, $rtsaExpiry, $tevetaNo, $tevetaExpiry, $zciltNo, $status, $notes);
                tm_execute($stmt, 'Unable to save the instructor profile.');
                $stmt->close();
                $message = 'Instructor compliance profile saved.';
                $messageType = 'success';
            }

            if ($action === 'add_cohort') {
                $programId = (int)($_POST['program_id'] ?? 0);
                $campusId = (int)($_POST['campus_id'] ?? 0);
                $cohortName = trim((string)($_POST['cohort_name'] ?? ''));
                $intakeMonth = tm_normalize_month($_POST['intake_month'] ?? '', 'Intake month');
                $startDate = tm_normalize_date($_POST['start_date'] ?? '', 'Start date', true);
                $endDate = tm_normalize_date($_POST['end_date'] ?? '', 'End date');
                $capacity = max(1, (int)($_POST['capacity'] ?? 20));
                $status = (string)($_POST['cohort_status'] ?? 'planning');
                $notes = trim((string)($_POST['cohort_notes'] ?? ''));

                if ($programId <= 0 || $campusId <= 0 || $cohortName === '' || $startDate === '') {
                    throw new RuntimeException('Program, campus, cohort name, and start date are required.');
                }
                if (!tm_record_exists($db, 'transport_programs', $programId)) {
                    throw new RuntimeException('Select a valid transport program.');
                }
                if (!tm_record_exists($db, 'transport_campuses', $campusId)) {
                    throw new RuntimeException('Select a valid campus for this cohort.');
                }
                if ($endDate !== null && strtotime($endDate) < strtotime($startDate)) {
                    throw new RuntimeException('End date cannot be before the start date.');
                }
                if (!in_array($status, ['planning','open','in_progress','completed','cancelled'], true)) {
                    $status = 'planning';
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_cohorts (program_id, campus_id, cohort_name, intake_month, start_date, end_date, capacity, status, notes) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iissssiss', $programId, $campusId, $cohortName, $intakeMonth, $startDate, $endDate, $capacity, $status, $notes);
                tm_execute($stmt, 'Unable to save the transport cohort.');
                $stmt->close();
                $message = 'Transport cohort created.';
                $messageType = 'success';
            }

            if ($action === 'add_trainee') {
                $cohortId = (int)($_POST['cohort_id'] ?? 0);
                $clientId = (int)($_POST['corporate_client_id'] ?? 0);
                $studentId = trim((string)($_POST['student_id'] ?? ''));
                $studentRecord = tm_fetch_trainee_candidate_by_sid($db, $studentId);
                if (!$studentRecord) {
                    throw new RuntimeException('Select a valid trainee from short-course, ITC programme, or transport enrolment records.');
                }
                $firstName = trim((string)$studentRecord['Fname']);
                $lastName = trim((string)$studentRecord['Lname']);
                $phone = tm_validate_phone((string)($studentRecord['mobile'] ?? ''), 'Trainee phone');
                $email = tm_validate_email((string)($studentRecord['email'] ?? ''), 'Trainee email');
                $licenseClass = trim((string)($_POST['existing_license_class'] ?? ''));
                $medical = (string)($_POST['medical_clearance_status'] ?? 'pending');
                $emergency = tm_student_emergency_contact($studentRecord);
                $employer = trim((string)($_POST['employer_name'] ?? ''));
                $enrollmentDate = tm_normalize_date($_POST['enrollment_date'] ?? date('Y-m-d'), 'Enrollment date', true);
                $notes = trim((string)($_POST['enrollment_notes'] ?? ''));
                $enrollmentType = $clientId > 0 ? 'corporate' : 'individual';
                $nullableClientId = $clientId > 0 ? $clientId : null;
                $status = 'enrolled';

                if ($cohortId <= 0 || $studentId === '') {
                    throw new RuntimeException('Cohort and student ID are required for trainee enrolment.');
                }
                if (!tm_record_exists($db, 'transport_cohorts', $cohortId)) {
                    throw new RuntimeException('Select a valid cohort for this trainee.');
                }
                $cohortState = tm_fetch_all($db, "SELECT status FROM transport_cohorts WHERE id = ? LIMIT 1", 'i', [$cohortId]);
                if (!in_array((string)($cohortState[0]['status'] ?? ''), ['open', 'in_progress'], true)) {
                    throw new RuntimeException('Trainees can only be enrolled into an open or in-progress cohort.');
                }
                if ($clientId > 0 && !tm_record_exists($db, 'transport_corporate_clients', $clientId)) {
                    throw new RuntimeException('Select a valid corporate client.');
                }
                if (tm_student_has_cohort_enrollment($db, $studentId, $cohortId)) {
                    throw new RuntimeException('This trainee is already enrolled in the selected cohort. Update the existing enrollment instead.');
                }
                if (!in_array($medical, ['pending','cleared','not_required','failed'], true)) {
                    $medical = 'pending';
                }

                // Resolve the program behind the cohort (the "course" for eligibility + fees).
                $programId = tm_cohort_program_id($db, $cohortId);

                // §7 eligibility gate. Hard failures block enrolment unless an admissions
                // officer / admin overrides with a reason (audited after the insert, BR018).
                $program = $programId > 0 ? te_program_requirements($db, $programId) : null;
                $applicant = te_applicant_from_student($studentRecord, $_POST);
                $elig = $program
                    ? te_check_eligibility($applicant, $program)
                    : ['eligible' => true, 'reasons' => [], 'warnings' => []];
                $overrideOk = !empty($_POST['eligibility_override']) && (canAccessAdmissions() || isSystemsAdmin());
                if (!$elig['eligible'] && !$overrideOk) {
                    throw new RuntimeException(
                        'Applicant is not eligible' . ($program ? ' for ' . $program['program_name'] : '') . ': '
                        . implode(' ', $elig['reasons'])
                    );
                }

                // §8 fee — computed from the APPROVED schedule, never trusted from the form (BR008).
                $mode = trim((string)($_POST['training_mode'] ?? ''));
                $optionIds = array_map('intval', (array)($_POST['fee_options'] ?? []));
                $feeCalc = tf_calculate($db, $programId, $mode, $optionIds);
                if (!$feeCalc['ok']) {
                    throw new RuntimeException($feeCalc['error']);
                }
                $feeAmount = (float)$feeCalc['total'];

                $db->begin_transaction();
                $transportTransactionOpen = true;
                $traineeId = tm_find_trainee_id_by_student($db, $studentId);
                if ($traineeId > 0) {
                    $stmt = tm_prepare($db, "UPDATE transport_trainees SET first_name = ?, last_name = ?, phone = ?, email = ?, existing_license_class = ?, medical_clearance_status = ?, emergency_contact = ?, employer_name = ? WHERE id = ?");
                    $stmt->bind_param('ssssssssi', $firstName, $lastName, $phone, $email, $licenseClass, $medical, $emergency, $employer, $traineeId);
                    tm_execute($stmt, 'Unable to update existing trainee details.');
                    $stmt->close();
                } else {
                    $stmt = tm_prepare($db, "INSERT INTO transport_trainees (student_id, first_name, last_name, phone, email, existing_license_class, medical_clearance_status, emergency_contact, employer_name) VALUES (?,?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('sssssssss', $studentId, $firstName, $lastName, $phone, $email, $licenseClass, $medical, $emergency, $employer);
                    tm_execute($stmt, 'Unable to save trainee details.');
                    $traineeId = (int)$db->insert_id;
                    $stmt->close();
                }

                // BR001: enrol in a pending state. amount_paid stays 0 until an
                // accounts officer verifies a payment via the Payments & Booking screen.
                $initialPaid = 0.00;
                $stmt = tm_prepare($db, "INSERT INTO transport_enrollments (trainee_id, cohort_id, corporate_client_id, enrollment_type, enrollment_date, fee_amount, amount_paid, status, notes, payment_status, booking_status) VALUES (?,?,?,?,?,?,?,?,?, 'awaiting_payment', 'pending_payment')");
                $stmt->bind_param('iiissddss', $traineeId, $cohortId, $nullableClientId, $enrollmentType, $enrollmentDate, $feeAmount, $initialPaid, $status, $notes);
                tm_execute($stmt, 'Unable to enroll the trainee into the selected cohort.');
                $newEnrollmentId = (int)$db->insert_id;
                $stmt->close();
                $db->commit();
                $transportTransactionOpen = false;

                // BR018: audit any eligibility override + soft warnings against the new enrolment.
                if ($newEnrollmentId > 0) {
                    if (!$elig['eligible'] && $overrideOk) {
                        $ovReason = trim((string)($_POST['eligibility_override_reason'] ?? ''));
                        tpay_audit($db, $newEnrollmentId, 'eligibility_override',
                            'Override: ' . ($ovReason !== '' ? $ovReason : 'no reason given')
                            . ' [' . implode('; ', $elig['reasons']) . ']');
                    }
                    if (!empty($elig['warnings'])) {
                        tpay_audit($db, $newEnrollmentId, 'eligibility_warning', implode('; ', $elig['warnings']));
                    }
                }

                // Generate the invoice from the assessed fee (§4.5). Non-fatal: the
                // enrolment is already committed, and the invoice is created lazily
                // on first view if this ever fails.
                $invoiceNote = '';
                if ($newEnrollmentId > 0) {
                    try {
                        $inv = tinv_ensure_invoice($db, $newEnrollmentId, $feeCalc);
                        if ($inv) {
                            $invoiceNote = ' Invoice ' . $inv['invoice_number'] . ' generated.';
                        }
                    } catch (Throwable $invEx) {
                        error_log('add_trainee invoice generation failed: ' . $invEx->getMessage());
                    }
                }

                $message = 'Trainee enrolled into the transport cohort. Fee assessed: ZMW '
                    . number_format($feeAmount, 2) . ' (' . $feeCalc['mode'] . ').' . $invoiceNote;
                $messageType = 'success';
            }

            if ($action === 'add_session') {
                $cohortId = (int)($_POST['cohort_id'] ?? 0);
                $instructorId = (int)($_POST['instructor_id'] ?? 0);
                $vehicleIdRaw = (int)($_POST['vehicle_id'] ?? 0);
                $vehicleId = $vehicleIdRaw > 0 ? $vehicleIdRaw : null;
                $sessionType = (string)($_POST['session_type'] ?? 'practical');
                $sessionDate = tm_normalize_date($_POST['session_date'] ?? '', 'Session date', true);
                $startTime = trim((string)($_POST['start_time'] ?? ''));
                $endTime = trim((string)($_POST['end_time'] ?? ''));
                $contactHours = (float)($_POST['contact_hours'] ?? 0);
                $location = trim((string)($_POST['location'] ?? ''));
                $status = (string)($_POST['session_status'] ?? 'scheduled');
                $notes = trim((string)($_POST['session_notes'] ?? ''));

                if ($cohortId <= 0 || $instructorId <= 0 || $sessionDate === '' || $startTime === '' || $endTime === '') {
                    throw new RuntimeException('Cohort, instructor, date, start time, and end time are required.');
                }
                if (!tm_record_exists($db, 'transport_cohorts', $cohortId)) {
                    throw new RuntimeException('Select a valid cohort for this session.');
                }
                if (!tm_record_exists($db, 'transport_instructors', $instructorId)) {
                    throw new RuntimeException('Select a valid instructor for this session.');
                }
                if ($vehicleId !== null && !tm_record_exists($db, 'transport_vehicles', $vehicleId)) {
                    throw new RuntimeException('Select a valid vehicle for this session.');
                }
                if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
                    throw new RuntimeException('Start and end time must be valid.');
                }
                if (strtotime($endTime) <= strtotime($startTime)) {
                    throw new RuntimeException('Session end time must be after start time.');
                }
                if ($contactHours <= 0) {
                    $contactHours = round((strtotime($endTime) - strtotime($startTime)) / 3600, 2);
                }
                if (!in_array($sessionType, ['theory','practical','simulator','assessment','maintenance_window'], true)) {
                    $sessionType = 'practical';
                }
                if (!in_array($status, ['scheduled','completed','cancelled'], true)) {
                    $status = 'scheduled';
                }

                $sessionContext = tm_session_context($db, $cohortId, $instructorId, $vehicleId);
                tm_validate_session_rules($sessionContext, $sessionType, $sessionDate, $status, $contactHours, $startTime, $endTime);

                $stmt = tm_prepare($db, "SELECT COUNT(*) AS c FROM transport_sessions WHERE instructor_id = ? AND session_date = ? AND status <> 'cancelled' AND start_time < ? AND end_time > ?");
                $stmt->bind_param('isss', $instructorId, $sessionDate, $endTime, $startTime);
                tm_execute($stmt, 'Unable to check instructor availability.');
                $conflict = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();
                if ($conflict > 0) {
                    throw new RuntimeException('Instructor conflict detected for the selected date and time.');
                }

                if ($vehicleId !== null) {
                    $stmt = tm_prepare($db, "SELECT COUNT(*) AS c FROM transport_sessions WHERE vehicle_id = ? AND session_date = ? AND status <> 'cancelled' AND start_time < ? AND end_time > ?");
                    $stmt->bind_param('isss', $vehicleId, $sessionDate, $endTime, $startTime);
                    tm_execute($stmt, 'Unable to check vehicle availability.');
                    $conflict = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                    $stmt->close();
                    if ($conflict > 0) {
                        throw new RuntimeException('Vehicle conflict detected for the selected date and time.');
                    }
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_sessions (cohort_id, instructor_id, vehicle_id, session_type, session_date, start_time, end_time, contact_hours, location, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iiissssdsss', $cohortId, $instructorId, $vehicleId, $sessionType, $sessionDate, $startTime, $endTime, $contactHours, $location, $status, $notes);
                tm_execute($stmt, 'Unable to schedule the transport session.');
                $stmt->close();
                $message = 'Training session scheduled.';
                $messageType = 'success';
            }

            if ($action === 'add_preuse_check') {
                $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
                $instructorIdRaw = (int)($_POST['instructor_id'] ?? 0);
                $instructorId = $instructorIdRaw > 0 ? $instructorIdRaw : null;
                $checkDate = tm_normalize_date($_POST['checklist_date'] ?? date('Y-m-d'), 'Checklist date', true);
                $odometer = max(0, (int)($_POST['odometer'] ?? 0));
                $tyresOk = isset($_POST['tyres_ok']) ? 1 : 0;
                $lightsOk = isset($_POST['lights_ok']) ? 1 : 0;
                $brakesOk = isset($_POST['brakes_ok']) ? 1 : 0;
                $fluidsOk = isset($_POST['fluids_ok']) ? 1 : 0;
                $overallStatus = (string)($_POST['overall_status'] ?? 'fit');
                $defects = trim((string)($_POST['defects'] ?? ''));
                $actionTaken = trim((string)($_POST['action_taken'] ?? ''));

                if ($vehicleId <= 0) {
                    throw new RuntimeException('Vehicle is required for a pre-use check.');
                }
                if (!tm_record_exists($db, 'transport_vehicles', $vehicleId)) {
                    throw new RuntimeException('Select a valid vehicle for the pre-use check.');
                }
                if ($instructorId !== null && !tm_record_exists($db, 'transport_instructors', $instructorId)) {
                    throw new RuntimeException('Select a valid instructor for the pre-use check.');
                }
                if (!in_array($overallStatus, ['fit','defect_reported','unfit'], true)) {
                    $overallStatus = 'fit';
                }
                if ($overallStatus !== 'fit' && $defects === '') {
                    throw new RuntimeException('Defect details are required when a vehicle is not marked fit.');
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_preuse_checks (vehicle_id, instructor_id, checklist_date, odometer, tyres_ok, lights_ok, brakes_ok, fluids_ok, overall_status, defects, action_taken) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iisiiiiisss', $vehicleId, $instructorId, $checkDate, $odometer, $tyresOk, $lightsOk, $brakesOk, $fluidsOk, $overallStatus, $defects, $actionTaken);
                tm_execute($stmt, 'Unable to record the pre-use inspection.');
                $stmt->close();

                if ($overallStatus !== 'fit') {
                    $stmt = tm_prepare($db, "UPDATE transport_vehicles SET status = 'maintenance' WHERE id = ?");
                    $stmt->bind_param('i', $vehicleId);
                    tm_execute($stmt, 'Unable to update vehicle status after defect reporting.');
                    $stmt->close();
                }

                $message = 'Pre-use inspection recorded.';
                $messageType = 'success';
            }

            if ($action === 'update_client') {
                $id = tm_post_id();
                $name = tm_validate_max_length((string)($_POST['client_name'] ?? ''), 180, 'Corporate client name');
                $contact = tm_validate_max_length((string)($_POST['contact_person'] ?? ''), 140, 'Contact person');
                $phone = tm_validate_phone((string)($_POST['phone'] ?? ''), 'Client phone');
                $email = tm_validate_email((string)($_POST['email'] ?? ''), 'Client email');
                $address = tm_validate_max_length((string)($_POST['billing_address'] ?? ''), 255, 'Billing address');
                $status = (string)($_POST['client_status'] ?? 'active');

                if ($id <= 0 || !tm_record_exists($db, 'transport_corporate_clients', $id)) {
                    throw new RuntimeException('Select a valid corporate client to update.');
                }
                if ($name === '') {
                    throw new RuntimeException('Corporate client name is required.');
                }
                if (mb_strlen($email) > 140) {
                    throw new RuntimeException('Client email is too long.');
                }
                if (!in_array($status, ['active','inactive'], true)) {
                    $status = 'active';
                }

                $stmt = tm_prepare($db, "UPDATE transport_corporate_clients SET client_name = ?, contact_person = ?, phone = ?, email = ?, billing_address = ?, status = ? WHERE id = ?");
                $stmt->bind_param('ssssssi', $name, $contact, $phone, $email, $address, $status, $id);
                tm_execute($stmt, 'Unable to update the corporate client.');
                $stmt->close();
                $message = 'Corporate client updated.';
                $messageType = 'success';
            }

            if ($action === 'delete_client') {
                $id = tm_post_id();
                if ($id <= 0 || !tm_record_exists($db, 'transport_corporate_clients', $id)) {
                    throw new RuntimeException('Select a valid corporate client to remove.');
                }
                if (tm_count_where_id($db, 'transport_enrollments', 'corporate_client_id', $id) > 0) {
                    $stmt = tm_prepare($db, "UPDATE transport_corporate_clients SET status = 'inactive' WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    tm_execute($stmt, 'Unable to deactivate the corporate client.');
                    $stmt->close();
                    $message = 'Corporate client has enrollments, so it was deactivated instead of deleted.';
                } else {
                    $stmt = tm_prepare($db, "DELETE FROM transport_corporate_clients WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    tm_execute($stmt, 'Unable to delete the corporate client.');
                    $stmt->close();
                    $message = 'Corporate client deleted.';
                }
                $messageType = 'success';
            }

            if ($action === 'update_vehicle') {
                $id = tm_post_id();
                $assetTag = trim((string)($_POST['asset_tag'] ?? ''));
                $registrationNo = strtoupper(trim((string)($_POST['registration_no'] ?? '')));
                $vehicleType = (string)($_POST['vehicle_type'] ?? 'other');
                $makeModel = trim((string)($_POST['make_model'] ?? ''));
                $supportedClass = trim((string)($_POST['supported_license_class'] ?? ''));
                $mileage = max(0, (int)($_POST['current_mileage'] ?? 0));
                $status = (string)($_POST['vehicle_status'] ?? 'available');
                $fitnessExpiry = tm_normalize_date($_POST['fitness_expiry'] ?? '', 'Fitness expiry');
                $insuranceExpiry = tm_normalize_date($_POST['insurance_expiry'] ?? '', 'Insurance expiry');
                $nextServiceDue = tm_normalize_date($_POST['next_service_due'] ?? '', 'Next service date');
                $notes = trim((string)($_POST['vehicle_notes'] ?? ''));
                $allowedTypes = ['light_vehicle','rigid_truck','articulated_truck','coach','motorcycle','forklift','simulator','trailer','other'];

                if ($id <= 0 || !tm_record_exists($db, 'transport_vehicles', $id)) {
                    throw new RuntimeException('Select a valid fleet record to update.');
                }
                if ($registrationNo === '' || !preg_match('/^[A-Z0-9 -]{2,40}$/', $registrationNo)) {
                    throw new RuntimeException('A valid registration number is required.');
                }
                if (!in_array($vehicleType, $allowedTypes, true)) {
                    $vehicleType = 'other';
                }
                if (!in_array($status, ['available','assigned','maintenance','unavailable'], true)) {
                    $status = 'available';
                }

                $stmt = tm_prepare($db, "UPDATE transport_vehicles SET asset_tag = ?, registration_no = ?, vehicle_type = ?, make_model = ?, supported_license_class = ?, current_mileage = ?, status = ?, fitness_expiry = ?, insurance_expiry = ?, next_service_due = ?, notes = ? WHERE id = ?");
                $stmt->bind_param('sssssisssssi', $assetTag, $registrationNo, $vehicleType, $makeModel, $supportedClass, $mileage, $status, $fitnessExpiry, $insuranceExpiry, $nextServiceDue, $notes, $id);
                tm_execute($stmt, 'Unable to update the fleet record. Check that the registration number is not already in use.');
                $stmt->close();
                $message = 'Fleet record updated.';
                $messageType = 'success';
            }

            if ($action === 'delete_vehicle') {
                $id = tm_post_id();
                if ($id <= 0 || !tm_record_exists($db, 'transport_vehicles', $id)) {
                    throw new RuntimeException('Select a valid fleet record to remove.');
                }
                $hasHistory = tm_count_where_id($db, 'transport_sessions', 'vehicle_id', $id)
                    + tm_count_where_id($db, 'transport_preuse_checks', 'vehicle_id', $id)
                    + tm_count_where_id($db, 'transport_telematics_logs', 'vehicle_id', $id)
                    + tm_count_where_id($db, 'transport_maintenance_logs', 'vehicle_id', $id)
                    + tm_count_where_id($db, 'transport_fuel_logs', 'vehicle_id', $id)
                    + tm_count_where_id($db, 'transport_route_plans', 'vehicle_id', $id)
                    + tm_count_where_id($db, 'transport_incident_reports', 'vehicle_id', $id)
                    + tm_count_where_id($db, 'transport_parts_inventory', 'vehicle_id', $id);
                if ($hasHistory > 0) {
                    $stmt = tm_prepare($db, "UPDATE transport_vehicles SET status = 'unavailable' WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    tm_execute($stmt, 'Unable to deactivate the fleet record.');
                    $stmt->close();
                    $message = 'Vehicle has operational history, so it was marked unavailable.';
                } else {
                    $stmt = tm_prepare($db, "DELETE FROM transport_vehicles WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    tm_execute($stmt, 'Unable to delete the fleet record.');
                    $stmt->close();
                    $message = 'Fleet record deleted.';
                }
                $messageType = 'success';
            }

            if ($action === 'add_telematics_log') {
                $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
                $deviceId = trim((string)($_POST['device_identifier'] ?? ''));
                $recordedAt = tm_normalize_datetime($_POST['recorded_at'] ?? '', 'Telemetry time', true);
                $location = trim((string)($_POST['location'] ?? ''));
                $speed = ($_POST['speed_kph'] ?? '') === '' ? null : max(0, (float)$_POST['speed_kph']);
                $rpm = ($_POST['engine_rpm'] ?? '') === '' ? null : max(0, (int)$_POST['engine_rpm']);
                $fuelLevel = ($_POST['fuel_level'] ?? '') === '' ? null : max(0, min(100, (float)$_POST['fuel_level']));
                $fuelConsumption = ($_POST['fuel_consumption'] ?? '') === '' ? null : max(0, (float)$_POST['fuel_consumption']);
                $events = trim((string)($_POST['behavior_events'] ?? ''));

                if ($vehicleId <= 0 || !tm_record_exists($db, 'transport_vehicles', $vehicleId)) {
                    throw new RuntimeException('Select a valid vehicle for telemetry.');
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_telematics_logs (vehicle_id, device_identifier, recorded_at, location, speed_kph, engine_rpm, fuel_level, fuel_consumption, behavior_events) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('isssdidds', $vehicleId, $deviceId, $recordedAt, $location, $speed, $rpm, $fuelLevel, $fuelConsumption, $events);
                tm_execute($stmt, 'Unable to save telemetry data.');
                $stmt->close();
                $message = 'Telemetry log recorded.';
                $messageType = 'success';
            }

            if ($action === 'add_maintenance_log') {
                $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
                $serviceDate = tm_normalize_date($_POST['service_date'] ?? date('Y-m-d'), 'Service date', true);
                $nextServiceDate = tm_normalize_date($_POST['next_service_date'] ?? '', 'Next service date');
                $tasksDue = trim((string)($_POST['tasks_due'] ?? ''));
                $completedTasks = trim((string)($_POST['completed_tasks'] ?? ''));
                $status = (string)($_POST['maintenance_status'] ?? 'scheduled');
                $notes = trim((string)($_POST['maintenance_notes'] ?? ''));
                $cost = ($_POST['maintenance_cost'] ?? '') === '' ? null : max(0, (float)$_POST['maintenance_cost']);

                if ($vehicleId <= 0 || !tm_record_exists($db, 'transport_vehicles', $vehicleId)) {
                    throw new RuntimeException('Select a valid vehicle for maintenance.');
                }
                if (!in_array($status, ['scheduled','completed','overdue','cancelled'], true)) {
                    $status = 'scheduled';
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_maintenance_logs (vehicle_id, service_date, next_service_date, tasks_due, completed_tasks, status, notes, cost) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param('issssssd', $vehicleId, $serviceDate, $nextServiceDate, $tasksDue, $completedTasks, $status, $notes, $cost);
                tm_execute($stmt, 'Unable to save maintenance log.');
                $stmt->close();

                if ($status === 'completed') {
                    $stmt = tm_prepare($db, "UPDATE transport_vehicles SET last_service_date = ?, next_service_due = ?, status = 'available' WHERE id = ?");
                    $stmt->bind_param('ssi', $serviceDate, $nextServiceDate, $vehicleId);
                    tm_execute($stmt, 'Unable to update vehicle service dates.');
                    $stmt->close();
                }

                $message = 'Maintenance log recorded.';
                $messageType = 'success';
            }

            if ($action === 'add_fuel_log') {
                $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
                $fuelDate = tm_normalize_date($_POST['fuel_date'] ?? date('Y-m-d'), 'Fuel date', true);
                $energyType = (string)($_POST['energy_type'] ?? 'fuel');
                $levelPercent = ($_POST['level_percent'] ?? '') === '' ? null : max(0, min(100, (float)$_POST['level_percent']));
                $amountAdded = max(0, (float)($_POST['amount_added'] ?? 0));
                $consumption = ($_POST['consumption'] ?? '') === '' ? null : max(0, (float)$_POST['consumption']);
                $odometer = ($_POST['odometer'] ?? '') === '' ? null : max(0, (int)$_POST['odometer']);
                $notes = trim((string)($_POST['fuel_notes'] ?? ''));
                $unitCost = ($_POST['unit_cost'] ?? '') === '' ? null : max(0, (float)$_POST['unit_cost']);
                $totalCost = ($_POST['total_cost'] ?? '') === '' ? null : max(0, (float)$_POST['total_cost']);
                if ($totalCost === null && $unitCost !== null && $amountAdded > 0) {
                    $totalCost = round($unitCost * $amountAdded, 2);
                }

                if ($vehicleId <= 0 || !tm_record_exists($db, 'transport_vehicles', $vehicleId)) {
                    throw new RuntimeException('Select a valid vehicle for fuel or battery data.');
                }
                if (!in_array($energyType, ['fuel','battery'], true)) {
                    $energyType = 'fuel';
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_fuel_logs (vehicle_id, fuel_date, energy_type, level_percent, amount_added, consumption, odometer, notes, unit_cost, total_cost) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('issdddisdd', $vehicleId, $fuelDate, $energyType, $levelPercent, $amountAdded, $consumption, $odometer, $notes, $unitCost, $totalCost);
                tm_execute($stmt, 'Unable to save fuel or battery data.');
                $stmt->close();

                if ($odometer !== null) {
                    $stmt = tm_prepare($db, "UPDATE transport_vehicles SET current_mileage = GREATEST(current_mileage, ?) WHERE id = ?");
                    $stmt->bind_param('ii', $odometer, $vehicleId);
                    tm_execute($stmt, 'Unable to update vehicle odometer.');
                    $stmt->close();
                }

                $message = 'Fuel or battery log recorded.';
                $messageType = 'success';
            }

            if ($action === 'add_route_plan') {
                $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
                $plannedDate = tm_normalize_date($_POST['planned_date'] ?? date('Y-m-d'), 'Planned date', true);
                $origin = trim((string)($_POST['origin'] ?? ''));
                $destination = trim((string)($_POST['destination'] ?? ''));
                $stops = trim((string)($_POST['stops'] ?? ''));
                $optimizedRoute = trim((string)($_POST['optimized_route'] ?? ''));
                $status = (string)($_POST['route_status'] ?? 'planned');

                if ($vehicleId <= 0 || !tm_record_exists($db, 'transport_vehicles', $vehicleId)) {
                    throw new RuntimeException('Select a valid vehicle for the route plan.');
                }
                if ($origin === '' || $destination === '') {
                    throw new RuntimeException('Origin and destination are required for route planning.');
                }
                if (!in_array($status, ['planned','in_progress','completed','cancelled'], true)) {
                    $status = 'planned';
                }
                if ($optimizedRoute === '') {
                    $optimizedRoute = $origin . ($stops !== '' ? ' -> ' . $stops : '') . ' -> ' . $destination;
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_route_plans (vehicle_id, planned_date, origin, destination, stops, optimized_route, status) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param('issssss', $vehicleId, $plannedDate, $origin, $destination, $stops, $optimizedRoute, $status);
                tm_execute($stmt, 'Unable to save route plan.');
                $stmt->close();
                $message = 'Route plan created.';
                $messageType = 'success';
            }

            if ($action === 'update_route_status') {
                $id = tm_post_id();
                $status = (string)($_POST['route_status'] ?? 'planned');
                if ($id <= 0 || !tm_record_exists($db, 'transport_route_plans', $id)) {
                    throw new RuntimeException('Select a valid route plan to update.');
                }
                if (!in_array($status, ['planned','in_progress','completed','cancelled'], true)) {
                    $status = 'planned';
                }
                $stmt = tm_prepare($db, "UPDATE transport_route_plans SET status = ? WHERE id = ?");
                $stmt->bind_param('si', $status, $id);
                tm_execute($stmt, 'Unable to update route status.');
                $stmt->close();
                $message = 'Route status updated.';
                $messageType = 'success';
            }

            if ($action === 'add_incident_report') {
                $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
                $instructorIdRaw = (int)($_POST['instructor_id'] ?? 0);
                $instructorId = $instructorIdRaw > 0 ? $instructorIdRaw : null;
                $incidentTime = tm_normalize_datetime($_POST['incident_time'] ?? '', 'Incident time', true);
                $location = trim((string)($_POST['incident_location'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $snapshot = trim((string)($_POST['telematics_snapshot'] ?? ''));
                $status = (string)($_POST['incident_status'] ?? 'open');

                if ($vehicleId <= 0 || !tm_record_exists($db, 'transport_vehicles', $vehicleId)) {
                    throw new RuntimeException('Select a valid vehicle for the incident.');
                }
                if ($instructorId !== null && !tm_record_exists($db, 'transport_instructors', $instructorId)) {
                    throw new RuntimeException('Select a valid instructor for the incident.');
                }
                if ($description === '') {
                    throw new RuntimeException('Incident description is required.');
                }
                if (!in_array($status, ['open','in_review','resolved'], true)) {
                    $status = 'open';
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_incident_reports (vehicle_id, instructor_id, incident_time, location, description, telematics_snapshot, status) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param('iisssss', $vehicleId, $instructorId, $incidentTime, $location, $description, $snapshot, $status);
                tm_execute($stmt, 'Unable to save incident report.');
                $stmt->close();

                if ($status !== 'resolved') {
                    $stmt = tm_prepare($db, "UPDATE transport_vehicles SET status = 'maintenance' WHERE id = ?");
                    $stmt->bind_param('i', $vehicleId);
                    tm_execute($stmt, 'Unable to update vehicle status after incident reporting.');
                    $stmt->close();
                }

                $message = 'Incident report recorded.';
                $messageType = 'success';
            }

            if ($action === 'update_incident_status') {
                $id = tm_post_id();
                $status = (string)($_POST['incident_status'] ?? 'open');
                if ($id <= 0 || !tm_record_exists($db, 'transport_incident_reports', $id)) {
                    throw new RuntimeException('Select a valid incident report to update.');
                }
                if (!in_array($status, ['open','in_review','resolved'], true)) {
                    $status = 'open';
                }
                $stmt = tm_prepare($db, "UPDATE transport_incident_reports SET status = ? WHERE id = ?");
                $stmt->bind_param('si', $status, $id);
                tm_execute($stmt, 'Unable to update incident status.');
                $stmt->close();
                $message = 'Incident status updated.';
                $messageType = 'success';
            }

            if ($action === 'add_part') {
                $vehicleIdRaw = (int)($_POST['vehicle_id'] ?? 0);
                $vehicleId = $vehicleIdRaw > 0 ? $vehicleIdRaw : null;
                $partName = trim((string)($_POST['part_name'] ?? ''));
                $stockLevel = max(0, (int)($_POST['stock_level'] ?? 0));
                $reorderLevel = max(0, (int)($_POST['reorder_level'] ?? 0));
                $notes = trim((string)($_POST['part_notes'] ?? ''));
                $status = $stockLevel <= $reorderLevel ? 'reorder' : ($vehicleId !== null ? 'assigned' : 'available');

                if ($partName === '') {
                    throw new RuntimeException('Part name is required.');
                }
                if ($vehicleId !== null && !tm_record_exists($db, 'transport_vehicles', $vehicleId)) {
                    throw new RuntimeException('Select a valid vehicle for the part.');
                }

                $stmt = tm_prepare($db, "INSERT INTO transport_parts_inventory (vehicle_id, part_name, stock_level, reorder_level, status, notes) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param('isiiss', $vehicleId, $partName, $stockLevel, $reorderLevel, $status, $notes);
                tm_execute($stmt, 'Unable to save parts inventory.');
                $stmt->close();
                $message = 'Part added to inventory.';
                $messageType = 'success';
            }

            if ($action === 'update_part') {
                $id = tm_post_id();
                $vehicleIdRaw = (int)($_POST['vehicle_id'] ?? 0);
                $vehicleId = $vehicleIdRaw > 0 ? $vehicleIdRaw : null;
                $stockLevel = max(0, (int)($_POST['stock_level'] ?? 0));
                $reorderLevel = max(0, (int)($_POST['reorder_level'] ?? 0));
                $status = (string)($_POST['part_status'] ?? 'available');
                if ($id <= 0 || !tm_record_exists($db, 'transport_parts_inventory', $id)) {
                    throw new RuntimeException('Select a valid part to update.');
                }
                if ($vehicleId !== null && !tm_record_exists($db, 'transport_vehicles', $vehicleId)) {
                    throw new RuntimeException('Select a valid vehicle for the part.');
                }
                if ($stockLevel <= $reorderLevel) {
                    $status = 'reorder';
                } elseif (!in_array($status, ['available','assigned','reorder','retired'], true)) {
                    $status = 'available';
                }
                $stmt = tm_prepare($db, "UPDATE transport_parts_inventory SET vehicle_id = ?, stock_level = ?, reorder_level = ?, status = ? WHERE id = ?");
                $stmt->bind_param('iiisi', $vehicleId, $stockLevel, $reorderLevel, $status, $id);
                tm_execute($stmt, 'Unable to update part inventory.');
                $stmt->close();
                $message = 'Part inventory updated.';
                $messageType = 'success';
            }

            if ($action === 'use_part') {
                $id = tm_post_id();
                $vehicleIdRaw = (int)($_POST['vehicle_id'] ?? 0);
                $vehicleId = $vehicleIdRaw > 0 ? $vehicleIdRaw : null;
                if ($id <= 0 || !tm_record_exists($db, 'transport_parts_inventory', $id)) {
                    throw new RuntimeException('Select a valid part to use.');
                }
                if ($vehicleId !== null && !tm_record_exists($db, 'transport_vehicles', $vehicleId)) {
                    throw new RuntimeException('Select a valid vehicle for the part.');
                }
                $stmt = tm_prepare($db, "SELECT stock_level, reorder_level FROM transport_parts_inventory WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $id);
                tm_execute($stmt, 'Unable to read part stock.');
                $part = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $stockLevel = (int)($part['stock_level'] ?? 0);
                $reorderLevel = (int)($part['reorder_level'] ?? 0);
                if ($stockLevel <= 0) {
                    throw new RuntimeException('This part is out of stock.');
                }
                $newStock = $stockLevel - 1;
                $status = $newStock <= $reorderLevel ? 'reorder' : ($vehicleId !== null ? 'assigned' : 'available');
                $stmt = tm_prepare($db, "UPDATE transport_parts_inventory SET vehicle_id = COALESCE(?, vehicle_id), stock_level = ?, status = ? WHERE id = ?");
                $stmt->bind_param('iisi', $vehicleId, $newStock, $status, $id);
                tm_execute($stmt, 'Unable to use the selected part.');
                $stmt->close();
                $message = 'Part issued and stock updated.';
                $messageType = 'success';
            }

            if ($action === 'update_instructor') {
                $id = tm_post_id();
                $staffId = trim((string)($_POST['staff_id'] ?? ''));
                $staffRecord = tm_fetch_staff_by_staff_id($db, $staffId);
                if (!$staffRecord) {
                    throw new RuntimeException('Select a valid staff ID from the admin staff database.');
                }
                if (!tm_staff_record_is_active($staffRecord)) {
                    throw new RuntimeException('Select an active staff member for the instructor profile.');
                }
                $fullName = tm_person_full_name((string)$staffRecord['Fname'], (string)$staffRecord['Lname'], $staffId);
                $licenseClasses = trim((string)($_POST['license_classes'] ?? ''));
                $rtsaNo = trim((string)($_POST['rtsa_license_no'] ?? ''));
                $rtsaExpiry = tm_normalize_date($_POST['rtsa_expiry'] ?? '', 'RTSA expiry');
                $tevetaNo = trim((string)($_POST['teveta_accreditation_no'] ?? ''));
                $tevetaExpiry = tm_normalize_date($_POST['teveta_expiry'] ?? '', 'TEVETA expiry');
                $zciltNo = trim((string)($_POST['zcilt_member_no'] ?? ''));
                $status = (string)($_POST['instructor_status'] ?? 'active');
                $notes = trim((string)($_POST['instructor_notes'] ?? ''));

                if ($id <= 0 || !tm_record_exists($db, 'transport_instructors', $id)) {
                    throw new RuntimeException('Select a valid instructor to update.');
                }
                if ($staffId === '') {
                    throw new RuntimeException('Instructor staff ID is required.');
                }
                if (tm_staff_has_instructor_profile($db, $staffId, $id)) {
                    throw new RuntimeException('This staff member already has another transport instructor profile.');
                }
                if (!in_array($status, ['active','inactive','on_leave'], true)) {
                    $status = 'active';
                }

                $stmt = tm_prepare($db, "UPDATE transport_instructors SET staff_id = ?, full_name = ?, license_classes = ?, rtsa_license_no = ?, rtsa_expiry = ?, teveta_accreditation_no = ?, teveta_expiry = ?, zcilt_member_no = ?, status = ?, notes = ? WHERE id = ?");
                $stmt->bind_param('ssssssssssi', $staffId, $fullName, $licenseClasses, $rtsaNo, $rtsaExpiry, $tevetaNo, $tevetaExpiry, $zciltNo, $status, $notes, $id);
                tm_execute($stmt, 'Unable to update the instructor profile.');
                $stmt->close();
                $message = 'Instructor profile updated.';
                $messageType = 'success';
            }

            if ($action === 'delete_instructor') {
                $id = tm_post_id();
                if ($id <= 0 || !tm_record_exists($db, 'transport_instructors', $id)) {
                    throw new RuntimeException('Select a valid instructor to remove.');
                }
                $hasHistory = tm_count_where_id($db, 'transport_sessions', 'instructor_id', $id)
                    + tm_count_where_id($db, 'transport_preuse_checks', 'instructor_id', $id)
                    + tm_count_where_id($db, 'transport_incident_reports', 'instructor_id', $id);
                if ($hasHistory > 0) {
                    $stmt = tm_prepare($db, "UPDATE transport_instructors SET status = 'inactive' WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    tm_execute($stmt, 'Unable to deactivate the instructor.');
                    $stmt->close();
                    $message = 'Instructor has operational history, so the profile was deactivated.';
                } else {
                    $stmt = tm_prepare($db, "DELETE FROM transport_instructors WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    tm_execute($stmt, 'Unable to delete the instructor.');
                    $stmt->close();
                    $message = 'Instructor profile deleted.';
                }
                $messageType = 'success';
            }

            if ($action === 'update_cohort') {
                $id = tm_post_id();
                $cohortName = trim((string)($_POST['cohort_name'] ?? ''));
                $intakeMonth = tm_normalize_month($_POST['intake_month'] ?? '', 'Intake month');
                $startDate = tm_normalize_date($_POST['start_date'] ?? '', 'Start date', true);
                $endDate = tm_normalize_date($_POST['end_date'] ?? '', 'End date');
                $capacity = max(1, (int)($_POST['capacity'] ?? 1));
                $status = (string)($_POST['cohort_status'] ?? 'planning');
                $notes = trim((string)($_POST['cohort_notes'] ?? ''));

                if ($id <= 0 || !tm_record_exists($db, 'transport_cohorts', $id)) {
                    throw new RuntimeException('Select a valid cohort to update.');
                }
                if ($cohortName === '') {
                    throw new RuntimeException('Cohort name is required.');
                }
                if ($endDate !== null && strtotime($endDate) < strtotime($startDate)) {
                    throw new RuntimeException('End date cannot be before the start date.');
                }
                if (!in_array($status, ['planning','open','in_progress','completed','cancelled'], true)) {
                    $status = 'planning';
                }

                $stmt = tm_prepare($db, "UPDATE transport_cohorts SET cohort_name = ?, intake_month = ?, start_date = ?, end_date = ?, capacity = ?, status = ?, notes = ? WHERE id = ?");
                $stmt->bind_param('ssssissi', $cohortName, $intakeMonth, $startDate, $endDate, $capacity, $status, $notes, $id);
                tm_execute($stmt, 'Unable to update the transport cohort.');
                $stmt->close();
                $message = 'Transport cohort updated.';
                $messageType = 'success';
            }

            if ($action === 'delete_cohort') {
                $id = tm_post_id();
                if ($id <= 0 || !tm_record_exists($db, 'transport_cohorts', $id)) {
                    throw new RuntimeException('Select a valid cohort to remove.');
                }
                $hasHistory = tm_count_where_id($db, 'transport_enrollments', 'cohort_id', $id) + tm_count_where_id($db, 'transport_sessions', 'cohort_id', $id);
                if ($hasHistory > 0) {
                    $stmt = tm_prepare($db, "UPDATE transport_cohorts SET status = 'cancelled' WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    tm_execute($stmt, 'Unable to cancel the cohort.');
                    $stmt->close();
                    $message = 'Cohort has enrollments or sessions, so it was cancelled instead of deleted.';
                } else {
                    $stmt = tm_prepare($db, "DELETE FROM transport_cohorts WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    tm_execute($stmt, 'Unable to delete the cohort.');
                    $stmt->close();
                    $message = 'Transport cohort deleted.';
                }
                $messageType = 'success';
            }

            if ($action === 'update_trainee') {
                $traineeId = tm_post_id('trainee_id');
                $enrollmentId = tm_post_id('enrollment_id');
                $studentId = trim((string)($_POST['student_id'] ?? ''));
                $studentRecord = tm_fetch_trainee_candidate_by_sid($db, $studentId);
                if (!$studentRecord) {
                    throw new RuntimeException('Select a valid trainee from short-course, ITC programme, or transport enrolment records.');
                }
                $firstName = trim((string)$studentRecord['Fname']);
                $lastName = trim((string)$studentRecord['Lname']);
                $phone = tm_validate_phone((string)($studentRecord['mobile'] ?? ''), 'Trainee phone');
                $email = tm_validate_email((string)($studentRecord['email'] ?? ''), 'Trainee email');
                $licenseClass = trim((string)($_POST['existing_license_class'] ?? ''));
                $medical = (string)($_POST['medical_clearance_status'] ?? 'pending');
                $emergency = tm_student_emergency_contact($studentRecord);
                $employer = trim((string)($_POST['employer_name'] ?? ''));
                $status = (string)($_POST['enrollment_status'] ?? 'enrolled');
                $notes = trim((string)($_POST['enrollment_notes'] ?? ''));

                if ($traineeId <= 0 || $enrollmentId <= 0 || !tm_record_exists($db, 'transport_trainees', $traineeId) || !tm_record_exists($db, 'transport_enrollments', $enrollmentId)) {
                    throw new RuntimeException('Select a valid trainee enrollment to update.');
                }
                $enrollmentIdentity = tm_fetch_enrollment_identity($db, $enrollmentId, $traineeId);
                if (!$enrollmentIdentity) {
                    throw new RuntimeException('The selected enrollment does not belong to this trainee.');
                }
                if (strcasecmp((string)$enrollmentIdentity['student_id'], $studentId) !== 0) {
                    throw new RuntimeException('Trainee identity cannot be changed from the enrollment editor.');
                }
                if (tm_student_has_cohort_enrollment($db, $studentId, (int)$enrollmentIdentity['cohort_id'], $enrollmentId)) {
                    throw new RuntimeException('This trainee already has another enrollment for the same cohort.');
                }
                if ($studentId === '') {
                    throw new RuntimeException('Trainee student ID is required.');
                }
                if (!in_array($medical, ['pending','cleared','not_required','failed'], true)) {
                    $medical = 'pending';
                }
                if (!in_array($status, ['enrolled','active','completed','withdrawn','failed'], true)) {
                    $status = 'enrolled';
                }
                // BR001: a trainee cannot be marked into/through training unless they
                // have been booked (which itself requires verified payment).
                if (in_array($status, ['active','completed'], true)) {
                    $enrCheck = tpay_enrollment($db, $enrollmentId);
                    if (($enrCheck['booking_status'] ?? '') !== 'booked') {
                        throw new RuntimeException('This trainee is not booked yet. Verify payment and book them on the Payments & Booking screen before setting training status to "' . $status . '".');
                    }
                }

                $db->begin_transaction();
                $transportTransactionOpen = true;
                $stmt = tm_prepare($db, "UPDATE transport_trainees SET student_id = ?, first_name = ?, last_name = ?, phone = ?, email = ?, existing_license_class = ?, medical_clearance_status = ?, emergency_contact = ?, employer_name = ? WHERE id = ?");
                $stmt->bind_param('sssssssssi', $studentId, $firstName, $lastName, $phone, $email, $licenseClass, $medical, $emergency, $employer, $traineeId);
                tm_execute($stmt, 'Unable to update trainee details.');
                $stmt->close();
                // amount_paid is intentionally NOT updated here — it is maintained
                // solely from accounts-verified payments (BR001).
                // fee_amount is NOT editable here — it was assessed from the approved fee
                // schedule at enrolment (BR008). Only training status + notes change.
                $stmt = tm_prepare($db, "UPDATE transport_enrollments SET status = ?, notes = ? WHERE id = ? AND trainee_id = ?");
                $stmt->bind_param('ssii', $status, $notes, $enrollmentId, $traineeId);
                tm_execute($stmt, 'Unable to update trainee enrollment.');
                $stmt->close();
                $db->commit();
                $transportTransactionOpen = false;
                $message = 'Trainee enrollment updated.';
                $messageType = 'success';
            }

            if ($action === 'delete_trainee') {
                $enrollmentId = tm_post_id('enrollment_id');
                if ($enrollmentId <= 0 || !tm_record_exists($db, 'transport_enrollments', $enrollmentId)) {
                    throw new RuntimeException('Select a valid trainee enrollment to withdraw.');
                }
                $stmt = tm_prepare($db, "UPDATE transport_enrollments SET status = 'withdrawn' WHERE id = ?");
                $stmt->bind_param('i', $enrollmentId);
                tm_execute($stmt, 'Unable to withdraw the trainee enrollment.');
                $stmt->close();
                $message = 'Trainee enrollment withdrawn.';
                $messageType = 'success';
            }

            // ── Course Fees & Requirements (§8 / §7 admin) ──────────────────────
            // Remember which program is being edited so the PRG redirect (which drops
            // the query string) returns to the same course.
            if (in_array($action, ['add_course_fee','toggle_course_mode','add_additional_fee','toggle_additional_fee','update_program_requirements'], true)) {
                $feesProgPid = (int)($_POST['program_id'] ?? 0);
                if ($feesProgPid > 0) {
                    $_SESSION['transport_fees_program'] = $feesProgPid;
                }
            }
            if ($action === 'add_course_fee') {
                if (!canAccessFinance() && !isSystemsAdmin()) {
                    throw new RuntimeException('Only an accounts officer can set course fees (BR008/BR019).');
                }
                $programId = (int)($_POST['program_id'] ?? 0);
                $feeYear = (int)($_POST['fee_year'] ?? date('Y'));
                $modeRaw = strtolower(trim((string)($_POST['training_mode'] ?? '')));
                $amount = round(max(0, (float)($_POST['amount'] ?? 0)), 2);
                $isAvail = !empty($_POST['is_available']) ? 1 : 0;
                if (!tm_record_exists($db, 'transport_programs', $programId)) {
                    throw new RuntimeException('Select a valid course.');
                }
                if ($modeRaw === '' || strlen($modeRaw) > 30) {
                    throw new RuntimeException('Enter a valid training mode (max 30 characters).');
                }
                if ($feeYear < 2000 || $feeYear > 2100) {
                    throw new RuntimeException('Enter a valid fee year.');
                }
                // Versioned by (program, year, mode); re-saving the same key updates it,
                // older years are preserved (BR016/BR017).
                $stmt = tm_prepare($db, "INSERT INTO transport_course_fees (program_id, fee_year, training_mode, amount, currency, is_available)
                    VALUES (?,?,?,?, 'ZMW', ?)
                    ON DUPLICATE KEY UPDATE amount = VALUES(amount), is_available = VALUES(is_available)");
                $stmt->bind_param('iisdi', $programId, $feeYear, $modeRaw, $amount, $isAvail);
                tm_execute($stmt, 'Unable to save the course fee.');
                $stmt->close();
                $message = 'Course fee saved for ' . $modeRaw . ' (' . $feeYear . ').';
                $messageType = 'success';
            }

            if ($action === 'toggle_course_mode') {
                if (!canAccessFinance() && !isSystemsAdmin()) {
                    throw new RuntimeException('Only an accounts officer can change course fees.');
                }
                $feeId = (int)($_POST['fee_id'] ?? 0);
                $isAvail = !empty($_POST['is_available']) ? 1 : 0;
                if ($feeId <= 0 || !tm_record_exists($db, 'transport_course_fees', $feeId)) {
                    throw new RuntimeException('Select a valid fee row.');
                }
                $stmt = tm_prepare($db, "UPDATE transport_course_fees SET is_available = ? WHERE id = ?");
                $stmt->bind_param('ii', $isAvail, $feeId);
                tm_execute($stmt, 'Unable to update the fee availability.');
                $stmt->close();
                $message = $isAvail ? 'Training mode marked available.' : 'Training mode marked N/A (BR002).';
                $messageType = 'success';
            }

            if ($action === 'add_additional_fee') {
                if (!canAccessFinance() && !isSystemsAdmin()) {
                    throw new RuntimeException('Only an accounts officer can add fees.');
                }
                $programId = (int)($_POST['program_id'] ?? 0);
                $feeName = trim((string)($_POST['fee_name'] ?? ''));
                $amount = round(max(0, (float)($_POST['amount'] ?? 0)), 2);
                $isMandatory = !empty($_POST['is_mandatory']) ? 1 : 0;
                $category = (string)($_POST['fee_category'] ?? 'standard');
                if (!in_array($category, ['standard','rtsa','equipment','option'], true)) {
                    $category = 'standard';
                }
                if (!tm_record_exists($db, 'transport_programs', $programId)) {
                    throw new RuntimeException('Select a valid course.');
                }
                if ($feeName === '' || mb_strlen($feeName) > 120) {
                    throw new RuntimeException('Enter a fee name (max 120 characters).');
                }
                $stmt = tm_prepare($db, "INSERT INTO transport_additional_fees (program_id, fee_name, amount, currency, is_mandatory, fee_category, is_active)
                    VALUES (?,?,?, 'ZMW', ?, ?, 1)");
                $stmt->bind_param('isdis', $programId, $feeName, $amount, $isMandatory, $category);
                tm_execute($stmt, 'Unable to save the additional fee.');
                $stmt->close();
                $message = 'Additional fee added.';
                $messageType = 'success';
            }

            if ($action === 'toggle_additional_fee') {
                if (!canAccessFinance() && !isSystemsAdmin()) {
                    throw new RuntimeException('Only an accounts officer can change fees.');
                }
                $feeId = (int)($_POST['fee_id'] ?? 0);
                $isActive = !empty($_POST['is_active']) ? 1 : 0;
                if ($feeId <= 0 || !tm_record_exists($db, 'transport_additional_fees', $feeId)) {
                    throw new RuntimeException('Select a valid fee row.');
                }
                $stmt = tm_prepare($db, "UPDATE transport_additional_fees SET is_active = ? WHERE id = ?");
                $stmt->bind_param('ii', $isActive, $feeId);
                tm_execute($stmt, 'Unable to update the fee.');
                $stmt->close();
                $message = $isActive ? 'Additional fee re-activated.' : 'Additional fee retired (kept for history, BR016).';
                $messageType = 'success';
            }

            if ($action === 'update_program_requirements') {
                if (!canAccessTransport() && !canAccessAdmissions() && !isSystemsAdmin()) {
                    throw new RuntimeException('You are not authorised to edit course requirements.');
                }
                $programId = (int)($_POST['program_id'] ?? 0);
                if (!tm_record_exists($db, 'transport_programs', $programId)) {
                    throw new RuntimeException('Select a valid course.');
                }
                $minAgeRaw = trim((string)($_POST['minimum_age'] ?? ''));
                $minAge = ($minAgeRaw === '') ? null : max(0, min(120, (int)$minAgeRaw));
                $reqLicence = trim((string)($_POST['required_licence_class'] ?? ''));
                if (mb_strlen($reqLicence) > 120) {
                    throw new RuntimeException('Required licence class list is too long (max 120 characters).');
                }
                $reqNrc = !empty($_POST['requires_nrc']) ? 1 : 0;
                $reqG12 = !empty($_POST['requires_grade_12']) ? 1 : 0;
                $reqDl  = !empty($_POST['requires_driver_licence']) ? 1 : 0;
                $reqMed = !empty($_POST['requires_medical_certificate']) ? 1 : 0;
                $reqLicenceN = $reqLicence !== '' ? $reqLicence : null;
                $stmt = tm_prepare($db, "UPDATE transport_programs
                    SET minimum_age = ?, required_licence_class = ?, requires_nrc = ?, requires_grade_12 = ?, requires_driver_licence = ?, requires_medical_certificate = ?
                    WHERE id = ?");
                $stmt->bind_param('isiiiii', $minAge, $reqLicenceN, $reqNrc, $reqG12, $reqDl, $reqMed, $programId);
                tm_execute($stmt, 'Unable to update course requirements.');
                $stmt->close();
                $message = 'Course entry requirements updated.';
                $messageType = 'success';
            }

            if ($action === 'update_session') {
                $id = tm_post_id();
                $sessionDate = tm_normalize_date($_POST['session_date'] ?? '', 'Session date', true);
                $startTime = trim((string)($_POST['start_time'] ?? ''));
                $endTime = trim((string)($_POST['end_time'] ?? ''));
                $contactHours = (float)($_POST['contact_hours'] ?? 0);
                $location = trim((string)($_POST['location'] ?? ''));
                $status = (string)($_POST['session_status'] ?? 'scheduled');
                $notes = trim((string)($_POST['session_notes'] ?? ''));

                if ($id <= 0 || !tm_record_exists($db, 'transport_sessions', $id)) {
                    throw new RuntimeException('Select a valid transport session to update.');
                }
                if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime) || strtotime($endTime) <= strtotime($startTime)) {
                    throw new RuntimeException('Session start and end time must be valid.');
                }
                if ($contactHours <= 0) {
                    $contactHours = round((strtotime($endTime) - strtotime($startTime)) / 3600, 2);
                }
                if (!in_array($status, ['scheduled','completed','cancelled'], true)) {
                    $status = 'scheduled';
                }

                $stmt = tm_prepare($db, "SELECT cohort_id, instructor_id, vehicle_id, session_type FROM transport_sessions WHERE id = ?");
                $stmt->bind_param('i', $id);
                tm_execute($stmt, 'Unable to load session details.');
                $session = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $instructorId = (int)($session['instructor_id'] ?? 0);
                $vehicleId = isset($session['vehicle_id']) ? (int)$session['vehicle_id'] : 0;
                $cohortId = (int)($session['cohort_id'] ?? 0);
                $sessionType = (string)($session['session_type'] ?? 'practical');
                $sessionContext = tm_session_context($db, $cohortId, $instructorId, $vehicleId > 0 ? $vehicleId : null);
                tm_validate_session_rules($sessionContext, $sessionType, $sessionDate, $status, $contactHours, $startTime, $endTime);

                $stmt = tm_prepare($db, "SELECT COUNT(*) AS c FROM transport_sessions WHERE id <> ? AND instructor_id = ? AND session_date = ? AND status <> 'cancelled' AND start_time < ? AND end_time > ?");
                $stmt->bind_param('iisss', $id, $instructorId, $sessionDate, $endTime, $startTime);
                tm_execute($stmt, 'Unable to check instructor availability.');
                $conflict = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();
                if ($conflict > 0) {
                    throw new RuntimeException('Instructor conflict detected for the selected date and time.');
                }
                if ($vehicleId > 0) {
                    $stmt = tm_prepare($db, "SELECT COUNT(*) AS c FROM transport_sessions WHERE id <> ? AND vehicle_id = ? AND session_date = ? AND status <> 'cancelled' AND start_time < ? AND end_time > ?");
                    $stmt->bind_param('iisss', $id, $vehicleId, $sessionDate, $endTime, $startTime);
                    tm_execute($stmt, 'Unable to check vehicle availability.');
                    $conflict = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                    $stmt->close();
                    if ($conflict > 0) {
                        throw new RuntimeException('Vehicle conflict detected for the selected date and time.');
                    }
                }

                $stmt = tm_prepare($db, "UPDATE transport_sessions SET session_date = ?, start_time = ?, end_time = ?, contact_hours = ?, location = ?, status = ?, notes = ? WHERE id = ?");
                $stmt->bind_param('sssdsssi', $sessionDate, $startTime, $endTime, $contactHours, $location, $status, $notes, $id);
                tm_execute($stmt, 'Unable to update the transport session.');
                $stmt->close();
                $message = 'Transport session updated.';
                $messageType = 'success';
            }

            if ($action === 'delete_session') {
                $id = tm_post_id();
                if ($id <= 0 || !tm_record_exists($db, 'transport_sessions', $id)) {
                    throw new RuntimeException('Select a valid transport session to cancel.');
                }
                $stmt = tm_prepare($db, "UPDATE transport_sessions SET status = 'cancelled' WHERE id = ?");
                $stmt->bind_param('i', $id);
                tm_execute($stmt, 'Unable to cancel the transport session.');
                $stmt->close();
                $message = 'Transport session cancelled.';
                $messageType = 'success';
            }

            if ($action === 'update_preuse_check') {
                $id = tm_post_id();
                $checkDate = tm_normalize_date($_POST['checklist_date'] ?? '', 'Checklist date', true);
                $odometer = max(0, (int)($_POST['odometer'] ?? 0));
                $tyresOk = isset($_POST['tyres_ok']) ? 1 : 0;
                $lightsOk = isset($_POST['lights_ok']) ? 1 : 0;
                $brakesOk = isset($_POST['brakes_ok']) ? 1 : 0;
                $fluidsOk = isset($_POST['fluids_ok']) ? 1 : 0;
                $overallStatus = (string)($_POST['overall_status'] ?? 'fit');
                $defects = trim((string)($_POST['defects'] ?? ''));
                $actionTaken = trim((string)($_POST['action_taken'] ?? ''));

                if ($id <= 0 || !tm_record_exists($db, 'transport_preuse_checks', $id)) {
                    throw new RuntimeException('Select a valid pre-use check to update.');
                }
                if (!in_array($overallStatus, ['fit','defect_reported','unfit'], true)) {
                    $overallStatus = 'fit';
                }
                if ($overallStatus !== 'fit' && $defects === '') {
                    throw new RuntimeException('Defect details are required when a vehicle is not marked fit.');
                }

                $stmt = tm_prepare($db, "UPDATE transport_preuse_checks SET checklist_date = ?, odometer = ?, tyres_ok = ?, lights_ok = ?, brakes_ok = ?, fluids_ok = ?, overall_status = ?, defects = ?, action_taken = ? WHERE id = ?");
                $stmt->bind_param('siiiiisssi', $checkDate, $odometer, $tyresOk, $lightsOk, $brakesOk, $fluidsOk, $overallStatus, $defects, $actionTaken, $id);
                tm_execute($stmt, 'Unable to update the pre-use inspection.');
                $stmt->close();
                if ($overallStatus !== 'fit') {
                    $stmt = tm_prepare($db, "UPDATE transport_vehicles v INNER JOIN transport_preuse_checks pc ON pc.vehicle_id = v.id SET v.status = 'maintenance' WHERE pc.id = ?");
                    $stmt->bind_param('i', $id);
                    tm_execute($stmt, 'Unable to update vehicle status after defect reporting.');
                    $stmt->close();
                }
                $message = 'Pre-use inspection updated.';
                $messageType = 'success';
            }

            if ($action === 'delete_preuse_check') {
                $id = tm_post_id();
                if ($id <= 0 || !tm_record_exists($db, 'transport_preuse_checks', $id)) {
                    throw new RuntimeException('Select a valid pre-use check to delete.');
                }
                $stmt = tm_prepare($db, "DELETE FROM transport_preuse_checks WHERE id = ?");
                $stmt->bind_param('i', $id);
                tm_execute($stmt, 'Unable to delete the pre-use inspection.');
                $stmt->close();
                $message = 'Pre-use inspection deleted.';
                $messageType = 'success';
            }
            if ($action === 'submit_payment_proof') {
                $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
                $db->begin_transaction();
                $transportTransactionOpen = true;
                $res = tpay_submit_payment($db, $enrollmentId, $_POST, $_FILES['proof'] ?? null);
                $db->commit();
                $transportTransactionOpen = false;
                $message = $res['message'];
                $messageType = 'success';
            }

            if ($action === 'verify_payment') {
                $paymentId = (int)($_POST['payment_id'] ?? 0);
                $db->begin_transaction();
                $transportTransactionOpen = true;
                $res = tpay_verify_payment($db, $paymentId);
                // BR011: a receipt may be generated only after payment verification.
                // tinv_issue_receipt is a no-op unless the enrolment is now fully verified.
                $receiptNote = '';
                $vpStmt = tm_prepare($db, "SELECT enrollment_id FROM transport_payments WHERE id = ? LIMIT 1");
                $vpStmt->bind_param('i', $paymentId);
                tm_execute($vpStmt, 'Unable to resolve the payment enrolment.');
                $vpEnr = (int)($vpStmt->get_result()->fetch_assoc()['enrollment_id'] ?? 0);
                $vpStmt->close();
                if ($vpEnr > 0) {
                    tinv_ensure_invoice($db, $vpEnr);
                    $rc = tinv_issue_receipt($db, $vpEnr);
                    if (!empty($rc['issued']) && empty($rc['reused'])) {
                        $receiptNote = ' Receipt ' . $rc['receipt_number'] . ' generated.';
                    }
                }
                $db->commit();
                $transportTransactionOpen = false;
                $message = $res['message'] . $receiptNote;
                $messageType = 'success';
            }

            if ($action === 'reject_payment') {
                $paymentId = (int)($_POST['payment_id'] ?? 0);
                $reason = (string)($_POST['reason'] ?? '');
                $db->begin_transaction();
                $transportTransactionOpen = true;
                $res = tpay_reject_payment($db, $paymentId, $reason);
                $db->commit();
                $transportTransactionOpen = false;
                $message = $res['message'];
                $messageType = 'success';
            }

            if ($action === 'book_trainee') {
                $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
                $db->begin_transaction();
                $transportTransactionOpen = true;
                $res = tpay_book_trainee($db, $enrollmentId);
                $db->commit();
                $transportTransactionOpen = false;
                $message = $res['message'];
                $messageType = 'success';
            }

            if (!isset($actionTabs[$action])) {
                throw new RuntimeException('Choose a valid transport action.');
            }
            if ($messageType === 'success') {
                $_SESSION['transport_flash'] = ['message' => $message, 'type' => $messageType, 'tab' => $activeTab];
                tm_redirect_self();
            }
        } catch (Throwable $e) {
            if ($transportTransactionOpen) {
                $db->rollback();
            }
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

$campuses = $programs = $cohorts = $enrollmentCohorts = $vehicles = $fleetVehicles = $instructors = $clients = [];
$studentOptions = $staffOptions = [];
$stats = [
    'active_cohorts' => 0,
    'active_trainees' => 0,
    'available_vehicles' => 0,
    'fleet_alerts' => 0,
    'sessions_month' => 0,
    'contact_hours_month' => 0,
    'expiring_instructors' => 0,
    'corporate_clients' => 0,
];
$cohortRows = $vehicleRows = $instructorRows = $sessionRows = $checkRows = $clientRows = $traineeRows = [];
$telematicsRows = $maintenanceRows = $fuelRows = $routeRows = $incidentRows = $partRows = [];
$transportIntelligence = null;
$transportAiStatus = ['available' => false, 'model' => null];

if (!$needsInstall) {
    $campuses = tm_fetch_all($db, "SELECT id, campus_name, campus_code FROM transport_campuses WHERE status = 'active' ORDER BY campus_name");
    $programs = tm_fetch_all($db, "SELECT id, program_name, program_code, default_fee FROM transport_programs WHERE status = 'active' ORDER BY program_name");
    $cohorts = tm_fetch_all($db, "SELECT id, cohort_name, status FROM transport_cohorts WHERE status IN ('planning','open','in_progress') ORDER BY start_date DESC, cohort_name");
    $enrollmentCohorts = array_values(array_filter($cohorts, static fn(array $cohort): bool => in_array((string)$cohort['status'], ['open', 'in_progress'], true)));
    $vehicles = tm_fetch_all($db, "SELECT id, registration_no, make_model FROM transport_vehicles WHERE status IN ('available','assigned') ORDER BY registration_no");
    $fleetVehicles = tm_fetch_all($db, "SELECT id, registration_no, make_model, status FROM transport_vehicles ORDER BY registration_no");
    $instructors = tm_fetch_all($db, "SELECT id, full_name FROM transport_instructors WHERE status = 'active' ORDER BY full_name");
    $clients = tm_fetch_all($db, "SELECT id, client_name FROM transport_corporate_clients WHERE status = 'active' ORDER BY client_name");
    $studentOptions = tm_fetch_trainee_candidates($db);
    $staffOptions = tm_fetch_all($db, "
        SELECT staff_id, Fname, Lname, role, status, email, mobile
        FROM staff
        WHERE LOWER(COALESCE(NULLIF(TRIM(status), ''), 'active')) NOT IN ('deleted','inactive','disabled','suspended','terminated')
        ORDER BY Lname, Fname, staff_id
    ");

    $stats['active_cohorts'] = (int)tm_scalar($db, "SELECT COUNT(*) FROM transport_cohorts WHERE status IN ('open','in_progress')");
    $stats['active_trainees'] = (int)tm_scalar($db, "SELECT COUNT(*) FROM transport_enrollments WHERE booking_status = 'booked' AND status IN ('enrolled','active')");
    $stats['available_vehicles'] = (int)tm_scalar($db, "SELECT COUNT(*) FROM transport_vehicles WHERE status = 'available'");
    $stats['fleet_alerts'] = (int)tm_scalar($db, "SELECT COUNT(*) FROM transport_vehicles WHERE status IN ('maintenance','unavailable') OR (fitness_expiry IS NOT NULL AND fitness_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) OR (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))");
    $stats['sessions_month'] = (int)tm_scalar($db, "SELECT COUNT(*) FROM transport_sessions WHERE session_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND status <> 'cancelled'");
    $stats['contact_hours_month'] = tm_scalar($db, "SELECT COALESCE(SUM(contact_hours),0) FROM transport_sessions WHERE session_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND status = 'completed'");
    $stats['expiring_instructors'] = (int)tm_scalar($db, "SELECT COUNT(*) FROM transport_instructors WHERE status = 'active' AND ((rtsa_expiry IS NOT NULL AND rtsa_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)) OR (teveta_expiry IS NOT NULL AND teveta_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)))");
    $stats['corporate_clients'] = (int)tm_scalar($db, "SELECT COUNT(*) FROM transport_corporate_clients WHERE status = 'active'");

    $cohortRows = tm_fetch_all($db, "
        SELECT c.*, p.program_name, p.program_code, p.required_contact_hours, ca.campus_name,
               COALESCE(ec.enrolled_count, 0) AS enrolled_count,
               COALESCE(sh.delivered_hours, 0) AS delivered_hours
        FROM transport_cohorts c
        INNER JOIN transport_programs p ON p.id = c.program_id
        INNER JOIN transport_campuses ca ON ca.id = c.campus_id
        LEFT JOIN (
            SELECT cohort_id, COUNT(*) AS enrolled_count
            FROM transport_enrollments
            WHERE status IN ('enrolled','active','completed')
            GROUP BY cohort_id
        ) ec ON ec.cohort_id = c.id
        LEFT JOIN (
            SELECT cohort_id, COALESCE(SUM(contact_hours), 0) AS delivered_hours
            FROM transport_sessions
            WHERE status = 'completed'
            GROUP BY cohort_id
        ) sh ON sh.cohort_id = c.id
        ORDER BY c.start_date DESC
        LIMIT 8
    ");

    $vehicleRows = tm_fetch_all($db, "
        SELECT v.*, ca.campus_name,
               (SELECT checklist_date FROM transport_preuse_checks pc WHERE pc.vehicle_id = v.id ORDER BY pc.checklist_date DESC, pc.id DESC LIMIT 1) AS last_check_date,
               (SELECT overall_status FROM transport_preuse_checks pc WHERE pc.vehicle_id = v.id ORDER BY pc.checklist_date DESC, pc.id DESC LIMIT 1) AS last_check_status
        FROM transport_vehicles v
        INNER JOIN transport_campuses ca ON ca.id = v.campus_id
        ORDER BY FIELD(v.status, 'maintenance','unavailable','assigned','available'), v.registration_no
        LIMIT 8
    ");

    $instructorRows = tm_fetch_all($db, "
        SELECT i.*,
               COALESCE(NULLIF(CONCAT_WS(' ', st.Fname, st.Lname), ''), i.full_name) AS full_name,
               ca.campus_name
        FROM transport_instructors i
        INNER JOIN transport_campuses ca ON ca.id = i.campus_id
        LEFT JOIN staff st ON st.staff_id = i.staff_id
        ORDER BY FIELD(i.status, 'active','on_leave','inactive'), i.full_name
        LIMIT 8
    ");

    $sessionWindowStart = $activeTab === 'session' ? date('Y-m-d', strtotime('-30 days')) : date('Y-m-d');
    $sessionLimit = $activeTab === 'session' ? 20 : 8;
    $sessionRows = tm_fetch_all($db, "
        SELECT s.*, c.cohort_name, i.full_name AS instructor_name, v.registration_no, p.program_code
        FROM transport_sessions s
        INNER JOIN transport_cohorts c ON c.id = s.cohort_id
        INNER JOIN transport_programs p ON p.id = c.program_id
        INNER JOIN transport_instructors i ON i.id = s.instructor_id
        LEFT JOIN transport_vehicles v ON v.id = s.vehicle_id
        WHERE s.session_date >= ?
          AND s.status <> 'cancelled'
        ORDER BY CASE WHEN s.session_date >= CURDATE() THEN 0 ELSE 1 END,
                 CASE WHEN s.session_date >= CURDATE() THEN s.session_date END ASC,
                 CASE WHEN s.session_date < CURDATE() THEN s.session_date END DESC,
                 s.start_time ASC
        LIMIT {$sessionLimit}
    ", 's', [$sessionWindowStart]);

    $checkRows = tm_fetch_all($db, "
        SELECT pc.*, v.registration_no, i.full_name AS instructor_name
        FROM transport_preuse_checks pc
        INNER JOIN transport_vehicles v ON v.id = pc.vehicle_id
        LEFT JOIN transport_instructors i ON i.id = pc.instructor_id
        ORDER BY pc.checklist_date DESC, pc.id DESC
        LIMIT 6
    ");

    $clientRows = tm_fetch_all($db, "
        SELECT cc.*,
               COALESCE(ce.active_enrollments, 0) AS active_enrollments,
               COALESCE(ce.outstanding_balance, 0) AS outstanding_balance
        FROM transport_corporate_clients cc
        LEFT JOIN (
            SELECT corporate_client_id,
                   COUNT(*) AS active_enrollments,
                   COALESCE(SUM(fee_amount - amount_paid), 0) AS outstanding_balance
            FROM transport_enrollments
            WHERE status IN ('enrolled','active')
              AND corporate_client_id IS NOT NULL
            GROUP BY corporate_client_id
        ) ce ON ce.corporate_client_id = cc.id
        ORDER BY cc.client_name
        LIMIT 8
    ");

    $traineeRows = tm_fetch_all($db, "
        SELECT t.id AS trainee_id, e.id AS enrollment_id, t.student_id,
               COALESCE(NULLIF(stu.Fname, ''), t.first_name) AS first_name,
               COALESCE(NULLIF(stu.Lname, ''), t.last_name) AS last_name,
               COALESCE(NULLIF(stu.mobile, ''), t.phone) AS phone,
               COALESCE(NULLIF(stu.email, ''), t.email) AS email,
               t.existing_license_class, t.medical_clearance_status,
               COALESCE(NULLIF(CONCAT_WS(' - ', NULLIF(stu.next_kin, ''), NULLIF(stu.next_kin_mobile, '')), ''), t.emergency_contact) AS emergency_contact,
               t.employer_name,
               e.status, e.fee_amount, e.amount_paid, e.notes AS enrollment_notes,
               c.cohort_name, p.program_code, cc.client_name
        FROM transport_enrollments e
        INNER JOIN transport_trainees t ON t.id = e.trainee_id
        LEFT JOIN students stu ON stu.SID = t.student_id
        INNER JOIN transport_cohorts c ON c.id = e.cohort_id
        INNER JOIN transport_programs p ON p.id = c.program_id
        LEFT JOIN transport_corporate_clients cc ON cc.id = e.corporate_client_id
        ORDER BY e.created_at DESC
        LIMIT 8
    ");

    $telematicsRows = tm_fetch_all($db, "
        SELECT tl.*, v.registration_no
        FROM transport_telematics_logs tl
        INNER JOIN transport_vehicles v ON v.id = tl.vehicle_id
        ORDER BY tl.recorded_at DESC, tl.id DESC
        LIMIT 8
    ");

    $maintenanceRows = tm_fetch_all($db, "
        SELECT ml.*, v.registration_no
        FROM transport_maintenance_logs ml
        INNER JOIN transport_vehicles v ON v.id = ml.vehicle_id
        ORDER BY ml.service_date DESC, ml.id DESC
        LIMIT 8
    ");

    $fuelRows = tm_fetch_all($db, "
        SELECT fl.*, v.registration_no
        FROM transport_fuel_logs fl
        INNER JOIN transport_vehicles v ON v.id = fl.vehicle_id
        ORDER BY fl.fuel_date DESC, fl.id DESC
        LIMIT 8
    ");

    $routeRows = tm_fetch_all($db, "
        SELECT rp.*, v.registration_no
        FROM transport_route_plans rp
        INNER JOIN transport_vehicles v ON v.id = rp.vehicle_id
        ORDER BY FIELD(rp.status, 'in_progress','planned','completed','cancelled'), rp.planned_date DESC, rp.id DESC
        LIMIT 8
    ");

    $incidentRows = tm_fetch_all($db, "
        SELECT ir.*, v.registration_no, i.full_name AS instructor_name
        FROM transport_incident_reports ir
        INNER JOIN transport_vehicles v ON v.id = ir.vehicle_id
        LEFT JOIN transport_instructors i ON i.id = ir.instructor_id
        ORDER BY FIELD(ir.status, 'open','in_review','resolved'), ir.incident_time DESC, ir.id DESC
        LIMIT 8
    ");

    $partRows = tm_fetch_all($db, "
        SELECT pi.*, v.registration_no
        FROM transport_parts_inventory pi
        LEFT JOIN transport_vehicles v ON v.id = pi.vehicle_id
        ORDER BY FIELD(pi.status, 'reorder','available','assigned','retired'), pi.part_name
        LIMIT 8
    ");

    if ($isOverviewPage) {
        require_once __DIR__ . '/services/TransportIntelligence.php';
        $transportIntelligenceService = new TransportIntelligence($db);
        $transportIntelligence = $transportIntelligenceService->snapshot();
        $transportAiStatus = $transportIntelligenceService->assistantStatus();
    }
}

require_once __DIR__ . "/includes/header.php";
?>

<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
<link rel="stylesheet" href="/wucportal/lecturers/css/lecturer-dashboard.css">
<link rel="stylesheet" href="/wucportal/lecturers/css/module-reusable.css">

<style>
    .transport-page {
        --transport-primary: #6f42c1;
        --transport-secondary: #5a32a3;
        --transport-line: #e2e8f0;
        --transport-muted: #64748b;
        padding-top: 1.25rem;
        padding-bottom: 2rem;
        max-width: 100%;
        overflow-x: hidden;
        box-sizing: border-box;
    }
    .transport-page .dashboard-header {
        border-left: 4px solid var(--transport-primary);
    }
    .transport-page .dashboard-title {
        color: var(--transport-primary);
    }
    .transport-page .page-header,
    .transport-card {
        background: #fff;
        border: 1px solid var(--transport-line);
        border-radius: 0.75rem;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.07);
    }
    .transport-page .page-header {
        padding: 1.25rem 1.5rem;
    }
    .transport-page .page-title {
        font-size: 1.65rem;
        font-weight: 700;
        color: var(--transport-primary);
        margin-bottom: .3rem;
    }
    .transport-page .page-subtitle {
        color: var(--transport-muted);
        font-size: .925rem;
        margin-bottom: 0;
    }
    .transport-module-badge {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        min-height: 28px;
        padding: .25rem .65rem;
        border-radius: 999px;
        background: #f3effb;
        color: #5a32a3;
        border: 1px solid #d9ccef;
        font-size: .78rem;
        font-weight: 700;
    }
    .transport-stat {
        background: #fff;
        border: 1px solid #f2f5f8;
        border-radius: 0.75rem;
        padding: 1.25rem;
        height: 100%;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        transition: transform .2s ease, box-shadow .2s ease;
    }
    .transport-stat:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(111, 66, 193, 0.15);
    }
    .transport-stat .icon {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        margin-bottom: .85rem;
        box-shadow: 0 4px 15px rgba(111, 66, 193, 0.25);
    }
    .transport-stat .value {
        font-size: 1.75rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.1;
    }
    .transport-stat .label {
        color: var(--transport-muted);
        font-size: .875rem;
        font-weight: 500;
        margin-top: .25rem;
    }
    .transport-card-header {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f0f0f0;
        font-weight: 700;
        color: var(--transport-primary);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        border-left: 4px solid var(--transport-primary);
    }
    .transport-card-body {
        padding: 1.25rem;
    }
    .transport-intelligence-score {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        flex: 0 0 auto;
        background: linear-gradient(135deg, #6f42c1, #5a32a3);
        color: #fff;
        font-size: 1.25rem;
        font-weight: 800;
        box-shadow: 0 8px 24px rgba(111, 66, 193, .22);
    }
    .transport-priority-list {
        display: grid;
        gap: .65rem;
    }
    .transport-priority {
        display: flex;
        gap: .75rem;
        align-items: flex-start;
        padding: .75rem;
        border: 1px solid var(--transport-line);
        border-radius: 10px;
        color: inherit;
        text-decoration: none;
    }
    .transport-priority:hover { border-color: #6f42c1; color: inherit; }
    .transport-priority.critical { border-left: 4px solid #dc3545; }
    .transport-priority.warning { border-left: 4px solid #f59e0b; }
    .transport-priority.good { border-left: 4px solid #198754; }
    .transport-ai-brief { min-height: 72px; white-space: pre-line; }
    .transport-action-strip {
        display: flex;
        flex-wrap: wrap;
        gap: .55rem;
    }
    .transport-action-strip .btn {
        border-radius: 8px;
        font-weight: 700;
    }
    .transport-page .btn-primary {
        background: linear-gradient(135deg, var(--transport-primary) 0%, var(--transport-secondary) 100%);
        border-color: var(--transport-primary);
    }
    .transport-page .btn-outline-primary {
        color: var(--transport-primary);
        border-color: var(--transport-primary);
    }
    .transport-page .btn-outline-primary:hover,
    .transport-page .btn-outline-primary:focus {
        background: var(--transport-primary);
        color: #fff;
    }
    .transport-page .text-primary {
        color: var(--transport-primary) !important;
    }
    .transport-page .bg-primary {
        background: linear-gradient(135deg, var(--transport-primary) 0%, var(--transport-secondary) 100%) !important;
    }
    .transport-setup-note {
        border: 1px solid #d9ccef;
        background: #faf8fe;
        color: #4a2b9c;
        border-radius: 8px;
        padding: .8rem .9rem;
        margin-bottom: 1rem;
        font-size: .86rem;
    }
    .table-transport th {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: var(--transport-muted);
        background: #f8fafc;
        white-space: nowrap;
    }
    .table-transport td {
        vertical-align: middle;
        font-size: .86rem;
    }
    .mini-progress {
        height: 7px;
        border-radius: 99px;
    }
    .transport-page .form-label {
        color: #1e293b;
        font-weight: 700;
    }
    .transport-page .form-control,
    .transport-page .form-select {
        border-color: #cbd5e1;
        border-radius: 8px;
    }
    .transport-page .form-control:focus,
    .transport-page .form-select:focus {
        border-color: var(--transport-primary);
        box-shadow: 0 0 0 .2rem rgba(111, 66, 193, .14);
    }
    .fleet-workspace-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
        padding: .45rem;
        margin-bottom: 1rem;
        background: #f8fafc;
        border: 1px solid var(--transport-line);
        border-radius: 8px;
    }
    .fleet-tab-button {
        border: 1px solid transparent;
        background: transparent;
        color: #334155;
        border-radius: 7px;
        padding: .45rem .7rem;
        font-size: .82rem;
        font-weight: 700;
        line-height: 1.15;
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        white-space: nowrap;
    }
    .fleet-tab-button:hover,
    .fleet-tab-button:focus {
        border-color: #cbd5e1;
        background: #fff;
        color: var(--transport-primary);
    }
    .fleet-tab-button.active {
        border-color: rgba(111, 66, 193, .35);
        background: #fff;
        color: var(--transport-primary);
        box-shadow: 0 2px 8px rgba(15, 23, 42, .08);
    }
    .fleet-tab-panel[hidden] {
        display: none !important;
    }
    .fleet-tab-panel .transport-card-body.border {
        padding: .9rem;
        border-color: #e2e8f0 !important;
        border-radius: 8px !important;
        background: #fff;
    }
    .fleet-tab-panel .h6 {
        font-size: .92rem;
    }
    .fleet-tab-panel .form-label {
        font-size: .72rem;
        margin-bottom: .2rem;
    }
    @media (max-width: 575.98px) {
        .transport-page {
            padding-left: .25rem !important;
            padding-right: .25rem !important;
        }
        .transport-page .page-header,
        .transport-card-body {
            padding: .85rem;
        }
        .transport-stat .value {
            font-size: 1.1rem;
        }
        .transport-card-header {
            align-items: flex-start;
            font-size: .9rem;
        }
        .transport-action-strip .btn {
            flex: 1 1 150px;
        }
        .fleet-workspace-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
        }
        .fleet-tab-button {
            justify-content: center;
            white-space: normal;
        }
    }
</style>

<div class="container-fluid px-4 portal-dashboard transport-page">
    <div class="dashboard-header lecturer-section page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="transport-module-badge mb-2"><i class="fas fa-route"></i> Head of Section Transport Control</div>
                <h1 class="dashboard-title page-title"><i class="<?php echo tm_h($activePage['icon']); ?> me-2"></i><?php echo tm_h($activePage['title']); ?></h1>
                <p class="page-subtitle"><?php echo tm_h($activePage['subtitle']); ?></p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="/wucportal/transport/reports.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-chart-line me-1"></i>Reports</a>
                <button class="btn btn-outline-primary btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo tm_h($messageType ?: 'info'); ?> alert-dismissible fade show" role="alert">
            <?php echo tm_h($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($needsInstall): ?>
        <div class="alert alert-warning">
            <strong>Transport Management is not installed.</strong>
            Install the TSMS database tables before using this module.
            <a class="btn btn-sm btn-primary ms-2" href="scripts/install_transport_module.php">Install Transport Tables</a>
        </div>
    <?php else: ?>
        <?php if ($isOverviewPage): ?>
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="transport-stat">
                    <div class="icon bg-primary"><i class="fas fa-users"></i></div>
                    <div class="value"><?php echo number_format($stats['active_trainees']); ?></div>
                    <div class="label">Booked active trainees</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="transport-stat">
                    <div class="icon bg-info"><i class="fas fa-calendar-check"></i></div>
                    <div class="value"><?php echo number_format($stats['active_cohorts']); ?></div>
                    <div class="label">Open or active cohorts</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="transport-stat">
                    <div class="icon bg-success"><i class="fas fa-truck"></i></div>
                    <div class="value"><?php echo number_format($stats['available_vehicles']); ?></div>
                    <div class="label">Available fleet assets</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="transport-stat">
                    <div class="icon bg-warning"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="value"><?php echo number_format($stats['fleet_alerts'] + $stats['expiring_instructors']); ?></div>
                    <div class="label">Fleet or accreditation alerts</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="transport-stat">
                    <div class="icon bg-secondary"><i class="fas fa-clock"></i></div>
                    <div class="value"><?php echo number_format($stats['sessions_month']); ?></div>
                    <div class="label">Sessions this month</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="transport-stat">
                    <div class="icon bg-dark"><i class="fas fa-hourglass-half"></i></div>
                    <div class="value"><?php echo number_format($stats['contact_hours_month'], 1); ?></div>
                    <div class="label">Contact hours this month</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="transport-stat">
                    <div class="icon bg-danger"><i class="fas fa-id-card"></i></div>
                    <div class="value"><?php echo number_format($stats['expiring_instructors']); ?></div>
                    <div class="label">Instructor renewals due</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="transport-stat">
                    <div class="icon bg-primary"><i class="fas fa-building"></i></div>
                    <div class="value"><?php echo number_format($stats['corporate_clients']); ?></div>
                    <div class="label">Corporate clients</div>
                </div>
            </div>
        </div>

        <div class="transport-action-strip mb-4">
            <a class="btn btn-primary btn-sm" href="/wucportal/transport/trainees.php"><i class="fas fa-user-plus me-1"></i>Enroll Trainee</a>
            <a class="btn btn-outline-primary btn-sm" href="/wucportal/transport/sessions.php"><i class="fas fa-calendar-plus me-1"></i>Schedule Session</a>
            <a class="btn btn-outline-primary btn-sm" href="/wucportal/transport/preuse_checks.php"><i class="fas fa-clipboard-check me-1"></i>Record Check</a>
            <a class="btn btn-outline-secondary btn-sm" href="/wucportal/transport/cohorts.php"><i class="fas fa-layer-group me-1"></i>New Cohort</a>
            <a class="btn btn-outline-secondary btn-sm" href="/wucportal/transport/fleet.php"><i class="fas fa-truck me-1"></i>Add Vehicle</a>
            <a class="btn btn-outline-secondary btn-sm" href="/wucportal/transport/instructors.php"><i class="fas fa-id-card me-1"></i>Add Instructor</a>
            <a class="btn btn-outline-secondary btn-sm" href="/wucportal/transport/clients.php"><i class="fas fa-building me-1"></i>Add Client</a>
            <a class="btn btn-outline-primary btn-sm" href="/wucportal/transport/reports.php"><i class="fas fa-chart-line me-1"></i>Reports &amp; Summaries</a>
        </div>

        <?php if ($transportIntelligence): ?>
        <section class="transport-card mb-4" aria-labelledby="transport-intelligence-title">
            <div class="transport-card-header">
                <span id="transport-intelligence-title"><i class="fas fa-wand-magic-sparkles me-2 text-primary"></i>Operations Intelligence</span>
                <span class="badge <?php echo $transportAiStatus['available'] ? 'bg-success' : 'bg-secondary'; ?>">
                    <?php echo $transportAiStatus['available'] ? 'Local AI connected' : 'Rules engine active'; ?>
                </span>
            </div>
            <div class="transport-card-body">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="transport-intelligence-score"><?php echo (int)$transportIntelligence['score']; ?></div>
                            <div>
                                <div class="fw-bold fs-5"><?php echo tm_h($transportIntelligence['label']); ?></div>
                                <div class="text-muted small">Safety and compliance score / 100</div>
                            </div>
                        </div>
                        <div class="transport-priority-list">
                            <?php foreach ($transportIntelligence['priorities'] as $priority): ?>
                            <a class="transport-priority <?php echo tm_h($priority['severity']); ?>" href="<?php echo tm_h($priority['url']); ?>">
                                <span class="badge bg-light text-dark"><?php echo (int)$priority['count']; ?></span>
                                <span><strong class="d-block"><?php echo tm_h($priority['title']); ?></strong><small class="text-muted"><?php echo tm_h($priority['detail']); ?></small></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="rounded-3 border bg-light p-3 h-100 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div><strong><i class="fas fa-robot me-2 text-primary"></i>Executive briefing</strong><div class="small text-muted">Aggregate operational data only; no trainee or staff personal data is sent.</div></div>
                                <button type="button" class="btn btn-primary btn-sm" id="transport-ai-generate" <?php echo $transportAiStatus['available'] ? '' : 'disabled'; ?>>Generate</button>
                            </div>
                            <div id="transport-ai-brief" class="transport-ai-brief text-muted mt-2" aria-live="polite">
                                <?php echo $transportAiStatus['available'] ? 'Generate a concise briefing from the current safety, compliance, maintenance, and scheduling signals.' : 'The local AI service is unavailable. The scored priorities on the left remain fully operational.'; ?>
                            </div>
                            <div id="transport-ai-meta" class="small text-muted mt-auto"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!$isOverviewPage): ?>
        <div class="transport-card mb-4">
            <div class="transport-card-header">
                <span><i class="<?php echo tm_h($activePage['icon']); ?> me-2 text-primary"></i><?php echo tm_h($activePage['card_title']); ?></span>
                <a class="btn btn-outline-secondary btn-sm" href="/wucportal/transport/transport_management.php"><i class="fas fa-arrow-left me-1"></i>Operations</a>
            </div>
            <div class="transport-card-body">
                <div class="transport-section-content">
                    <?php if ($activeTab === 'trainee'): ?>
                    <div class="<?php echo tm_tab_pane_class('trainee', $activeTab); ?>" id="tab-trainee">
                        <?php if (!$enrollmentCohorts): ?>
                            <div class="transport-setup-note"><i class="fas fa-info-circle me-1"></i>Open a cohort before enrolling trainees. Planning, completed, and cancelled cohorts do not accept enrolments.</div>
                        <?php endif; ?>
                        <?php if (!$studentOptions): ?>
                            <div class="transport-setup-note"><i class="fas fa-info-circle me-1"></i>No trainee candidates were found in short-course, ITC programme, or transport enrolment records.</div>
                        <?php endif; ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_trainee">
                            <div class="col-md-3">
                                <label class="form-label">Cohort</label>
                                <select name="cohort_id" class="form-select" required>
                                    <option value="">Select cohort</option>
                                    <?php foreach ($enrollmentCohorts as $cohort): ?>
                                        <option value="<?php echo (int)$cohort['id']; ?>"><?php echo tm_h($cohort['cohort_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Trainee</label>
                                <select name="student_id" id="transport-student-select" class="form-select" required>
                                    <option value="">Select enrolled trainee</option>
                                    <?php foreach ($studentOptions as $student): ?>
                                        <?php
                                            $studentName = tm_person_full_name((string)$student['Fname'], (string)$student['Lname'], (string)$student['SID']);
                                            $studentStatus = trim((string)($student['status'] ?? ''));
                                            $traineeSource = trim((string)($student['trainee_source'] ?? ''));
                                            $studentLabel = (string)$student['SID'] . ' - ' . $studentName
                                                . ($traineeSource !== '' ? ' [' . $traineeSource . ']' : '')
                                                . ($studentStatus !== '' ? ' (' . $studentStatus . ')' : '');
                                        ?>
                                        <option
                                            value="<?php echo tm_h($student['SID']); ?>"
                                            data-first-name="<?php echo tm_h($student['Fname']); ?>"
                                            data-last-name="<?php echo tm_h($student['Lname']); ?>"
                                            data-phone="<?php echo tm_h($student['mobile']); ?>"
                                            data-email="<?php echo tm_h($student['email']); ?>"
                                            data-emergency="<?php echo tm_h(tm_student_emergency_contact($student)); ?>"
                                            data-source="<?php echo tm_h($traineeSource); ?>"
                                        ><?php echo tm_h($studentLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">First Name</label>
                                <input name="first_name" class="form-control" readonly maxlength="100">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Last Name</label>
                                <input name="last_name" class="form-control" readonly maxlength="100">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Corporate Client</label>
                                <select name="corporate_client_id" class="form-select">
                                    <option value="">Individual trainee</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo (int)$client['id']; ?>"><?php echo tm_h($client['client_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Phone</label>
                                <input name="phone" class="form-control" readonly maxlength="40">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" readonly maxlength="140">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Existing Licence</label>
                                <input name="existing_license_class" class="form-control" placeholder="B, C, CE" maxlength="50">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Medical</label>
                                <select name="medical_clearance_status" class="form-select">
                                    <option value="pending">Pending</option>
                                    <option value="cleared">Cleared</option>
                                    <option value="not_required">Not required</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Employer</label>
                                <input name="employer_name" class="form-control" maxlength="180">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Emergency Contact</label>
                                <input name="emergency_contact" class="form-control" readonly maxlength="160">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Training Mode</label>
                                <select name="training_mode" id="enrollMode" class="form-select">
                                    <option value="full-time">Full-time</option>
                                    <option value="in-house">In-house</option>
                                    <option value="evening">Evening</option>
                                    <option value="distance">Distance</option>
                                    <option value="at-campus">At campus</option>
                                </select>
                                <div class="form-text">Fee is calculated from the approved schedule (BR008).</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Assessed Fee</label>
                                <input type="text" id="enrollFeeDisplay" class="form-control" value="&mdash;" readonly>
                                <div class="form-text" id="enrollFeeNote">Payments are recorded &amp; verified on <strong>Payments &amp; Booking</strong> (BR001).</div>
                            </div>
                            <div class="col-12" id="enrollFeeOptionsWrap" style="display:none;">
                                <label class="form-label">Optional Charges</label>
                                <div id="enrollFeeOptions" class="d-flex flex-wrap gap-3"></div>
                            </div>
                            <div class="col-12" id="enrollEligibilityWrap" style="display:none;">
                                <div id="enrollEligibility"></div>
                            </div>
                            <div class="col-12" id="enrollOverrideWrap" style="display:none;">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="eligibility_override" id="enrollOverride" value="1">
                                    <label class="form-check-label" for="enrollOverride">
                                        Override eligibility block (admissions / admin only)
                                    </label>
                                </div>
                                <input type="text" name="eligibility_override_reason" class="form-control mt-1" maxlength="255" placeholder="Reason for the override (recorded in the audit trail)">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Enrollment Date</label>
                                <input type="date" name="enrollment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Notes</label>
                                <input name="enrollment_notes" class="form-control" maxlength="255">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button class="btn btn-primary w-100" <?php echo (!$enrollmentCohorts || !$studentOptions) ? 'disabled' : ''; ?>><i class="fas fa-user-plus me-1"></i>Enroll Trainee</button>
                            </div>
                        </form>
                        <script>
                        (function(){
                          var pane = document.getElementById('tab-trainee');
                          var form = pane ? pane.querySelector('form') : null;
                          if (!form) return;
                          var cohort   = form.querySelector('[name="cohort_id"]');
                          var student  = form.querySelector('#transport-student-select');
                          var mode     = form.querySelector('#enrollMode');
                          var licence  = form.querySelector('[name="existing_license_class"]');
                          var medical  = form.querySelector('[name="medical_clearance_status"]');
                          var feeDisplay = document.getElementById('enrollFeeDisplay');
                          var optionsWrap = document.getElementById('enrollFeeOptionsWrap');
                          var optionsBox  = document.getElementById('enrollFeeOptions');
                          var eligWrap = document.getElementById('enrollEligibilityWrap');
                          var eligBox  = document.getElementById('enrollEligibility');
                          var overrideWrap = document.getElementById('enrollOverrideWrap');
                          if (!cohort || !student || !mode || !feeDisplay) return;
                          var csrf = <?php echo json_encode($csrfToken); ?>;
                          var timer = null;
                          function esc(s){ var d=document.createElement('div'); d.textContent = s==null?'':String(s); return d.innerHTML; }
                          function checkedOptions(){ return Array.prototype.slice.call(optionsBox.querySelectorAll('input[type=checkbox]:checked')).map(function(c){return c.value;}); }
                          function render(data){
                            var fq = data.fee_quote || {};
                            if (fq.ok){ feeDisplay.value = (fq.currency||'ZMW') + ' ' + Number(fq.total||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
                            else { feeDisplay.value = fq.error || '—'; }
                            var opts = data.fee_options || [];
                            if (opts.length){
                              optionsWrap.style.display = '';
                              var was = {}; checkedOptions().forEach(function(id){ was[id]=true; });
                              optionsBox.innerHTML = '';
                              opts.forEach(function(o){
                                var id='feeopt_'+o.id, div=document.createElement('div'); div.className='form-check';
                                div.innerHTML='<input class="form-check-input" type="checkbox" name="fee_options[]" value="'+o.id+'" id="'+id+'"'+(was[o.id]?' checked':'')+'>'+
                                  '<label class="form-check-label" for="'+id+'">'+esc(o.fee_name)+' (+'+Number(o.amount).toFixed(2)+')</label>';
                                optionsBox.appendChild(div);
                              });
                            } else { optionsWrap.style.display='none'; optionsBox.innerHTML=''; }
                            var e = data.eligibility;
                            if (e){
                              eligWrap.style.display='';
                              var html='';
                              if (e.eligible){ html+='<div class="alert alert-success py-2 mb-2"><i class="fas fa-check-circle me-1"></i>Eligible for this course.</div>'; }
                              else { html+='<div class="alert alert-danger py-2 mb-2"><strong><i class="fas fa-ban me-1"></i>Not eligible:</strong><ul class="mb-0">'; (e.reasons||[]).forEach(function(r){html+='<li>'+esc(r)+'</li>';}); html+='</ul></div>'; }
                              if (e.warnings && e.warnings.length){ html+='<div class="alert alert-warning py-2 mb-0"><strong>Verify documents:</strong><ul class="mb-0">'; e.warnings.forEach(function(w){html+='<li>'+esc(w)+'</li>';}); html+='</ul></div>'; }
                              eligBox.innerHTML=html;
                              overrideWrap.style.display = e.eligible ? 'none' : '';
                            } else { eligWrap.style.display='none'; overrideWrap.style.display='none'; }
                          }
                          function refresh(){
                            if (!cohort.value || !student.value){ return; }
                            var body=new URLSearchParams();
                            body.set('csrf_token', csrf);
                            body.set('cohort_id', cohort.value);
                            body.set('student_id', student.value);
                            body.set('training_mode', mode.value);
                            body.set('existing_license_class', licence ? licence.value : '');
                            body.set('medical_clearance_status', medical ? medical.value : '');
                            checkedOptions().forEach(function(id){ body.append('fee_options[]', id); });
                            fetch('/wucportal/transport/ajax_enroll_preview.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:body, credentials:'same-origin'})
                              .then(function(r){return r.json();}).then(render).catch(function(){});
                          }
                          function debounce(){ clearTimeout(timer); timer=setTimeout(refresh, 250); }
                          [cohort, student, mode, licence, medical].forEach(function(el){ if(el){ el.addEventListener('change', debounce); } });
                          optionsBox.addEventListener('change', refresh);
                        })();
                        </script>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'payments'): ?>
                    <div class="<?php echo tm_tab_pane_class('payments', $activeTab); ?>" id="tab-payments">
                        <?php include __DIR__ . '/includes/payments_pane.php'; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'fees'): ?>
                    <div class="<?php echo tm_tab_pane_class('fees', $activeTab); ?>" id="tab-fees">
                        <?php include __DIR__ . '/includes/fees_pane.php'; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'cohort'): ?>
                    <div class="<?php echo tm_tab_pane_class('cohort', $activeTab); ?>" id="tab-cohort">
                        <?php if (!$programs || !$campuses): ?>
                            <div class="transport-setup-note"><i class="fas fa-info-circle me-1"></i>Active campuses and programmes are required before creating cohorts.</div>
                        <?php endif; ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_cohort">
                            <div class="col-md-3">
                                <label class="form-label">Program</label>
                                <select name="program_id" class="form-select" required>
                                    <option value="">Select program</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo (int)$program['id']; ?>"><?php echo tm_h($program['program_code'] . ' - ' . $program['program_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Campus</label>
                                <select name="campus_id" class="form-select" required>
                                    <option value="">Campus</option>
                                    <?php foreach ($campuses as $campus): ?>
                                        <option value="<?php echo (int)$campus['id']; ?>"><?php echo tm_h($campus['campus_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cohort Name</label>
                                <input name="cohort_name" class="form-control" required maxlength="140" placeholder="June 2026 Class B">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Intake Month</label>
                                <input type="month" name="intake_month" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Capacity</label>
                                <input type="number" min="1" name="capacity" class="form-control" value="20">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="cohort_status" class="form-select">
                                    <option value="planning">Planning</option>
                                    <option value="open">Open</option>
                                    <option value="in_progress">In progress</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Notes</label>
                                <input name="cohort_notes" class="form-control" maxlength="255">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button class="btn btn-primary w-100" <?php echo (!$programs || !$campuses) ? 'disabled' : ''; ?>>Create Cohort</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'session'): ?>
                    <div class="<?php echo tm_tab_pane_class('session', $activeTab); ?>" id="tab-session">
                        <?php if (!$cohorts || !$instructors): ?>
                            <div class="transport-setup-note"><i class="fas fa-info-circle me-1"></i>Create at least one cohort and one active instructor before scheduling sessions.</div>
                        <?php endif; ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_session">
                            <div class="col-md-3">
                                <label class="form-label">Cohort</label>
                                <select name="cohort_id" class="form-select" required>
                                    <option value="">Select cohort</option>
                                    <?php foreach ($cohorts as $cohort): ?>
                                        <option value="<?php echo (int)$cohort['id']; ?>"><?php echo tm_h($cohort['cohort_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Instructor</label>
                                <select name="instructor_id" class="form-select" required>
                                    <option value="">Select instructor</option>
                                    <?php foreach ($instructors as $instructor): ?>
                                        <option value="<?php echo (int)$instructor['id']; ?>"><?php echo tm_h($instructor['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Vehicle</label>
                                <select name="vehicle_id" id="transport-session-vehicle" class="form-select" aria-describedby="transport-session-vehicle-help">
                                    <option value="">None</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?php echo (int)$vehicle['id']; ?>"><?php echo tm_h($vehicle['registration_no']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Type</label>
                                <select name="session_type" id="transport-session-type" class="form-select" aria-describedby="transport-session-vehicle-help">
                                    <option value="practical">Practical</option>
                                    <option value="theory">Theory</option>
                                    <option value="simulator">Simulator</option>
                                    <option value="assessment">Assessment</option>
                                    <option value="maintenance_window">Maintenance window</option>
                                </select>
                                <div id="transport-session-vehicle-help" class="form-text">Practical sessions require a vehicle with valid fitness and insurance.</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date</label>
                                <input type="date" name="session_date" class="form-control" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Start</label>
                                <input type="time" name="start_time" class="form-control" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">End</label>
                                <input type="time" name="end_time" class="form-control" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Contact Hours</label>
                                <input type="number" step="0.25" min="0" name="contact_hours" class="form-control" value="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="session_status" class="form-select">
                                    <option value="scheduled">Scheduled</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Location</label>
                                <input name="location" class="form-control" maxlength="160">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Notes</label>
                                <input name="session_notes" class="form-control" maxlength="255">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button class="btn btn-primary w-100" <?php echo (!$cohorts || !$instructors) ? 'disabled' : ''; ?>><i class="fas fa-calendar-plus me-1"></i>Schedule Session</button>
                            </div>
                        </form>
                        <script>
                        (function () {
                            var type = document.getElementById('transport-session-type');
                            var vehicle = document.getElementById('transport-session-vehicle');
                            if (!type || !vehicle) return;
                            function syncVehicleRequirement() {
                                var required = type.value === 'practical';
                                vehicle.required = required;
                                vehicle.setAttribute('aria-required', required ? 'true' : 'false');
                            }
                            type.addEventListener('change', syncVehicleRequirement);
                            syncVehicleRequirement();
                        }());
                        </script>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'fleet'): ?>
                    <div class="<?php echo tm_tab_pane_class('fleet', $activeTab); ?>" id="tab-fleet">
                        <?php if (!$campuses): ?>
                            <div class="transport-setup-note"><i class="fas fa-info-circle me-1"></i>An active campus is required before adding fleet records.</div>
                        <?php endif; ?>
                        <div class="fleet-workspace-tabs" role="tablist" aria-label="Fleet workspace sections">
                            <button type="button" class="fleet-tab-button active" data-fleet-tab="assets" role="tab" aria-selected="true"><i class="fas fa-truck"></i>Assets</button>
                            <button type="button" class="fleet-tab-button" data-fleet-tab="logs" role="tab" aria-selected="false"><i class="fas fa-clipboard-list"></i>Logs</button>
                            <button type="button" class="fleet-tab-button" data-fleet-tab="routes" role="tab" aria-selected="false"><i class="fas fa-route"></i>Routes & Incidents</button>
                            <button type="button" class="fleet-tab-button" data-fleet-tab="parts" role="tab" aria-selected="false"><i class="fas fa-gears"></i>Parts</button>
                        </div>
                        <div class="fleet-tab-panel" data-fleet-panel="assets">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_vehicle">
                            <div class="col-md-2">
                                <label class="form-label">Campus</label>
                                <select name="campus_id" class="form-select" required>
                                    <option value="">Campus</option>
                                    <?php foreach ($campuses as $campus): ?>
                                        <option value="<?php echo (int)$campus['id']; ?>"><?php echo tm_h($campus['campus_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Registration</label>
                                <input name="registration_no" class="form-control" required maxlength="40">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Asset Tag</label>
                                <input name="asset_tag" class="form-control" maxlength="60">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Type</label>
                                <select name="vehicle_type" class="form-select">
                                    <option value="light_vehicle">Light vehicle</option>
                                    <option value="rigid_truck">Rigid truck</option>
                                    <option value="articulated_truck">Articulated truck</option>
                                    <option value="coach">Coach</option>
                                    <option value="motorcycle">Motorcycle</option>
                                    <option value="forklift">Forklift</option>
                                    <option value="simulator">Simulator</option>
                                    <option value="trailer">Trailer</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Make / Model</label>
                                <input name="make_model" class="form-control" maxlength="160">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Licence Class</label>
                                <input name="supported_license_class" class="form-control" maxlength="80">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Mileage</label>
                                <input type="number" min="0" name="current_mileage" class="form-control" value="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="vehicle_status" class="form-select">
                                    <option value="available">Available</option>
                                    <option value="assigned">Assigned</option>
                                    <option value="maintenance">Maintenance</option>
                                    <option value="unavailable">Unavailable</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Fitness Expiry</label>
                                <input type="date" name="fitness_expiry" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Insurance Expiry</label>
                                <input type="date" name="insurance_expiry" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Next Service</label>
                                <input type="date" name="next_service_due" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Notes</label>
                                <input name="vehicle_notes" class="form-control" maxlength="255">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button class="btn btn-primary w-100" <?php echo !$campuses ? 'disabled' : ''; ?>>Add Vehicle</button>
                            </div>
                        </form>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="logs" hidden>
                                <form method="post" class="transport-card-body border rounded h-100">
                                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                    <input type="hidden" name="action" value="add_telematics_log">
                                    <h3 class="h6 mb-3"><i class="fas fa-satellite-dish me-1 text-primary"></i>Telematics</h3>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <label class="form-label">Vehicle</label>
                                            <select name="vehicle_id" class="form-select" required>
                                                <option value="">Vehicle</option>
                                                <?php foreach ($fleetVehicles as $vehicle): ?>
                                                    <option value="<?php echo (int)$vehicle['id']; ?>"><?php echo tm_h($vehicle['registration_no']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Time</label>
                                            <input type="datetime-local" name="recorded_at" class="form-control" value="<?php echo tm_h(date('Y-m-d\TH:i')); ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Device ID</label>
                                            <input name="device_identifier" class="form-control" maxlength="80">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Location</label>
                                            <input name="location" class="form-control" maxlength="180">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Speed</label>
                                            <input type="number" step="0.01" min="0" name="speed_kph" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">RPM</label>
                                            <input type="number" min="0" name="engine_rpm" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Fuel %</label>
                                            <input type="number" step="0.01" min="0" max="100" name="fuel_level" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Cons.</label>
                                            <input type="number" step="0.01" min="0" name="fuel_consumption" class="form-control">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Behaviour events</label>
                                            <input name="behavior_events" class="form-control" maxlength="255" placeholder="Speeding, hard brake, seat-belt event">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm" <?php echo !$fleetVehicles ? 'disabled' : ''; ?>>Record Telemetry</button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="logs" hidden>
                                <form method="post" class="transport-card-body border rounded h-100">
                                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                    <input type="hidden" name="action" value="add_maintenance_log">
                                    <h3 class="h6 mb-3"><i class="fas fa-screwdriver-wrench me-1 text-primary"></i>Maintenance</h3>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <label class="form-label">Vehicle</label>
                                            <select name="vehicle_id" class="form-select" required>
                                                <option value="">Vehicle</option>
                                                <?php foreach ($fleetVehicles as $vehicle): ?>
                                                    <option value="<?php echo (int)$vehicle['id']; ?>"><?php echo tm_h($vehicle['registration_no']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Service Date</label>
                                            <input type="date" name="service_date" class="form-control" value="<?php echo tm_h(date('Y-m-d')); ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Next Service</label>
                                            <input type="date" name="next_service_date" class="form-control">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Status</label>
                                            <select name="maintenance_status" class="form-select">
                                                <option value="scheduled">Scheduled</option>
                                                <option value="completed">Completed</option>
                                                <option value="overdue">Overdue</option>
                                                <option value="cancelled">Cancelled</option>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label">Tasks Due</label>
                                            <input name="tasks_due" class="form-control" maxlength="255">
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label">Completed Tasks</label>
                                            <input name="completed_tasks" class="form-control" maxlength="255">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Notes</label>
                                            <input name="maintenance_notes" class="form-control" maxlength="255">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Cost (ZMW)</label>
                                            <input type="number" step="0.01" min="0" name="maintenance_cost" class="form-control" placeholder="optional">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm" <?php echo !$fleetVehicles ? 'disabled' : ''; ?>>Record Maintenance</button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="logs" hidden>
                                <form method="post" class="transport-card-body border rounded h-100">
                                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                    <input type="hidden" name="action" value="add_fuel_log">
                                    <h3 class="h6 mb-3"><i class="fas fa-gas-pump me-1 text-primary"></i>Fuel / Battery</h3>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <label class="form-label">Vehicle</label>
                                            <select name="vehicle_id" class="form-select" required>
                                                <option value="">Vehicle</option>
                                                <?php foreach ($fleetVehicles as $vehicle): ?>
                                                    <option value="<?php echo (int)$vehicle['id']; ?>"><?php echo tm_h($vehicle['registration_no']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Date</label>
                                            <input type="date" name="fuel_date" class="form-control" value="<?php echo tm_h(date('Y-m-d')); ?>" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Type</label>
                                            <select name="energy_type" class="form-select">
                                                <option value="fuel">Fuel</option>
                                                <option value="battery">Battery</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Level %</label>
                                            <input type="number" step="0.01" min="0" max="100" name="level_percent" class="form-control">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Added</label>
                                            <input type="number" step="0.01" min="0" name="amount_added" class="form-control" value="0">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Consumption</label>
                                            <input type="number" step="0.01" min="0" name="consumption" class="form-control">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Odometer</label>
                                            <input type="number" min="0" name="odometer" class="form-control">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Notes</label>
                                            <input name="fuel_notes" class="form-control" maxlength="255">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Unit cost (ZMW)</label>
                                            <input type="number" step="0.01" min="0" name="unit_cost" class="form-control" placeholder="per litre/kWh">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Total cost (ZMW)</label>
                                            <input type="number" step="0.01" min="0" name="total_cost" class="form-control" placeholder="auto if unit set">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm" <?php echo !$fleetVehicles ? 'disabled' : ''; ?>>Record Fuel</button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="routes" hidden>
                                <form method="post" class="transport-card-body border rounded h-100">
                                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                    <input type="hidden" name="action" value="add_route_plan">
                                    <h3 class="h6 mb-3"><i class="fas fa-route me-1 text-primary"></i>Route Plan</h3>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <label class="form-label">Vehicle</label>
                                            <select name="vehicle_id" class="form-select" required>
                                                <option value="">Vehicle</option>
                                                <?php foreach ($fleetVehicles as $vehicle): ?>
                                                    <option value="<?php echo (int)$vehicle['id']; ?>"><?php echo tm_h($vehicle['registration_no']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Date</label>
                                            <input type="date" name="planned_date" class="form-control" value="<?php echo tm_h(date('Y-m-d')); ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Status</label>
                                            <select name="route_status" class="form-select">
                                                <option value="planned">Planned</option>
                                                <option value="in_progress">In progress</option>
                                                <option value="completed">Completed</option>
                                                <option value="cancelled">Cancelled</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Origin</label>
                                            <input name="origin" class="form-control" required maxlength="180">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Destination</label>
                                            <input name="destination" class="form-control" required maxlength="180">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Stops</label>
                                            <input name="stops" class="form-control" maxlength="255">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Optimized Route</label>
                                            <input name="optimized_route" class="form-control" maxlength="255">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm" <?php echo !$fleetVehicles ? 'disabled' : ''; ?>>Create Route</button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="routes" hidden>
                                <form method="post" class="transport-card-body border rounded h-100">
                                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                    <input type="hidden" name="action" value="add_incident_report">
                                    <h3 class="h6 mb-3"><i class="fas fa-triangle-exclamation me-1 text-primary"></i>Incident</h3>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <label class="form-label">Vehicle</label>
                                            <select name="vehicle_id" class="form-select" required>
                                                <option value="">Vehicle</option>
                                                <?php foreach ($fleetVehicles as $vehicle): ?>
                                                    <option value="<?php echo (int)$vehicle['id']; ?>"><?php echo tm_h($vehicle['registration_no']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Instructor</label>
                                            <select name="instructor_id" class="form-select">
                                                <option value="">Not assigned</option>
                                                <?php foreach ($instructors as $instructor): ?>
                                                    <option value="<?php echo (int)$instructor['id']; ?>"><?php echo tm_h($instructor['full_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Time</label>
                                            <input type="datetime-local" name="incident_time" class="form-control" value="<?php echo tm_h(date('Y-m-d\TH:i')); ?>" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Location</label>
                                            <input name="incident_location" class="form-control" maxlength="180">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Status</label>
                                            <select name="incident_status" class="form-select">
                                                <option value="open">Open</option>
                                                <option value="in_review">In review</option>
                                                <option value="resolved">Resolved</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Snapshot</label>
                                            <input name="telematics_snapshot" class="form-control" maxlength="255">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Description</label>
                                            <input name="description" class="form-control" required maxlength="255">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm" <?php echo !$fleetVehicles ? 'disabled' : ''; ?>>Report Incident</button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="parts" hidden>
                                <form method="post" class="transport-card-body border rounded h-100">
                                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                    <input type="hidden" name="action" value="add_part">
                                    <h3 class="h6 mb-3"><i class="fas fa-gears me-1 text-primary"></i>Parts Inventory</h3>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <label class="form-label">Vehicle</label>
                                            <select name="vehicle_id" class="form-select">
                                                <option value="">General stock</option>
                                                <?php foreach ($fleetVehicles as $vehicle): ?>
                                                    <option value="<?php echo (int)$vehicle['id']; ?>"><?php echo tm_h($vehicle['registration_no']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Part Name</label>
                                            <input name="part_name" class="form-control" required maxlength="160">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Stock</label>
                                            <input type="number" min="0" name="stock_level" class="form-control" value="0">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Reorder</label>
                                            <input type="number" min="0" name="reorder_level" class="form-control" value="0">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Notes</label>
                                            <input name="part_notes" class="form-control" maxlength="255">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-primary btn-sm">Add Part</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'check'): ?>
                    <div class="<?php echo tm_tab_pane_class('check', $activeTab); ?>" id="tab-check">
                        <?php if (!$vehicles): ?>
                            <div class="transport-setup-note"><i class="fas fa-info-circle me-1"></i>Add or restore an available fleet vehicle before recording pre-use checks.</div>
                        <?php endif; ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_preuse_check">
                            <div class="col-md-3">
                                <label class="form-label">Vehicle</label>
                                <select name="vehicle_id" class="form-select" required>
                                    <option value="">Select vehicle</option>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <option value="<?php echo (int)$vehicle['id']; ?>"><?php echo tm_h($vehicle['registration_no'] . ' ' . ($vehicle['make_model'] ?? '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Instructor</label>
                                <select name="instructor_id" class="form-select">
                                    <option value="">Not assigned</option>
                                    <?php foreach ($instructors as $instructor): ?>
                                        <option value="<?php echo (int)$instructor['id']; ?>"><?php echo tm_h($instructor['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date</label>
                                <input type="date" name="checklist_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Odometer</label>
                                <input type="number" min="0" name="odometer" class="form-control" value="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Overall</label>
                                <select name="overall_status" class="form-select">
                                    <option value="fit">Fit</option>
                                    <option value="defect_reported">Defect reported</option>
                                    <option value="unfit">Unfit</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label d-block">Checklist</label>
                                <div class="d-flex flex-wrap gap-3">
                                    <label class="form-check"><input class="form-check-input" type="checkbox" name="tyres_ok" checked> Tyres</label>
                                    <label class="form-check"><input class="form-check-input" type="checkbox" name="lights_ok" checked> Lights</label>
                                    <label class="form-check"><input class="form-check-input" type="checkbox" name="brakes_ok" checked> Brakes</label>
                                    <label class="form-check"><input class="form-check-input" type="checkbox" name="fluids_ok" checked> Fluids</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Defects</label>
                                <input name="defects" class="form-control" maxlength="255" data-defect-details>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Action Taken</label>
                                <input name="action_taken" class="form-control" maxlength="255">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button class="btn btn-primary w-100" <?php echo !$vehicles ? 'disabled' : ''; ?>>Record Check</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'instructor'): ?>
                    <div class="<?php echo tm_tab_pane_class('instructor', $activeTab); ?>" id="tab-instructor">
                        <?php if (!$campuses): ?>
                            <div class="transport-setup-note"><i class="fas fa-info-circle me-1"></i>An active campus is required before adding instructors.</div>
                        <?php endif; ?>
                        <?php if (!$staffOptions): ?>
                            <div class="transport-setup-note"><i class="fas fa-info-circle me-1"></i>No staff records were found in the admin staff database.</div>
                        <?php endif; ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_instructor">
                            <div class="col-md-2">
                                <label class="form-label">Campus</label>
                                <select name="campus_id" class="form-select" required>
                                    <option value="">Campus</option>
                                    <?php foreach ($campuses as $campus): ?>
                                        <option value="<?php echo (int)$campus['id']; ?>"><?php echo tm_h($campus['campus_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Staff ID</label>
                                <select name="staff_id" id="transport-staff-select" class="form-select" required>
                                    <option value="">Select staff from admin database</option>
                                    <?php foreach ($staffOptions as $staff): ?>
                                        <?php
                                            $staffName = tm_person_full_name((string)$staff['Fname'], (string)$staff['Lname'], (string)$staff['staff_id']);
                                            $staffMeta = trim(implode(' / ', array_filter([(string)($staff['role'] ?? ''), (string)($staff['status'] ?? '')])));
                                            $staffLabel = (string)$staff['staff_id'] . ' - ' . $staffName . ($staffMeta !== '' ? ' (' . $staffMeta . ')' : '');
                                        ?>
                                        <option
                                            value="<?php echo tm_h($staff['staff_id']); ?>"
                                            data-full-name="<?php echo tm_h($staffName); ?>"
                                        ><?php echo tm_h($staffLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Full Name</label>
                                <input name="full_name" class="form-control" readonly maxlength="160">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Licence Classes</label>
                                <input name="license_classes" class="form-control" maxlength="120">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">RTSA Licence No.</label>
                                <input name="rtsa_license_no" class="form-control" maxlength="80">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">RTSA Expiry</label>
                                <input type="date" name="rtsa_expiry" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">TEVETA Accreditation</label>
                                <input name="teveta_accreditation_no" class="form-control" maxlength="80">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">TEVETA Expiry</label>
                                <input type="date" name="teveta_expiry" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">ZCILT Member No.</label>
                                <input name="zcilt_member_no" class="form-control" maxlength="80">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">Status</label>
                                <select name="instructor_status" class="form-select">
                                    <option value="active">Active</option>
                                    <option value="on_leave">On leave</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Notes</label>
                                <input name="instructor_notes" class="form-control" maxlength="255">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button class="btn btn-primary w-100" <?php echo (!$campuses || !$staffOptions) ? 'disabled' : ''; ?>>Add Instructor</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'client'): ?>
                    <div class="<?php echo tm_tab_pane_class('client', $activeTab); ?>" id="tab-client">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                            <input type="hidden" name="action" value="add_client">
                            <div class="col-md-3">
                                <label class="form-label">Company Name</label>
                                <input name="client_name" class="form-control" required maxlength="180" autocomplete="organization">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Contact Person</label>
                                <input name="contact_person" class="form-control" maxlength="140" autocomplete="name">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Phone</label>
                                <input type="tel" name="phone" class="form-control" maxlength="40" autocomplete="tel">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" maxlength="140" autocomplete="email">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Billing Address</label>
                                <input name="billing_address" class="form-control" maxlength="255" autocomplete="street-address">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button class="btn btn-primary w-100"><i class="fas fa-building me-1"></i>Save Client</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <?php if ($showLeftReports): ?>
            <div class="<?php echo $isOverviewPage && $showRightReports ? 'col-xl-7' : 'col-12'; ?>">
                <?php if ($isOverviewPage || $activeTab === 'cohort'): ?>
                <div class="transport-card mb-4">
                    <div class="transport-card-header"><span><i class="fas fa-layer-group me-2 text-primary"></i>Cohorts and RTSA Contact Hours</span></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-transport mb-0">
                            <thead class="table-light"><tr><th>Cohort</th><th>Program</th><th>Campus</th><th>Enrolled</th><th>Hours</th><th>Status</th><?php if (!$isOverviewPage): ?><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($cohortRows as $row): ?>
                                <?php
                                    $required = (float)$row['required_contact_hours'];
                                    $delivered = (float)$row['delivered_hours'];
                                    $pct = $required > 0 ? min(100, round(($delivered / $required) * 100)) : 0;
                                    $formId = 'cohort-edit-' . (int)$row['id'];
                                    $deleteFormId = 'cohort-delete-' . (int)$row['id'];
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" name="cohort_name" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['cohort_name']); ?>" maxlength="140" required>
                                            <div class="transport-inline-fields mt-1">
                                                <input form="<?php echo $formId; ?>" type="date" name="start_date" class="form-control form-control-sm" value="<?php echo tm_h($row['start_date']); ?>" required>
                                                <input form="<?php echo $formId; ?>" type="date" name="end_date" class="form-control form-control-sm" value="<?php echo tm_h($row['end_date']); ?>">
                                            </div>
                                            <input form="<?php echo $formId; ?>" type="hidden" name="intake_month" value="<?php echo tm_h($row['intake_month']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="cohort_notes" value="<?php echo tm_h($row['notes']); ?>">
                                        <?php else: ?>
                                            <strong><?php echo tm_h($row['cohort_name']); ?></strong><div class="text-muted small"><?php echo tm_h($row['start_date']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo tm_h($row['program_code']); ?><div class="text-muted small"><?php echo tm_h($row['program_name']); ?></div></td>
                                    <td><?php echo tm_h($row['campus_name']); ?></td>
                                    <td>
                                        <?php echo number_format((int)$row['enrolled_count']); ?> /
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" type="number" min="1" name="capacity" class="form-control form-control-sm transport-number-control" value="<?php echo (int)$row['capacity']; ?>">
                                        <?php else: ?>
                                            <?php echo number_format((int)$row['capacity']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="min-width:150px">
                                        <div class="d-flex justify-content-between small"><span><?php echo number_format($delivered, 1); ?></span><span><?php echo number_format($required, 1); ?></span></div>
                                        <div class="progress mini-progress"><div class="progress-bar" style="width: <?php echo $pct; ?>%"></div></div>
                                    </td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <select form="<?php echo $formId; ?>" name="cohort_status" class="form-select form-select-sm transport-crud-control">
                                                <option value="planning"<?php echo tm_select($row['status'], 'planning'); ?>>Planning</option>
                                                <option value="open"<?php echo tm_select($row['status'], 'open'); ?>>Open</option>
                                                <option value="in_progress"<?php echo tm_select($row['status'], 'in_progress'); ?>>In progress</option>
                                                <option value="completed"<?php echo tm_select($row['status'], 'completed'); ?>>Completed</option>
                                                <option value="cancelled"<?php echo tm_select($row['status'], 'cancelled'); ?>>Cancelled</option>
                                            </select>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark"><?php echo tm_h(str_replace('_', ' ', $row['status'])); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$isOverviewPage): ?>
                                    <td class="transport-action-cell">
                                        <form id="<?php echo $formId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_cohort">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-primary">Save</button>
                                        </form>
                                        <form id="<?php echo $deleteFormId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_cohort">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$cohortRows): ?><tr><td colspan="<?php echo !$isOverviewPage ? 7 : 6; ?>" class="text-center text-muted py-4">No transport cohorts recorded yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isOverviewPage || $activeTab === 'trainee'): ?>
                <div class="transport-card mb-4">
                    <div class="transport-card-header"><span><i class="fas fa-users me-2 text-primary"></i>Recent Trainee Enrolments</span></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-transport mb-0">
                            <thead class="table-light"><tr><th>Trainee</th><th>Cohort</th><th>Client</th><th>Medical</th><th>Fee / Paid</th><th>Status</th><?php if (!$isOverviewPage): ?><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($traineeRows as $row): ?>
                                <?php
                                    $formId = 'trainee-edit-' . (int)$row['enrollment_id'];
                                    $deleteFormId = 'trainee-delete-' . (int)$row['enrollment_id'];
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <strong><?php echo tm_h($row['first_name'] . ' ' . $row['last_name']); ?></strong>
                                            <div class="text-muted small"><?php echo tm_h($row['student_id']); ?><?php echo $row['phone'] ? ' | ' . tm_h($row['phone']) : ''; ?></div>
                                            <input form="<?php echo $formId; ?>" type="hidden" name="first_name" value="<?php echo tm_h($row['first_name']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="last_name" value="<?php echo tm_h($row['last_name']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="phone" value="<?php echo tm_h($row['phone']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="student_id" value="<?php echo tm_h($row['student_id']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="email" value="<?php echo tm_h($row['email']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="existing_license_class" value="<?php echo tm_h($row['existing_license_class']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="emergency_contact" value="<?php echo tm_h($row['emergency_contact']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="employer_name" value="<?php echo tm_h($row['employer_name']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="enrollment_notes" value="<?php echo tm_h($row['enrollment_notes']); ?>">
                                        <?php else: ?>
                                            <strong><?php echo tm_h($row['first_name'] . ' ' . $row['last_name']); ?></strong><div class="text-muted small"><?php echo tm_h($row['phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo tm_h($row['program_code']); ?><div class="text-muted small"><?php echo tm_h($row['cohort_name']); ?></div></td>
                                    <td><?php echo tm_h($row['client_name'] ?: 'Individual'); ?></td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <select form="<?php echo $formId; ?>" name="medical_clearance_status" class="form-select form-select-sm transport-crud-control">
                                                <option value="pending"<?php echo tm_select($row['medical_clearance_status'], 'pending'); ?>>Pending</option>
                                                <option value="cleared"<?php echo tm_select($row['medical_clearance_status'], 'cleared'); ?>>Cleared</option>
                                                <option value="not_required"<?php echo tm_select($row['medical_clearance_status'], 'not_required'); ?>>Not required</option>
                                                <option value="failed"<?php echo tm_select($row['medical_clearance_status'], 'failed'); ?>>Failed</option>
                                            </select>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark"><?php echo tm_h(str_replace('_', ' ', $row['medical_clearance_status'])); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $rowBal = max(0, (float)$row['fee_amount'] - (float)$row['amount_paid']); ?>
                                        <?php if (!$isOverviewPage): ?>
                                            <div class="small">
                                                <div>Fee: <strong>ZMW <?php echo number_format((float)$row['fee_amount'], 2); ?></strong></div>
                                                <div class="text-success">Paid: ZMW <?php echo number_format((float)$row['amount_paid'], 2); ?></div>
                                                <div class="<?php echo $rowBal > 0 ? 'text-danger' : 'text-success'; ?>">Bal: ZMW <?php echo number_format($rowBal, 2); ?></div>
                                                <div class="form-text mb-0">Fee from schedule; payments via Payments &amp; Booking.</div>
                                            </div>
                                        <?php else: ?>
                                            ZMW <?php echo number_format($rowBal, 2); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <select form="<?php echo $formId; ?>" name="enrollment_status" class="form-select form-select-sm transport-crud-control">
                                                <option value="enrolled"<?php echo tm_select($row['status'], 'enrolled'); ?>>Enrolled</option>
                                                <option value="active"<?php echo tm_select($row['status'], 'active'); ?>>Active</option>
                                                <option value="completed"<?php echo tm_select($row['status'], 'completed'); ?>>Completed</option>
                                                <option value="failed"<?php echo tm_select($row['status'], 'failed'); ?>>Failed</option>
                                                <option value="withdrawn"<?php echo tm_select($row['status'], 'withdrawn'); ?>>Withdrawn</option>
                                            </select>
                                        <?php else: ?>
                                            <span class="badge bg-primary"><?php echo tm_h($row['status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$isOverviewPage): ?>
                                    <td class="transport-action-cell">
                                        <form id="<?php echo $formId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_trainee">
                                            <input type="hidden" name="trainee_id" value="<?php echo (int)$row['trainee_id']; ?>">
                                            <input type="hidden" name="enrollment_id" value="<?php echo (int)$row['enrollment_id']; ?>">
                                            <button class="btn btn-sm btn-primary">Save</button>
                                        </form>
                                        <form id="<?php echo $deleteFormId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_trainee">
                                            <input type="hidden" name="enrollment_id" value="<?php echo (int)$row['enrollment_id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger">Withdraw</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$traineeRows): ?><tr><td colspan="<?php echo !$isOverviewPage ? 7 : 6; ?>" class="text-center text-muted py-4">No transport trainees enrolled yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isOverviewPage || $activeTab === 'session'): ?>
                <div class="transport-card">
                    <div class="transport-card-header"><span><i class="fas fa-calendar-alt me-2 text-primary"></i><?php echo $activeTab === 'session' ? 'Recent & Upcoming Sessions' : 'Upcoming Sessions'; ?></span></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-transport mb-0">
                            <thead class="table-light"><tr><th>Date</th><th>Time</th><th>Cohort</th><th>Instructor</th><th>Vehicle</th><th>Hours</th><?php if (!$isOverviewPage): ?><th>Status</th><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($sessionRows as $row): ?>
                                <?php
                                    $formId = 'session-edit-' . (int)$row['id'];
                                    $deleteFormId = 'session-delete-' . (int)$row['id'];
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" type="date" name="session_date" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['session_date']); ?>" required>
                                        <?php else: ?>
                                            <?php echo tm_h($row['session_date']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <div class="transport-inline-fields">
                                                <input form="<?php echo $formId; ?>" type="time" name="start_time" class="form-control form-control-sm" value="<?php echo tm_h(substr($row['start_time'], 0, 5)); ?>" required>
                                                <input form="<?php echo $formId; ?>" type="time" name="end_time" class="form-control form-control-sm" value="<?php echo tm_h(substr($row['end_time'], 0, 5)); ?>" required>
                                            </div>
                                        <?php else: ?>
                                            <?php echo tm_h(substr($row['start_time'], 0, 5) . ' - ' . substr($row['end_time'], 0, 5)); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo tm_h($row['program_code']); ?><div class="text-muted small"><?php echo tm_h($row['cohort_name']); ?></div></td>
                                    <td><?php echo tm_h($row['instructor_name']); ?></td>
                                    <td><?php echo tm_h($row['registration_no'] ?: 'None'); ?></td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" type="number" step="0.25" min="0" name="contact_hours" class="form-control form-control-sm transport-number-control" value="<?php echo tm_h($row['contact_hours']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="location" value="<?php echo tm_h($row['location']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="session_notes" value="<?php echo tm_h($row['notes']); ?>">
                                        <?php else: ?>
                                            <?php echo number_format((float)$row['contact_hours'], 1); ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$isOverviewPage): ?>
                                    <td>
                                        <select form="<?php echo $formId; ?>" name="session_status" class="form-select form-select-sm transport-crud-control">
                                            <option value="scheduled"<?php echo tm_select($row['status'], 'scheduled'); ?>>Scheduled</option>
                                            <option value="completed"<?php echo tm_select($row['status'], 'completed'); ?>>Completed</option>
                                            <option value="cancelled"<?php echo tm_select($row['status'], 'cancelled'); ?>>Cancelled</option>
                                        </select>
                                    </td>
                                    <td class="transport-action-cell">
                                        <form id="<?php echo $formId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_session">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-primary">Save</button>
                                        </form>
                                        <form id="<?php echo $deleteFormId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_session">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$sessionRows): ?><tr><td colspan="<?php echo !$isOverviewPage ? 8 : 6; ?>" class="text-center text-muted py-4">No upcoming sessions scheduled.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($showRightReports): ?>
            <div class="<?php echo $isOverviewPage && $showLeftReports ? 'col-xl-5' : 'col-12'; ?>">
                <?php if ($isOverviewPage || $activeTab === 'fleet'): ?>
                <div class="transport-card mb-4 fleet-tab-panel" data-fleet-panel="assets">
                    <div class="transport-card-header"><span><i class="fas fa-truck me-2 text-primary"></i>Fleet Compliance</span></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-transport mb-0">
                            <thead class="table-light"><tr><th>Vehicle</th><th>Status</th><th>Fitness</th><th>Insurance</th><th>Last Check</th><?php if (!$isOverviewPage): ?><th>Mileage</th><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($vehicleRows as $row): ?>
                                <?php
                                    $formId = 'vehicle-edit-' . (int)$row['id'];
                                    $deleteFormId = 'vehicle-delete-' . (int)$row['id'];
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" name="registration_no" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['registration_no']); ?>" maxlength="40" required>
                                            <div class="text-muted small"><?php echo tm_h($row['make_model'] ?: $row['vehicle_type']); ?></div>
                                            <input form="<?php echo $formId; ?>" type="hidden" name="asset_tag" value="<?php echo tm_h($row['asset_tag']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="vehicle_type" value="<?php echo tm_h($row['vehicle_type']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="make_model" value="<?php echo tm_h($row['make_model']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="supported_license_class" value="<?php echo tm_h($row['supported_license_class']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="next_service_due" value="<?php echo tm_h($row['next_service_due']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="vehicle_notes" value="<?php echo tm_h($row['notes']); ?>">
                                        <?php else: ?>
                                            <strong><?php echo tm_h($row['registration_no']); ?></strong><div class="text-muted small"><?php echo tm_h($row['make_model'] ?: $row['vehicle_type']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <select form="<?php echo $formId; ?>" name="vehicle_status" class="form-select form-select-sm transport-crud-control">
                                                <option value="available"<?php echo tm_select($row['status'], 'available'); ?>>Available</option>
                                                <option value="assigned"<?php echo tm_select($row['status'], 'assigned'); ?>>Assigned</option>
                                                <option value="maintenance"<?php echo tm_select($row['status'], 'maintenance'); ?>>Maintenance</option>
                                                <option value="unavailable"<?php echo tm_select($row['status'], 'unavailable'); ?>>Unavailable</option>
                                            </select>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark"><?php echo tm_h($row['status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" type="date" name="fitness_expiry" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['fitness_expiry']); ?>">
                                        <?php else: ?>
                                            <?php echo tm_date_badge($row['fitness_expiry']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" type="date" name="insurance_expiry" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['insurance_expiry']); ?>">
                                        <?php else: ?>
                                            <?php echo tm_date_badge($row['insurance_expiry']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo tm_h($row['last_check_date'] ?: 'None'); ?></td>
                                    <?php if (!$isOverviewPage): ?>
                                    <td><input form="<?php echo $formId; ?>" type="number" min="0" name="current_mileage" class="form-control form-control-sm transport-number-control" value="<?php echo (int)$row['current_mileage']; ?>"></td>
                                    <td class="transport-action-cell">
                                        <form id="<?php echo $formId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_vehicle">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-primary">Save</button>
                                        </form>
                                        <form id="<?php echo $deleteFormId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_vehicle">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger">Remove</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$vehicleRows): ?><tr><td colspan="<?php echo !$isOverviewPage ? 7 : 5; ?>" class="text-center text-muted py-4">No fleet assets recorded yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$isOverviewPage && $activeTab === 'fleet'): ?>
                <div class="row g-4">
                    <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="logs" hidden>
                        <div class="transport-card mb-4">
                            <div class="transport-card-header"><span><i class="fas fa-satellite-dish me-2 text-primary"></i>Recent Telematics</span></div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle table-transport mb-0">
                                    <thead class="table-light"><tr><th>Vehicle</th><th>Time</th><th>Location</th><th>Speed</th><th>Events</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($telematicsRows as $row): ?>
                                        <tr>
                                            <td><?php echo tm_h($row['registration_no']); ?><div class="text-muted small"><?php echo tm_h($row['device_identifier']); ?></div></td>
                                            <td><?php echo tm_h($row['recorded_at']); ?></td>
                                            <td><?php echo tm_h($row['location']); ?></td>
                                            <td><?php echo $row['speed_kph'] !== null ? number_format((float)$row['speed_kph'], 1) . ' kph' : '-'; ?></td>
                                            <td><?php echo tm_h($row['behavior_events'] ?: '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$telematicsRows): ?><tr><td colspan="5" class="text-center text-muted py-4">No telemetry logs recorded yet.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="logs" hidden>
                        <div class="transport-card mb-4">
                            <div class="transport-card-header"><span><i class="fas fa-screwdriver-wrench me-2 text-primary"></i>Maintenance Logs</span></div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle table-transport mb-0">
                                    <thead class="table-light"><tr><th>Vehicle</th><th>Service</th><th>Next</th><th>Tasks</th><th>Status</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($maintenanceRows as $row): ?>
                                        <tr>
                                            <td><?php echo tm_h($row['registration_no']); ?></td>
                                            <td><?php echo tm_h($row['service_date']); ?></td>
                                            <td><?php echo tm_h($row['next_service_date'] ?: '-'); ?></td>
                                            <td><?php echo tm_h($row['completed_tasks'] ?: $row['tasks_due']); ?></td>
                                            <td><span class="badge bg-light text-dark"><?php echo tm_h($row['status']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$maintenanceRows): ?><tr><td colspan="5" class="text-center text-muted py-4">No maintenance logs recorded yet.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="logs" hidden>
                        <div class="transport-card mb-4">
                            <div class="transport-card-header"><span><i class="fas fa-gas-pump me-2 text-primary"></i>Fuel / Battery Logs</span></div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle table-transport mb-0">
                                    <thead class="table-light"><tr><th>Vehicle</th><th>Date</th><th>Type</th><th>Added</th><th>Level</th><th>Odometer</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($fuelRows as $row): ?>
                                        <tr>
                                            <td><?php echo tm_h($row['registration_no']); ?></td>
                                            <td><?php echo tm_h($row['fuel_date']); ?></td>
                                            <td><?php echo tm_h($row['energy_type']); ?></td>
                                            <td><?php echo number_format((float)$row['amount_added'], 2); ?></td>
                                            <td><?php echo $row['level_percent'] !== null ? number_format((float)$row['level_percent'], 1) . '%' : '-'; ?></td>
                                            <td><?php echo $row['odometer'] !== null ? number_format((int)$row['odometer']) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$fuelRows): ?><tr><td colspan="6" class="text-center text-muted py-4">No fuel or battery logs recorded yet.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="routes" hidden>
                        <div class="transport-card mb-4">
                            <div class="transport-card-header"><span><i class="fas fa-route me-2 text-primary"></i>Route Plans</span></div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle table-transport mb-0">
                                    <thead class="table-light"><tr><th>Vehicle</th><th>Date</th><th>Route</th><th>Status</th><th>Action</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($routeRows as $row): ?>
                                        <?php $routeFormId = 'route-status-' . (int)$row['id']; ?>
                                        <tr>
                                            <td><?php echo tm_h($row['registration_no']); ?></td>
                                            <td><?php echo tm_h($row['planned_date']); ?></td>
                                            <td><?php echo tm_h($row['origin'] . ' -> ' . $row['destination']); ?><div class="text-muted small"><?php echo tm_h($row['optimized_route']); ?></div></td>
                                            <td>
                                                <select form="<?php echo $routeFormId; ?>" name="route_status" class="form-select form-select-sm transport-crud-control">
                                                    <option value="planned"<?php echo tm_select($row['status'], 'planned'); ?>>Planned</option>
                                                    <option value="in_progress"<?php echo tm_select($row['status'], 'in_progress'); ?>>In progress</option>
                                                    <option value="completed"<?php echo tm_select($row['status'], 'completed'); ?>>Completed</option>
                                                    <option value="cancelled"<?php echo tm_select($row['status'], 'cancelled'); ?>>Cancelled</option>
                                                </select>
                                            </td>
                                            <td>
                                                <form id="<?php echo $routeFormId; ?>" method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="update_route_status">
                                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                    <button class="btn btn-sm btn-primary">Save</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$routeRows): ?><tr><td colspan="5" class="text-center text-muted py-4">No route plans created yet.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="routes" hidden>
                        <div class="transport-card mb-4">
                            <div class="transport-card-header"><span><i class="fas fa-triangle-exclamation me-2 text-primary"></i>Incident Reports</span></div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle table-transport mb-0">
                                    <thead class="table-light"><tr><th>Vehicle</th><th>Time</th><th>Description</th><th>Status</th><th>Action</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($incidentRows as $row): ?>
                                        <?php $incidentFormId = 'incident-status-' . (int)$row['id']; ?>
                                        <tr>
                                            <td><?php echo tm_h($row['registration_no']); ?><div class="text-muted small"><?php echo tm_h($row['instructor_name'] ?: 'No instructor'); ?></div></td>
                                            <td><?php echo tm_h($row['incident_time']); ?></td>
                                            <td><?php echo tm_h($row['description']); ?><div class="text-muted small"><?php echo tm_h($row['location']); ?></div></td>
                                            <td>
                                                <select form="<?php echo $incidentFormId; ?>" name="incident_status" class="form-select form-select-sm transport-crud-control">
                                                    <option value="open"<?php echo tm_select($row['status'], 'open'); ?>>Open</option>
                                                    <option value="in_review"<?php echo tm_select($row['status'], 'in_review'); ?>>In review</option>
                                                    <option value="resolved"<?php echo tm_select($row['status'], 'resolved'); ?>>Resolved</option>
                                                </select>
                                            </td>
                                            <td>
                                                <form id="<?php echo $incidentFormId; ?>" method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="update_incident_status">
                                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                    <button class="btn btn-sm btn-primary">Save</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$incidentRows): ?><tr><td colspan="5" class="text-center text-muted py-4">No incident reports recorded yet.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-6 fleet-tab-panel" data-fleet-panel="parts" hidden>
                        <div class="transport-card mb-4">
                            <div class="transport-card-header"><span><i class="fas fa-gears me-2 text-primary"></i>Parts Inventory</span></div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle table-transport mb-0">
                                    <thead class="table-light"><tr><th>Part</th><th>Vehicle</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($partRows as $row): ?>
                                        <?php
                                            $partFormId = 'part-edit-' . (int)$row['id'];
                                            $partUseFormId = 'part-use-' . (int)$row['id'];
                                        ?>
                                        <tr>
                                            <td><?php echo tm_h($row['part_name']); ?></td>
                                            <td>
                                                <select form="<?php echo $partFormId; ?>" name="vehicle_id" class="form-select form-select-sm transport-crud-control">
                                                    <option value="">General stock</option>
                                                    <?php foreach ($fleetVehicles as $vehicle): ?>
                                                        <option value="<?php echo (int)$vehicle['id']; ?>"<?php echo (int)$row['vehicle_id'] === (int)$vehicle['id'] ? ' selected' : ''; ?>><?php echo tm_h($vehicle['registration_no']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input form="<?php echo $partFormId; ?>" type="number" min="0" name="stock_level" class="form-control form-control-sm transport-number-control" value="<?php echo (int)$row['stock_level']; ?>">
                                                <input form="<?php echo $partFormId; ?>" type="number" min="0" name="reorder_level" class="form-control form-control-sm transport-number-control mt-1" value="<?php echo (int)$row['reorder_level']; ?>">
                                            </td>
                                            <td>
                                                <select form="<?php echo $partFormId; ?>" name="part_status" class="form-select form-select-sm transport-crud-control">
                                                    <option value="available"<?php echo tm_select($row['status'], 'available'); ?>>Available</option>
                                                    <option value="assigned"<?php echo tm_select($row['status'], 'assigned'); ?>>Assigned</option>
                                                    <option value="reorder"<?php echo tm_select($row['status'], 'reorder'); ?>>Reorder</option>
                                                    <option value="retired"<?php echo tm_select($row['status'], 'retired'); ?>>Retired</option>
                                                </select>
                                            </td>
                                            <td class="transport-action-cell">
                                                <form id="<?php echo $partFormId; ?>" method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="update_part">
                                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                    <button class="btn btn-sm btn-primary">Save</button>
                                                </form>
                                                <form id="<?php echo $partUseFormId; ?>" method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="use_part">
                                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                    <input type="hidden" name="vehicle_id" value="<?php echo (int)$row['vehicle_id']; ?>">
                                                    <button class="btn btn-sm btn-outline-secondary" <?php echo (int)$row['stock_level'] <= 0 ? 'disabled' : ''; ?>>Use</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$partRows): ?><tr><td colspan="5" class="text-center text-muted py-4">No parts inventory recorded yet.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isOverviewPage || $activeTab === 'instructor'): ?>
                <div class="transport-card mb-4">
                    <div class="transport-card-header"><span><i class="fas fa-id-card me-2 text-primary"></i>Instructor Accreditation</span></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-transport mb-0">
                            <thead class="table-light"><tr><th>Instructor</th><th>Campus</th><th>RTSA</th><th>TEVETA</th><th>Status</th><?php if (!$isOverviewPage): ?><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($instructorRows as $row): ?>
                                <?php
                                    $formId = 'instructor-edit-' . (int)$row['id'];
                                    $deleteFormId = 'instructor-delete-' . (int)$row['id'];
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <strong><?php echo tm_h($row['full_name']); ?></strong>
                                            <div class="text-muted small"><?php echo tm_h($row['staff_id']); ?></div>
                                            <input form="<?php echo $formId; ?>" type="hidden" name="full_name" value="<?php echo tm_h($row['full_name']); ?>">
                                            <input form="<?php echo $formId; ?>" name="license_classes" class="form-control form-control-sm transport-crud-control mt-1" value="<?php echo tm_h($row['license_classes']); ?>" maxlength="120">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="staff_id" value="<?php echo tm_h($row['staff_id']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="rtsa_license_no" value="<?php echo tm_h($row['rtsa_license_no']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="teveta_accreditation_no" value="<?php echo tm_h($row['teveta_accreditation_no']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="zcilt_member_no" value="<?php echo tm_h($row['zcilt_member_no']); ?>">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="instructor_notes" value="<?php echo tm_h($row['notes']); ?>">
                                        <?php else: ?>
                                            <strong><?php echo tm_h($row['full_name']); ?></strong><div class="text-muted small"><?php echo tm_h($row['license_classes']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo tm_h($row['campus_name']); ?></td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" type="date" name="rtsa_expiry" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['rtsa_expiry']); ?>">
                                        <?php else: ?>
                                            <?php echo tm_date_badge($row['rtsa_expiry']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" type="date" name="teveta_expiry" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['teveta_expiry']); ?>">
                                        <?php else: ?>
                                            <?php echo tm_date_badge($row['teveta_expiry']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <select form="<?php echo $formId; ?>" name="instructor_status" class="form-select form-select-sm transport-crud-control">
                                                <option value="active"<?php echo tm_select($row['status'], 'active'); ?>>Active</option>
                                                <option value="on_leave"<?php echo tm_select($row['status'], 'on_leave'); ?>>On leave</option>
                                                <option value="inactive"<?php echo tm_select($row['status'], 'inactive'); ?>>Inactive</option>
                                            </select>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark"><?php echo tm_h(str_replace('_', ' ', $row['status'])); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$isOverviewPage): ?>
                                    <td class="transport-action-cell">
                                        <form id="<?php echo $formId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_instructor">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-primary">Save</button>
                                        </form>
                                        <form id="<?php echo $deleteFormId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_instructor">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger">Remove</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$instructorRows): ?><tr><td colspan="<?php echo !$isOverviewPage ? 6 : 5; ?>" class="text-center text-muted py-4">No instructors recorded yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isOverviewPage || $activeTab === 'check'): ?>
                <div class="transport-card mb-4">
                    <div class="transport-card-header"><span><i class="fas fa-clipboard-check me-2 text-primary"></i>Recent Pre-use Checks</span></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-transport mb-0">
                            <thead class="table-light"><tr><th>Date</th><th>Vehicle</th><th>Instructor</th><th>Status</th><th>Defects</th><?php if (!$isOverviewPage): ?><th>Checklist</th><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($checkRows as $row): ?>
                                <?php
                                    $formId = 'check-edit-' . (int)$row['id'];
                                    $deleteFormId = 'check-delete-' . (int)$row['id'];
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" type="date" name="checklist_date" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['checklist_date']); ?>" required>
                                            <input form="<?php echo $formId; ?>" type="number" min="0" name="odometer" class="form-control form-control-sm transport-crud-control mt-1" value="<?php echo (int)$row['odometer']; ?>">
                                        <?php else: ?>
                                            <?php echo tm_h($row['checklist_date']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo tm_h($row['registration_no']); ?></td>
                                    <td><?php echo tm_h($row['instructor_name'] ?: 'None'); ?></td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <select form="<?php echo $formId; ?>" name="overall_status" class="form-select form-select-sm transport-crud-control">
                                                <option value="fit"<?php echo tm_select($row['overall_status'], 'fit'); ?>>Fit</option>
                                                <option value="defect_reported"<?php echo tm_select($row['overall_status'], 'defect_reported'); ?>>Defect reported</option>
                                                <option value="unfit"<?php echo tm_select($row['overall_status'], 'unfit'); ?>>Unfit</option>
                                            </select>
                                        <?php else: ?>
                                            <span class="badge <?php echo $row['overall_status'] === 'fit' ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo tm_h(str_replace('_', ' ', $row['overall_status'])); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" name="defects" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['defects']); ?>" maxlength="255">
                                            <input form="<?php echo $formId; ?>" type="hidden" name="action_taken" value="<?php echo tm_h($row['action_taken']); ?>">
                                        <?php else: ?>
                                            <?php echo tm_h($row['defects'] ?: 'None'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$isOverviewPage): ?>
                                    <td>
                                        <div class="transport-checklist-mini">
                                            <label><input form="<?php echo $formId; ?>" type="checkbox" name="tyres_ok"<?php echo tm_checked($row['tyres_ok']); ?>> Tyres</label>
                                            <label><input form="<?php echo $formId; ?>" type="checkbox" name="lights_ok"<?php echo tm_checked($row['lights_ok']); ?>> Lights</label>
                                            <label><input form="<?php echo $formId; ?>" type="checkbox" name="brakes_ok"<?php echo tm_checked($row['brakes_ok']); ?>> Brakes</label>
                                            <label><input form="<?php echo $formId; ?>" type="checkbox" name="fluids_ok"<?php echo tm_checked($row['fluids_ok']); ?>> Fluids</label>
                                        </div>
                                    </td>
                                    <td class="transport-action-cell">
                                        <form id="<?php echo $formId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_preuse_check">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-primary">Save</button>
                                        </form>
                                        <form id="<?php echo $deleteFormId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_preuse_check">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$checkRows): ?><tr><td colspan="<?php echo !$isOverviewPage ? 7 : 5; ?>" class="text-center text-muted py-4">No pre-use checks recorded yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isOverviewPage || $activeTab === 'client'): ?>
                <div class="transport-card">
                    <div class="transport-card-header"><span><i class="fas fa-building me-2 text-primary"></i>Corporate Training Clients</span></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-transport mb-0">
                            <thead class="table-light"><tr><th>Client</th><th>Contact</th><th>Active</th><th>Outstanding</th><?php if (!$isOverviewPage): ?><th>Status</th><th>Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($clientRows as $row): ?>
                                <?php
                                    $formId = 'client-edit-' . (int)$row['id'];
                                    $deleteFormId = 'client-delete-' . (int)$row['id'];
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" name="client_name" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['client_name']); ?>" maxlength="180" required>
                                            <input form="<?php echo $formId; ?>" name="billing_address" class="form-control form-control-sm transport-crud-control mt-1" value="<?php echo tm_h($row['billing_address']); ?>" maxlength="255" placeholder="Billing address">
                                        <?php else: ?>
                                            <strong><?php echo tm_h($row['client_name']); ?></strong>
                                            <?php if (!empty($row['billing_address'])): ?><div class="text-muted small"><?php echo tm_h($row['billing_address']); ?></div><?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isOverviewPage): ?>
                                            <input form="<?php echo $formId; ?>" name="contact_person" class="form-control form-control-sm transport-crud-control" value="<?php echo tm_h($row['contact_person']); ?>" maxlength="140">
                                            <input form="<?php echo $formId; ?>" name="phone" class="form-control form-control-sm transport-crud-control mt-1" value="<?php echo tm_h($row['phone']); ?>" maxlength="40">
                                            <input form="<?php echo $formId; ?>" type="email" name="email" class="form-control form-control-sm transport-crud-control mt-1" value="<?php echo tm_h($row['email']); ?>" maxlength="140">
                                        <?php else: ?>
                                            <?php echo tm_h($row['contact_person'] ?: $row['phone']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo number_format((int)$row['active_enrollments']); ?></td>
                                    <td>ZMW <?php echo number_format((float)$row['outstanding_balance'], 2); ?></td>
                                    <?php if (!$isOverviewPage): ?>
                                    <td>
                                        <select form="<?php echo $formId; ?>" name="client_status" class="form-select form-select-sm transport-crud-control">
                                            <option value="active"<?php echo tm_select($row['status'], 'active'); ?>>Active</option>
                                            <option value="inactive"<?php echo tm_select($row['status'], 'inactive'); ?>>Inactive</option>
                                        </select>
                                    </td>
                                    <td class="transport-action-cell">
                                        <form id="<?php echo $formId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="update_client">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-primary">Save</button>
                                        </form>
                                        <form id="<?php echo $deleteFormId; ?>" method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="delete_client">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger">Remove</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$clientRows): ?><tr><td colspan="<?php echo !$isOverviewPage ? 6 : 4; ?>" class="text-center text-muted py-4">No corporate clients recorded yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var intelligenceButton = document.getElementById('transport-ai-generate');
    if (intelligenceButton) {
        intelligenceButton.addEventListener('click', function () {
            var brief = document.getElementById('transport-ai-brief');
            var meta = document.getElementById('transport-ai-meta');
            intelligenceButton.disabled = true;
            intelligenceButton.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Analysing';
            brief.textContent = 'Reviewing current aggregate transport signals…';
            fetch('/wucportal/transport/api/intelligence.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
                body: new URLSearchParams({csrf_token: <?php echo json_encode($csrfToken); ?>})
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data.ok) throw new Error(data.message || 'Unable to generate the briefing.');
                    return data;
                });
            }).then(function (data) {
                brief.textContent = data.briefing.text;
                meta.textContent = data.briefing.source === 'local_ai' ? 'Generated locally with ' + data.briefing.model : 'Generated by the transport rules engine';
            }).catch(function (error) {
                brief.textContent = error.message;
                meta.textContent = 'The live risk priorities remain available.';
            }).finally(function () {
                intelligenceButton.disabled = false;
                intelligenceButton.innerHTML = 'Regenerate';
            });
        });
    }

    function bindSelectAutofill(selectId, fieldMap) {
        var select = document.getElementById(selectId);
        if (!select) {
            return;
        }
        var form = select.closest('form');
        if (!form) {
            return;
        }
        function updateFields() {
            var option = select.options[select.selectedIndex];
            Object.keys(fieldMap).forEach(function (fieldName) {
                var input = form.querySelector('[name="' + fieldName + '"]');
                if (!input) {
                    return;
                }
                input.value = option && option.value ? (option.getAttribute(fieldMap[fieldName]) || '') : '';
            });
        }
        select.addEventListener('change', updateFields);
        updateFields();
    }

    bindSelectAutofill('transport-student-select', {
        first_name: 'data-first-name',
        last_name: 'data-last-name',
        phone: 'data-phone',
        email: 'data-email',
        emergency_contact: 'data-emergency'
    });
    bindSelectAutofill('transport-staff-select', {
        full_name: 'data-full-name'
    });

    document.querySelectorAll('form').forEach(function (form) {
        var status = form.querySelector('[name="overall_status"]');
        var defects = form.querySelector('[name="defects"]');
        if (!status || !defects) {
            return;
        }
        function syncDefectRequirement() {
            var needsDefects = status.value !== 'fit';
            defects.required = needsDefects;
            defects.setAttribute('aria-required', needsDefects ? 'true' : 'false');
            defects.placeholder = needsDefects ? 'Describe the defect before saving' : '';
        }
        status.addEventListener('change', syncDefectRequirement);
        syncDefectRequirement();
    });

    document.querySelectorAll('form').forEach(function (form) {
        var start = form.querySelector('[name="start_time"]');
        var end = form.querySelector('[name="end_time"]');
        var hours = form.querySelector('[name="contact_hours"]');
        if (!start || !end || !hours) {
            return;
        }
        function minutesFromTime(value) {
            var parts = (value || '').split(':');
            if (parts.length < 2) {
                return null;
            }
            var hour = parseInt(parts[0], 10);
            var minute = parseInt(parts[1], 10);
            if (Number.isNaN(hour) || Number.isNaN(minute)) {
                return null;
            }
            return (hour * 60) + minute;
        }
        function syncContactHours() {
            var startMinutes = minutesFromTime(start.value);
            var endMinutes = minutesFromTime(end.value);
            if (startMinutes === null || endMinutes === null || endMinutes <= startMinutes) {
                return;
            }
            if (parseFloat(hours.value || '0') > 0 && hours.dataset.autoCalculated !== 'true') {
                return;
            }
            hours.value = ((endMinutes - startMinutes) / 60).toFixed(2);
            hours.dataset.autoCalculated = 'true';
        }
        hours.addEventListener('input', function () {
            hours.dataset.autoCalculated = parseFloat(hours.value || '0') > 0 ? 'false' : 'true';
        });
        start.addEventListener('change', syncContactHours);
        end.addEventListener('change', syncContactHours);
        syncContactHours();
    });

    var fleetRoot = document.getElementById('tab-fleet');
    if (!fleetRoot) {
        return;
    }

    var fleetPage = fleetRoot.closest('.transport-page') || document;
    var buttons = Array.prototype.slice.call(fleetRoot.querySelectorAll('[data-fleet-tab]'));
    var panels = Array.prototype.slice.call(fleetPage.querySelectorAll('[data-fleet-panel]'));
    var storageKey = 'wuc.transport.fleet.activeTab';
    var validTabs = buttons.map(function (button) { return button.getAttribute('data-fleet-tab'); });

    function setFleetTab(tab) {
        if (validTabs.indexOf(tab) === -1) {
            tab = 'assets';
        }
        buttons.forEach(function (button) {
            var active = button.getAttribute('data-fleet-tab') === tab;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-fleet-panel') !== tab;
        });
        try {
            window.localStorage.setItem(storageKey, tab);
        } catch (e) {}
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            setFleetTab(button.getAttribute('data-fleet-tab'));
        });
    });

    var initialTab = 'assets';
    try {
        initialTab = window.localStorage.getItem(storageKey) || initialTab;
    } catch (e) {}
    setFleetTab(initialTab);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
