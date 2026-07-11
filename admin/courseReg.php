<?php
include "includes/admin.php";
require_once __DIR__ . '/../includes/helpers/academic_structure_helpers.php';

// Link the admin dashboard stylesheet
// Enable error reporting only in development mode
$isDev = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1']);
if ($isDev) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Check database connection
if (!isset($db) || $db->connect_error) {
    echo '<div class="alert alert-danger">
            <strong>Database Error:</strong> Unable to connect to database. 
            ' . (isset($db) ? 'Error: ' . htmlspecialchars($db->connect_error) : 'Connection not established') . '
          </div>';
    exit;
}

// Initialize variables
$records = [];
$searchPerformed = false;
$studentBalance = 0;
$studentType = 'Regular';
$programs = [];
$studentInfo = null;
$periodType = 'semester';
$periodLabel = 'Semester';
$resolvedProgramCode = '';
$academicYear = '';
$semesterRegistrationId = null;
$registrationReady = false;
$registrationWarning = '';
$existingCourseCodes = [];

function course_reg_table_exists(mysqli $db, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    $exists = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $cache[$table] = $exists;
}

function course_reg_columns(mysqli $db, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $columns = [];
    if (!course_reg_table_exists($db, $table)) {
        return $cache[$table] = $columns;
    }
    if ($res = $db->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($row = $res->fetch_assoc()) {
            $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
        }
        $res->free();
    }
    return $cache[$table] = $columns;
}

function course_reg_column_exists(mysqli $db, string $table, string $column): bool {
    $columns = course_reg_columns($db, $table);
    return isset($columns[strtolower($column)]);
}

function course_reg_period_mode(mysqli $db, string $programCode): string {
    if ($programCode === '') {
        return 'semester';
    }
    if (function_exists('wuc_program_structure_type')) {
        $structure = wuc_program_structure_type($db, $programCode);
        if ($structure === 'TERM_BASED') {
            return 'term';
        }
        if ($structure === 'TRADE_TEST_LEVEL') {
            return 'trade_test_level';
        }
        if ($structure === 'SHORT_COURSE') {
            return 'short_course_cycle';
        }
        if ($structure === 'SEMESTER_BASED') {
            return 'semester';
        }
    }
    $mode = 'semester';
    $programCols = course_reg_columns($db, 'programs');
    $modeExpr = isset($programCols['period_mode'])
        ? "COALESCE(period_mode, 'semester')"
        : (isset($programCols['study_mode']) ? "COALESCE(study_mode, 'semester')" : "'semester'");
    if ($stmt = $db->prepare("SELECT {$modeExpr} AS period_mode FROM programs WHERE program_code = ? LIMIT 1")) {
        $stmt->bind_param('s', $programCode);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $raw = strtolower(trim((string)($row['period_mode'] ?? '')));
            $mode = $raw === 'term' ? 'term' : 'semester';
        }
        $stmt->close();
    }
    return $mode;
}

function course_reg_period_label(string $periodType): string {
    if ($periodType === 'term') {
        return 'Term';
    }
    if ($periodType === 'trade_test_level') {
        return 'Trade Test Level';
    }
    if ($periodType === 'short_course_cycle') {
        return 'Short Course Cycle';
    }
    return 'Semester';
}

function course_reg_current_academic_year(mysqli $db, string $periodType): string {
    if (course_reg_table_exists($db, 'academic_periods')
        && course_reg_column_exists($db, 'academic_periods', 'academic_year')
        && course_reg_column_exists($db, 'academic_periods', 'period_type')
        && course_reg_column_exists($db, 'academic_periods', 'is_current')) {
        if ($stmt = $db->prepare("SELECT academic_year FROM academic_periods WHERE period_type = ? AND is_current = 1 ORDER BY id DESC LIMIT 1")) {
            $stmt->bind_param('s', $periodType);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && trim((string)($row['academic_year'] ?? '')) !== '') {
                    return substr(trim((string)$row['academic_year']), 0, 4);
                }
            } else {
                $stmt->close();
            }
        }
    }
    return date('Y');
}

function course_reg_detect_student_program(mysqli $db, string $studentId): ?array {
    $stmt = $db->prepare("SELECT sp.program_code, sp.mode, COUNT(pc.course_code) AS mapped_courses
                          FROM student_program sp
                          LEFT JOIN program_courses pc ON pc.program_code = sp.program_code
                          WHERE sp.Sid = ?
                            AND LOWER(COALESCE(sp.status, 'active')) = 'active'
                          GROUP BY sp.id, sp.program_code, sp.mode
                          ORDER BY mapped_courses DESC, sp.id DESC
                          LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function course_reg_student_program_active(mysqli $db, string $studentId, string $programCode): bool {
    if ($studentId === '' || $programCode === '') {
        return false;
    }
    if ($stmt = $db->prepare("SELECT 1 FROM student_program WHERE Sid = ? AND program_code = ? AND LOWER(COALESCE(status, 'active')) = 'active' LIMIT 1")) {
        $stmt->bind_param('ss', $studentId, $programCode);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }
    return false;
}

function course_reg_semester_registration_id(mysqli $db, string $studentId, string $programCode, int $year, int $period, string $periodType, string $academicYear): ?int {
    if ($studentId === '' || $programCode === '' || $year <= 0 || $period <= 0 || !course_reg_table_exists($db, 'semester_registration')) {
        return null;
    }
    $sql = "SELECT id FROM semester_registration
            WHERE (student_id = ? OR SID = ?)
              AND program_code = ?
              AND semester = ?
              AND period_type = ?
              AND year_of_study = ?
              AND academic_year = ?
            ORDER BY id DESC
            LIMIT 1";
    if ($stmt = $db->prepare($sql)) {
        $periodText = (string)$period;
        $yearText = (string)$year;
        $stmt->bind_param('sssssss', $studentId, $studentId, $programCode, $periodText, $periodType, $yearText, $academicYear);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }
    return null;
}

function course_reg_existing_courses(mysqli $db, string $studentId, int $year, int $period, ?int $semesterRegistrationId): array {
    $existing = [];
    if ($semesterRegistrationId) {
        $sql = "SELECT DISTINCT course_code FROM course_registration
                WHERE semester_registration_id = ? AND COALESCE(is_active, 1) = 1";
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param('i', $semesterRegistrationId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $existing[] = strtoupper(trim((string)$row['course_code']));
            }
            $stmt->close();
        }
    }

    $sql = "SELECT DISTINCT course_code FROM course_registration
            WHERE Sid = ? AND semester = ? AND Year = ? AND COALESCE(is_active, 1) = 1";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('sii', $studentId, $period, $year);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $existing[] = strtoupper(trim((string)$row['course_code']));
        }
        $stmt->close();
    }
    return array_values(array_unique(array_filter($existing)));
}

function course_reg_available_courses(mysqli $db, string $programCode, int $year, int $period, array $existingCourseCodes = []): array {
    $records = [];
    if ($programCode === '' || $year <= 0 || $period <= 0) {
        return $records;
    }
    $pcCols = wuc_course_availability_columns($db, 'program_courses');
    $where = ['pc.year = ?', 'pc.program_code = ?', "COALESCE(c.status, 'active') = 'active'"];
    $types = 'is';
    $params = [$year, $programCode];
    $periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcCols['semester'] ?? null, $period);
    if ($periodFilter['sql'] !== '1=1') {
        $where[] = $periodFilter['sql'];
        $types .= $periodFilter['types'];
        $params = array_merge($params, $periodFilter['params']);
    }

    $sql = "SELECT pc.course_code, c.course_name, COALESCE(c.credits, 0) AS credit_hours
            FROM program_courses pc
            INNER JOIN courses c ON c.course_code = pc.course_code
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pc.course_code";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_object()) {
            $row->already_registered = in_array(strtoupper(trim((string)$row->course_code)), $existingCourseCodes, true);
            $records[] = $row;
        }
        $stmt->close();
    }
    return $records;
}

function course_reg_balance(mysqli $db, string $studentId, string $programCode, int $year, int $period, string $academicYear): float {
    $totalFees = 0.0;
    $where = ['program_code = ?', 'year_of_study = ?', 'semester = ?'];
    $types = 'sii';
    $params = [$programCode, $year, $period];
    if (course_reg_column_exists($db, 'fee_structure', 'entity_type')) {
        $where[] = "entity_type = 'program'";
    }
    if (course_reg_column_exists($db, 'fee_structure', 'status')) {
        $where[] = "LOWER(status) = 'active'";
    }
    $sql = 'SELECT COALESCE(SUM(amount), 0) AS total_fees FROM fee_structure WHERE ' . implode(' AND ', $where);
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $totalFees = (float)($row['total_fees'] ?? 0);
    }

    $totalPaid = 0.0;
    $paymentWhere = ["student_id = ?", "LOWER(status) IN ('completed', 'paid', 'success', 'confirmed')"];
    $paymentTypes = 's';
    $paymentParams = [$studentId];
    if (course_reg_column_exists($db, 'payments', 'academic_year') && $academicYear !== '') {
        $paymentWhere[] = "(academic_year = ? OR academic_year IS NULL OR academic_year = '')";
        $paymentTypes .= 's';
        $paymentParams[] = $academicYear;
    }
    if (course_reg_column_exists($db, 'payments', 'semester')) {
        $paymentWhere[] = "(semester = ? OR semester IS NULL OR semester = 0)";
        $paymentTypes .= 'i';
        $paymentParams[] = $period;
    }
    $sql = 'SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE ' . implode(' AND ', $paymentWhere);
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param($paymentTypes, ...$paymentParams);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $totalPaid = (float)($row['total_paid'] ?? 0);
    }

    return max(0.0, $totalFees - $totalPaid);
}

// Get all available programs (handle schemas without a 'status' column)
$programCols = course_reg_columns($db, 'programs');
$legacyModeFallback = isset($programCols['period_mode'])
    ? "COALESCE(period_mode, 'semester')"
    : (isset($programCols['study_mode']) ? "COALESCE(study_mode, 'semester')" : "'semester'");
$modeExpr = isset($programCols['structure_type'])
    ? "CASE structure_type
          WHEN 'TERM_BASED' THEN 'term'
          WHEN 'SEMESTER_BASED' THEN 'semester'
          WHEN 'TRADE_TEST_LEVEL' THEN 'trade_test_level'
          WHEN 'SHORT_COURSE' THEN 'short_course_cycle'
          ELSE {$legacyModeFallback}
       END"
    : $legacyModeFallback;
$programsQuery = "SELECT program_code, program_name, {$modeExpr} AS period_mode FROM programs";
if (isset($programCols['is_active'])) {
    $programsQuery .= " WHERE COALESCE(is_active, 1) = 1";
} elseif (isset($programCols['status'])) {
    $programsQuery .= " WHERE LOWER(status) = 'active'";
}
$programsQuery .= " ORDER BY program_name";
if ($programResults = $db->query($programsQuery)) {
    if($programResults->num_rows > 0) {
        while($row = $programResults->fetch_object()){
            $programs[] = $row;
        }
    }
    $programResults->free();
} else {
    $_SESSION['errorMsg'] = "Error loading programs: " . $db->error;
}

// Handle search with prepared statements
if (isset($_POST['search'])) {
    $Sid = trim($_POST['Sid'] ?? '');
    $semester = (int)($_POST['semester'] ?? 0);
    $Year = (int)($_POST['Year'] ?? 0);
    $programCode = trim($_POST['program_code'] ?? '');
    $periodType = strtolower(trim($_POST['period_type'] ?? ''));

    if (empty($Sid) || $semester === 0 || $Year === 0) {
        $_SESSION['errorMsg'] = "Please fill in all required fields.";
    } else {
        // Get student information first; program auto-detection is handled by
        // course_reg_detect_student_program() so students with multiple
        // assignments do not randomly land on an unmapped/old program.
        $stmt = $db->prepare("SELECT s.SID, s.Fname, s.Lname, s.email
                              FROM students s
                              WHERE s.SID = ?");
        if ($stmt) {
            $stmt->bind_param("s", $Sid);
            $stmt->execute();
            $studentResult = $stmt->get_result();
            if ($studentResult && $row = $studentResult->fetch_object()) {
                $studentInfo = $row;
                $detectedProgram = course_reg_detect_student_program($db, $Sid);
                if (empty($programCode)) {
                    $programCode = $detectedProgram['program_code'] ?? '';
                }
                if ($programCode !== '' && !course_reg_student_program_active($db, $Sid, $programCode)) {
                    $programCode = '';
                }
                $row->program_code = $programCode;
                $row->mode = $detectedProgram['mode'] ?? '';
                // Transfer status is not a students-table column in the live schema.
                $studentType = stripos((string)$row->mode, 'transfer') !== false ? 'Transfer' : 'Regular';
                $resolvedProgramCode = $programCode;
            }
            $stmt->close();
        }
        if (empty($studentInfo)) {
            $_SESSION['errorMsg'] = 'Student ID not found in the system.';
        } else {
            if ($resolvedProgramCode === '') {
                $resolvedProgramCode = $programCode;
            }
        if (!in_array($periodType, ['semester', 'term', 'trade_test_level', 'short_course_cycle'], true)) {
            $periodType = course_reg_period_mode($db, $programCode);
        }
        $periodLabel = course_reg_period_label($periodType);
        $periodLimit = $periodType === 'term' || $periodType === 'trade_test_level' ? 3 : 2;
        $academicYear = course_reg_current_academic_year($db, $periodType);
        $semesterRegistrationId = course_reg_semester_registration_id($db, $Sid, $programCode, $Year, $semester, $periodType, $academicYear);
        $registrationReady = $semesterRegistrationId !== null;
        if (!$registrationReady && $programCode !== '') {
            $registrationWarning = "No {$periodLabel} registration exists for this student in Year {$Year}, {$periodLabel} {$semester}, {$academicYear}. Create the {$periodLabel} registration first, then enroll courses.";
        }

        $studentBalance = course_reg_balance($db, $Sid, $programCode, $Year, $semester, $academicYear);

        // Get available courses based on student's program, year, and semester
        if (!empty($programCode)) {
            if ($semester < 1 || $semester > $periodLimit) {
                $_SESSION['errorMsg'] = "Invalid {$periodLabel} number for this program.";
            } else {
                $existingCourseCodes = course_reg_existing_courses($db, $Sid, $Year, $semester, $semesterRegistrationId);
                $records = course_reg_available_courses($db, $programCode, $Year, $semester, $existingCourseCodes);
                if (count($records) > 0) {
                    $searchPerformed = true;
                    $_SESSION['successMsg'] = "Found " . count($records) . " curriculum course(s) for Year $Year, {$periodLabel} $semester.";
                } else {
                    $_SESSION['errorMsg'] = "No courses available for Year $Year, {$periodLabel} $semester in the selected program.";
                }
            }
        } else {
            $_SESSION['errorMsg'] = "Student's active program could not be determined.";
        }
        }
    }
}

// Handle course registration with prepared statements
if(isset($_POST["register"])) {
    $Sid = trim($_POST["Sid"] ?? '');
    $courseCodes = $_POST["course_code"] ?? [];
    $semester = (int)($_POST["semester"] ?? 0);
    $Year = (int)($_POST["Year"] ?? 0);
    $program_code = trim($_POST["program_code"] ?? '');
    $periodType = strtolower(trim($_POST['period_type'] ?? course_reg_period_mode($db, $program_code)));
    if (!in_array($periodType, ['semester', 'term', 'trade_test_level', 'short_course_cycle'], true)) {
        $periodType = course_reg_period_mode($db, $program_code);
    }
    $periodLabel = course_reg_period_label($periodType);
    $academicYear = course_reg_current_academic_year($db, $periodType);

    // Validate inputs
    $errors = [];
    if (empty($Sid)) $errors[] = "Student ID";
    if (empty($courseCodes)) $errors[] = "Course selection";
    if ($semester === 0) $errors[] = $periodLabel;
    if ($Year === 0) $errors[] = "Year";
    if (empty($program_code)) $errors[] = "Program";

    if (!empty($errors)) {
        $_SESSION['errorMsg'] = "Missing required fields: " . implode(", ", $errors);
        header('Location: courseReg.php');
        exit();
    }

    if (!course_reg_student_program_active($db, $Sid, $program_code)) {
        $_SESSION['errorMsg'] = "The student is not actively enrolled in the selected program.";
        header('Location: courseReg.php');
        exit();
    }

    $expectedPeriodType = course_reg_period_mode($db, $program_code);
    if ($periodType !== $expectedPeriodType) {
        $_SESSION['errorMsg'] = "The selected period type does not match the programme structure.";
        header('Location: courseReg.php');
        exit();
    }

    $periodLimit = $periodType === 'term' || $periodType === 'trade_test_level' ? 3 : 2;
    if ($semester < 1 || $semester > $periodLimit) {
        $_SESSION['errorMsg'] = "Invalid {$periodLabel} number for this program.";
        header('Location: courseReg.php');
        exit();
    }

    $registrationGuard = wuc_legacy_course_registration_guard($db, $Sid, $program_code, $Year, $semester, (array)$courseCodes);
    if (!$registrationGuard['ok']) {
        $_SESSION['errorMsg'] = $registrationGuard['reason'];
        header('Location: courseReg.php');
        exit();
    }
    $periodType = $registrationGuard['period_type'];
    $periodLabel = course_reg_period_label($periodType);

    $semesterRegistrationId = course_reg_semester_registration_id($db, $Sid, $program_code, $Year, $semester, $periodType, $academicYear);
    if ($semesterRegistrationId === null) {
        $_SESSION['errorMsg'] = "Create the student's {$periodLabel} registration for {$periodLabel} {$semester}, Year {$Year}, {$academicYear} before enrolling courses.";
        header('Location: courseReg.php');
        exit();
    }

    $available = course_reg_available_courses($db, $program_code, $Year, $semester);
    $allowedCodes = array_map(static fn($row): string => strtoupper(trim((string)$row->course_code)), $available);
    $courseCodes = array_values(array_unique(array_filter(array_map(static fn($code): string => strtoupper(trim((string)$code)), (array)$courseCodes))));
    foreach ($courseCodes as $courseCode) {
        if (!in_array($courseCode, $allowedCodes, true)) {
            $_SESSION['errorMsg'] = "Invalid course selected: {$courseCode}.";
            header('Location: courseReg.php');
            exit();
        }
    }

    // Begin transaction for atomic course registration
    $db->begin_transaction();
    try {
        $existsStmt = $db->prepare("SELECT id FROM course_registration
                                    WHERE course_code = ?
                                      AND COALESCE(is_active, 1) = 1
                                      AND (semester_registration_id = ? OR (Sid = ? AND semester = ? AND Year = ?))
                                    LIMIT 1");
        if (!$existsStmt) {
            throw new Exception("Failed to prepare duplicate check: " . $db->error);
        }

        $insertStmt = $db->prepare("INSERT INTO course_registration
                                    (Sid, course_code, semester, Year, semester_registration_id, registration_date, status, created_at, is_active)
                                    VALUES (?, ?, ?, ?, ?, NOW(), 'registered', NOW(), 1)");
        
        if (!$insertStmt) {
            throw new Exception("Failed to prepare insert statement: " . $db->error);
        }
        
        $registeredCount = 0;
        $skippedCount = 0;
        foreach ($courseCodes as $courseCode) {
            $courseCode = trim($courseCode);
            if (!empty($courseCode)) {
                $existsStmt->bind_param("sisii", $courseCode, $semesterRegistrationId, $Sid, $semester, $Year);
                $existsStmt->execute();
                if ($existsStmt->get_result()->num_rows > 0) {
                    $sync = wuc_sync_legacy_course_registration_to_canonical($db, $Sid, $program_code, $courseCode, $Year, $semester);
                    if (!$sync['ok']) {
                        throw new Exception("Failed to sync canonical registration for {$courseCode}: " . $sync['reason']);
                    }
                    $skippedCount++;
                    continue;
                }
                $insertStmt->bind_param("ssiii", $Sid, $courseCode, $semester, $Year, $semesterRegistrationId);
                if (!$insertStmt->execute()) {
                    throw new Exception("Failed to register course $courseCode: " . $insertStmt->error);
                }
                $sync = wuc_sync_legacy_course_registration_to_canonical($db, $Sid, $program_code, $courseCode, $Year, $semester);
                if (!$sync['ok']) {
                    throw new Exception("Failed to sync canonical registration for {$courseCode}: " . $sync['reason']);
                }
                $registeredCount++;
            }
        }
        $existsStmt->close();
        $insertStmt->close();
        
        $db->commit();
        if ($registeredCount > 0) {
            $_SESSION['successMsg'] = "Successfully enrolled {$registeredCount} course(s)." . ($skippedCount > 0 ? " {$skippedCount} already-enrolled course(s) skipped." : '');
        } else {
            $_SESSION['errorMsg'] = "No new courses were enrolled. All selected course(s) are already registered for this student.";
        }
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['errorMsg'] = "Registration failed: " . $e->getMessage();
    }
    
    header('Location: courseReg.php');
    exit();
}

require 'includes/header.php';
?>

<!-- Content Area with Max Width -->
<div class="container-fluid px-4 portal-dashboard">
        <!-- Dashboard Header -->
        <div class="dashboard-header admin-section mb-4">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="dashboard-title">Course Registration</h1>
                    <p class="text-muted">Register students for courses</p>
                </div>
                <div class="col-auto">
                    <div class="header-actions d-flex gap-2">
                        <?php if($searchPerformed): ?>
                            <a href="courseReg.php" class="btn btn-outline-primary d-flex align-items-center gap-2">
                                <i class="fas fa-arrow-left"></i> New Search
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Messages -->
        <div class="row mb-4">
            <div class="col-12">
                <?php if(isset($_SESSION['errorMsg'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($_SESSION['errorMsg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['errorMsg']); ?>
                <?php endif; ?>
                <?php if(isset($_SESSION['successMsg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_SESSION['successMsg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['successMsg']); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Search Form -->
        <?php if(!$searchPerformed): ?>
        <div class="data-table-card mb-4">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-search me-2"></i>Find Student
                    </h5>
                </div>
            </div>
            <div class="card-body">
                <form method="post" action="" class="row g-3">
                    <div class="col-md-6">
                        <label for="Sid" class="form-label">Student ID</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                            <input type="text" class="form-control" id="Sid" name="Sid" placeholder="Enter Student ID" 
                                   required maxlength="50">
                        </div>
                        <div id="student-lookup-msg" class="form-text"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="program_code" class="form-label">Program (Optional)</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-graduation-cap"></i></span>
                            <select class="form-select" id="program_code" name="program_code">
                                <option value="">-- Auto-detect from student --</option>
                                <?php foreach($programs as $program): ?>
                                    <option value="<?php echo htmlspecialchars($program->program_code); ?>"
                                            data-mode="<?php echo htmlspecialchars(strtolower((string)($program->period_mode ?? 'semester'))); ?>">
                                        <?php echo htmlspecialchars($program->program_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="period_type" class="form-label">Academic Period Type</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-stream"></i></span>
                            <select class="form-select" id="period_type" name="period_type" required>
                                <option value="">-- Select Type --</option>
                                <option value="semester">Semester-based</option>
                                <option value="term">Term-based</option>
                                <option value="trade_test_level">Trade-test level</option>
                                <option value="short_course_cycle">Short-course cycle</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="semester" class="form-label" id="period_number_label">Period</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                            <select class="form-select" id="semester" name="semester" required>
                                <option value="">-- Select Type First --</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="Year" class="form-label">Year Level</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-layer-group"></i></span>
                            <select class="form-select" id="Year" name="Year" required>
                                <option value="">-- Select Year --</option>
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                                <option value="5">Year 5</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="search" class="btn btn-primary" id="course-reg-search-btn">
                            <i class="fas fa-search me-2"></i>Search Courses
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <!-- Student Information -->
        <div class="card section-container mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary">
                    <i class="fas fa-user-graduate me-2"></i>Student Information
                </h5>
                <span class="badge <?php echo $studentType == 'Regular' ? 'bg-success' : 'bg-warning'; ?>">
                    <?php echo htmlspecialchars($studentType); ?> Student
                </span>
            </div>
            <div class="card-body">
            <div class="row g-3 text-center">
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="data-container mb-2">
                        <label class="text-muted mb-0 small">Student ID</label>
                        <h6 class="mb-0"><?php echo htmlspecialchars($_POST['Sid']); ?></h6>
                    </div>
                </div>
                <?php if ($studentInfo): ?>
                <div class="col-md-3 col-sm-4">
                    <div class="data-container mb-2">
                        <label class="text-muted mb-0 small">Name</label>
                        <h6 class="mb-0"><?php echo htmlspecialchars($studentInfo->Fname . ' ' . $studentInfo->Lname); ?></h6>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="data-container mb-2">
                        <label class="text-muted mb-0 small">Program</label>
                        <h6 class="mb-0"><?php echo htmlspecialchars($resolvedProgramCode ?: '-'); ?></h6>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="data-container mb-2">
                        <label class="text-muted mb-0 small">Academic Period</label>
                        <h6 class="mb-0">
                            Year <?php echo (int)$_POST['Year']; ?>, 
                            <?php echo htmlspecialchars($periodLabel); ?> <?php echo (int)$_POST['semester']; ?>
                            <small class="d-block text-muted"><?php echo htmlspecialchars($academicYear ?: date('Y')); ?></small>
                        </h6>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="data-container mb-2">
                        <label class="text-muted mb-0 small">Registration Link</label>
                        <h6 class="mb-0 <?php echo $registrationReady ? 'text-success' : 'text-warning'; ?>">
                            <?php echo $registrationReady ? 'Ready' : 'Missing'; ?>
                            <?php if ($semesterRegistrationId): ?>
                                <small class="d-block text-muted">#<?php echo (int)$semesterRegistrationId; ?></small>
                            <?php endif; ?>
                        </h6>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <div class="data-container mb-2">
                        <label class="text-muted mb-0 small">Period Balance</label>
                        <h6 class="mb-0 <?php echo $studentBalance > 0 ? 'text-danger' : 'text-success'; ?>">
                ZMW <?php echo number_format($studentBalance, 2); ?>
                            <?php if($studentBalance > 0): ?>
                                <small class="d-block text-danger">Outstanding balance</small>
                            <?php else: ?>
                                <small class="d-block text-success">Cleared</small>
                            <?php endif; ?>
                        </h6>
                    </div>
                </div>
            </div>
            </div>
        </div>

        <!-- Course Registration Form -->
        <?php
            $pendingCourses = array_filter($records, static fn($row): bool => empty($row->already_registered));
            $pendingCount = count($pendingCourses);
        ?>
        <div class="card section-container">
            <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h5 class="mb-0 text-primary">
                    <i class="fas fa-edit me-2"></i>Course Enrollment
                </h5>
                <span class="badge bg-primary"><?php echo $pendingCount; ?> pending / <?php echo count($records); ?> curriculum</span>
            </div>
            <div class="card-body">
            <?php if (!$registrationReady): ?>
                <div class="alert alert-warning d-flex align-items-start gap-2">
                    <i class="fas fa-link-slash mt-1"></i>
                    <div>
                        <strong><?php echo htmlspecialchars($periodLabel); ?> registration required.</strong>
                        <div><?php echo htmlspecialchars($registrationWarning); ?></div>
                        <a href="semester_registration.php" class="btn btn-sm btn-outline-primary mt-2">
                            <i class="fas fa-user-edit me-1"></i>Open Term Registration
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <form action="courseReg.php" method="post">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 48px;">
                                    <input type="checkbox" class="form-check-input" id="selectAllCourses" <?php echo (!$registrationReady || $pendingCount === 0) ? 'disabled' : ''; ?>>
                                </th>
                                <th>Course</th>
                                <th>Name</th>
                                <th class="text-center">Credits</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($records as $r): ?>
                            <?php $isRegistered = !empty($r->already_registered); ?>
                            <tr class="<?php echo $isRegistered ? 'table-success' : ''; ?>">
                                <td>
                                    <input type="checkbox"
                                           class="form-check-input course-check"
                                           name="course_code[]"
                                           value="<?php echo htmlspecialchars($r->course_code); ?>"
                                           <?php echo ($isRegistered || !$registrationReady) ? 'disabled' : ''; ?>>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($r->course_code); ?></span></td>
                                <td><?php echo htmlspecialchars($r->course_name); ?></td>
                                <td class="text-center"><?php echo (int)($r->credit_hours ?? 3); ?></td>
                                <td class="text-center">
                                    <?php if ($isRegistered): ?>
                                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Enrolled</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-text mt-2">
                    Select only the courses to enroll now. Existing active enrollments are protected from duplicate registration.
                </div>

                <input type="hidden" name="Sid" value="<?php echo htmlspecialchars($_POST['Sid']); ?>">
                <input type="hidden" name="semester" value="<?php echo (int)$_POST['semester']; ?>">
                <input type="hidden" name="period_type" value="<?php echo htmlspecialchars($periodType); ?>">
                <input type="hidden" name="Year" value="<?php echo (int)$_POST['Year']; ?>">
                <input type="hidden" name="program_code" value="<?php echo htmlspecialchars($resolvedProgramCode ?: ($studentInfo->program_code ?? '')); ?>">

                <div class="mt-3 d-flex flex-wrap justify-content-end gap-2">
                    <a href="courseReg.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>New Search
                    </a>
                    <button class="btn btn-success px-4" type="submit" name="register" <?php echo (!$registrationReady || $pendingCount === 0) ? 'disabled' : ''; ?>>
                        <i class="fas fa-save me-2"></i>Register Courses
                    </button>
                </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

<script>
(function () {
    const programSelect = document.getElementById('program_code');
    const typeSelect = document.getElementById('period_type');
    const periodSelect = document.getElementById('semester');
    const periodLabel = document.getElementById('period_number_label');
    const selectAllCourses = document.getElementById('selectAllCourses');

    function rebuildPeriods() {
        const mode = typeSelect ? typeSelect.value : '';
        let label = 'Semester';
        let max = 0;
        if (mode === 'term') {
            label = 'Term';
            max = 3;
        } else if (mode === 'semester') {
            label = 'Semester';
            max = 2;
        } else if (mode === 'trade_test_level') {
            label = 'Trade Test Level';
            max = 3;
        } else if (mode === 'short_course_cycle') {
            label = 'Short Course Cycle';
            max = 4;
        }

        periodSelect.innerHTML = '';
        periodLabel.textContent = max ? label : 'Period';

        if (!max) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '-- Select Type First --';
            periodSelect.appendChild(opt);
            return;
        }

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Select ' + label + ' --';
        periodSelect.appendChild(placeholder);

        for (let i = 1; i <= max; i++) {
            const opt = document.createElement('option');
            opt.value = String(i);
            opt.textContent = label + ' ' + i;
            periodSelect.appendChild(opt);
        }
    }

    if (programSelect && typeSelect) {
        programSelect.addEventListener('change', function () {
            const selected = this.options[this.selectedIndex];
            const mode = (selected && selected.getAttribute('data-mode') || '').toLowerCase();
            if (['term', 'semester', 'trade_test_level', 'short_course_cycle'].includes(mode)) {
                typeSelect.value = mode;
            }
            rebuildPeriods();
        });
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', rebuildPeriods);
        rebuildPeriods();
    }

    if (selectAllCourses) {
        selectAllCourses.addEventListener('change', function () {
            document.querySelectorAll('.course-check:not(:disabled)').forEach(function (checkbox) {
                checkbox.checked = selectAllCourses.checked;
            });
        });
    }
})();
</script>
<script src="js/student_lookup.js"></script>
<script>
wucBindStudentLookup({
    inputId: 'Sid',
    msgId: 'student-lookup-msg',
    submitSelector: '#course-reg-search-btn',
    programSelectId: 'program_code'
});
</script>

<?php require_once "includes/footer.php"; ?>
