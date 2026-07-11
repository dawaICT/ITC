
<?php
// CRITICAL: Always start the session first for CSRF protection.
session_start();

// Ensure includes act as scripts (no HTML output from admin/includes/admin.php)
if (!defined('IS_SCRIPT')) {
    define('IS_SCRIPT', true);
}

// 1. ============================== INITIAL SETUP & CONFIGURATION ==============================
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', 1);

set_time_limit(120);
ini_set('memory_limit', '256M');

// For redirecting with status messages
define('SUCCESS_MSG', 'success_message');
define('ERROR_MSG', 'error_message');

// 2. ============================== DATABASE & INCLUDES ==============================
// Include dependencies. Use require_once to prevent multiple inclusions and throw a fatal error if files are missing.
require_once "includes/admin.php"; // Should establish the $db connection

// Log errors to a file for production debugging
$logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
ini_set('error_log', $logDir . DIRECTORY_SEPARATOR . 'programs.log');


// 3. ============================== HELPER & CORE FUNCTIONS ==============================

/**
 * Generates and stores a CSRF token in the session.
 * @return string The generated token.
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Detects a column from candidates on a table.
 */
function detect_column(mysqli $db, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        $colEsc = $db->real_escape_string($col);
        if ($res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$colEsc}'")) {
            if ($res->num_rows > 0) { $res->free(); return $col; }
            $res->free();
        }
    }
    return null;
}

/**
 * Determines how to join programs to departments based on the actual schema.
 * Returns an array with keys: joinSql, deptNameExpr
 */
function determine_programs_departments_join(mysqli $db): array {
    // Programs possible FK columns
    $progFkCol = detect_column($db, 'programs', ['department_id', 'deptId', 'department_code']);
    // Departments numeric and code cols
    $deptNumericCol = detect_column($db, 'departments', ['id', 'DeptID', 'department_id']);
    $deptCodeCol = detect_column($db, 'departments', ['deptId', 'department_code']);
    $deptNameCol = detect_column($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']) ?? 'department_name';

    $joinSql = '';
    if ($progFkCol === 'department_id' && $deptNumericCol) {
        $joinSql = "LEFT JOIN departments d ON p.`department_id` = d.`{$deptNumericCol}`";
    } elseif (($progFkCol === 'deptId' || $progFkCol === 'department_code') && $deptCodeCol) {
        $joinSql = "LEFT JOIN departments d ON p.`{$progFkCol}` = d.`{$deptCodeCol}`";
    } elseif ($deptNumericCol) {
        // Last resort: attempt program->department mapping via an existing relation table (none known), skip join
        $joinSql = '';
    }

    $deptNameExpr = $joinSql ? "d.`{$deptNameCol}`" : "NULL";
    return ['joinSql' => $joinSql, 'deptNameExpr' => $deptNameExpr];
}

/**
 * Verifies the submitted CSRF token. Dies if the token is invalid.
 * @param string $token The token from the form submission.
 */
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        die('CSRF token validation failed. Please try again.');
    }
}

function default_program_duration_years(string $program_type): float {
    switch (strtolower(trim($program_type))) {
        case 'degree':
            return 4.0;
        case 'diploma':
            return 2.0;
        case 'certificate':
            return 1.0;
        default:
            return 1.0;
    }
}

function format_program_duration_years($years): string {
    if ($years === null || $years === '' || (float)$years <= 0) {
        return 'N/A';
    }

    $years = round((float)$years, 2);
    if ($years < 1) {
        $months = max(1, (int)round($years * 12));
        return $months . ' month' . ($months === 1 ? '' : 's');
    }

    $label = rtrim(rtrim(number_format($years, 2, '.', ''), '0'), '.');
    return $label . ' year' . ($years == 1.0 ? '' : 's');
}

function deactivate_unmapped_courses(mysqli $db, array $courseCodes): int {
    $courseCodes = array_values(array_unique(array_filter(array_map('strval', $courseCodes))));
    if (empty($courseCodes)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($courseCodes), '?'));
    $types = str_repeat('s', count($courseCodes));
    $sql = "UPDATE courses c
            SET c.status = 'inactive'
            WHERE c.course_code IN ($placeholders)
              AND NOT EXISTS (
                  SELECT 1 FROM program_courses pc
                  WHERE pc.course_code = c.course_code
              )";
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$courseCodes);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return max(0, $affected);
}

/**
 * Redirects to the main programs page with a status message.
 * @param string $type 'success' or 'error'
 * @param string $message The message to display.
 */
function redirect_with_message($type, $message) {
    $_SESSION[$type === 'success' ? SUCCESS_MSG : ERROR_MSG] = $message;
    if (!headers_sent()) {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        echo "<script>window.location.href='programs.php';</script>";
        exit;
    }
}

/**
 * Fetches a single program's details from the database.
 * @param mysqli $db The database connection.
 * @param string $program_code The program code.
 * @return array|null The program data or null if not found.
 */
function get_program_details(mysqli $db, $program_code) {
    $join = determine_programs_departments_join($db);
    $query = "SELECT p.*, " . $join['deptNameExpr'] . " AS department_name
              FROM programs p 
              " . $join['joinSql'] . "
              WHERE p.program_code = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("s", $program_code);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Saves a program (handles both insert and update).
 * @param mysqli $db The database connection.
 * @param array $data The program data from the form.
 * @return array Status and message.
 */
function save_program(mysqli $db, array $data) {
    // Sanitize and prepare data
    $is_update = !empty($data['original_code']);
    $program_code = trim($data['program_code']);
    $program_name = trim($data['program_name']);
    $program_type = strtolower(trim($data['program_type']));
    
    // Academic Structure & Examination Type
    $academic_structure = trim($data['academic_structure'] ?? 'certificate_term');
    $examination_type = trim($data['examination_type'] ?? 'external');
    
    $uses_terms = 0;
    $uses_semesters = 0;
    $is_short_course = 0;
    $is_transport_exception = 0;
    $period_mode = 'semester';
    
    if ($academic_structure === 'short_course') {
        $is_short_course = 1;
        $period_mode = 'short_course';
    } elseif ($academic_structure === 'certificate_term' || $academic_structure === 'diploma_term') {
        $uses_terms = 1;
        $period_mode = 'term';
    } elseif ($academic_structure === 'semester_exception') {
        $uses_semesters = 1;
        $is_transport_exception = 1;
        $period_mode = 'semester';
    }
    
    // Duration
    $duration_value = !empty($data['duration_value']) ? (int)$data['duration_value'] : null;
    $duration_unit = !empty($data['duration_unit']) ? trim($data['duration_unit']) : null;
    
    $program_description = trim($data['program_description']);
    // department_id is a string code (e.g. GEN01), not an integer.
    $department_id = trim((string)($data['department_id'] ?? ''));
    if ($department_id === '') { $department_id = null; }
    $program_duration_raw = trim((string)($data['program_duration'] ?? ''));
    $program_duration = $program_duration_raw === ''
        ? default_program_duration_years($program_type)
        : filter_var($program_duration_raw, FILTER_VALIDATE_FLOAT, ['options' => ['default' => null]]);
    $is_active = isset($data['is_active']) ? 1 : 0;

    // Validation
    if (empty($program_code) || empty($program_name) || empty($program_type) || $department_id === null) {
        return ['status' => 'error', 'message' => 'Program code, name, type, and department are required.'];
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-.]*$/', $program_code) || strlen($program_code) > 50) {
        return ['status' => 'error', 'message' => 'Invalid program code: use letters, numbers, dashes or dots (max 50 chars).'];
    }
    if (mb_strlen($program_name) > 200) {
        return ['status' => 'error', 'message' => 'Program name is too long (max 200 characters).'];
    }
    if (!in_array(strtolower($program_type), ['degree', 'diploma', 'certificate'], true)) {
        return ['status' => 'error', 'message' => 'Invalid program type. Choose Degree, Diploma or Certificate.'];
    }
    if ($program_duration === null || $program_duration < 0.25 || $program_duration > 10) {
        return ['status' => 'error', 'message' => 'Program duration must be between 0.25 and 10 years.'];
    }
    $program_duration = round((float)$program_duration, 2);

    if ($is_update) {
        // --- UPDATE ---
        $original_code = trim($data['original_code']);
        if (empty($original_code)) {
            return ['status' => 'error', 'message' => 'Original program code is required for updates.'];
        }
        $sql = "UPDATE programs SET
                    program_code = ?, program_name = ?, program_type = ?,
                    period_mode = ?, program_duration = ?, program_description = ?,
                    department_id = ?, is_active = ?,
                    academic_structure = ?, duration_value = ?, duration_unit = ?,
                    uses_terms = ?, uses_semesters = ?, is_short_course = ?,
                    is_transport_exception = ?, examination_type = ?
                WHERE program_code = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param(
            "ssssdssissiiiiiss",
            $program_code, $program_name, $program_type, $period_mode, $program_duration,
            $program_description, $department_id, $is_active,
            $academic_structure, $duration_value, $duration_unit,
            $uses_terms, $uses_semesters, $is_short_course,
            $is_transport_exception, $examination_type, $original_code
        );
    } else {
        // --- INSERT ---
        // Check for duplicate program code before inserting
        $check_stmt = $db->prepare("SELECT program_code FROM programs WHERE program_code = ?");
        $check_stmt->bind_param("s", $program_code);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            return ['status' => 'error', 'message' => "Program with code '{$program_code}' already exists."];
        }

        $sql = "INSERT INTO programs (
                    program_code, program_name, program_type, period_mode,
                    program_duration, program_description, department_id, is_active,
                    academic_structure, duration_value, duration_unit,
                    uses_terms, uses_semesters, is_short_course,
                    is_transport_exception, examination_type
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->bind_param(
            "ssssdssissiiiiis",
            $program_code, $program_name, $program_type, $period_mode, $program_duration,
            $program_description, $department_id, $is_active,
            $academic_structure, $duration_value, $duration_unit,
            $uses_terms, $uses_semesters, $is_short_course,
            $is_transport_exception, $examination_type
        );
    }

    try {
        $db->begin_transaction();
        $stmt->execute();

        if ($is_update && $original_code !== $program_code) {
            $syncTables = ['program_courses', 'student_program', 'fee_structure', 'semester_registration'];
            foreach ($syncTables as $tableName) {
                if ($db->query("SHOW TABLES LIKE '{$tableName}'")->num_rows === 0) {
                    continue;
                }
                if ($db->query("SHOW COLUMNS FROM `{$tableName}` LIKE 'program_code'")->num_rows === 0) {
                    continue;
                }
                $sync = $db->prepare("UPDATE `{$tableName}` SET program_code = ? WHERE program_code = ?");
                $sync->bind_param("ss", $program_code, $original_code);
                $sync->execute();
                $sync->close();
            }
        }

        $db->commit();
        $action = $is_update ? 'updated' : 'added';
        return ['status' => 'success', 'message' => "Program successfully {$action}."];
    } catch (Throwable $e) {
        $db->rollback();
        $action = $is_update ? 'updating' : 'adding';
        error_log("Error {$action} program: " . $e->getMessage());
        return ['status' => 'error', 'message' => "Error {$action} program. Please try again."];
    }
}

/**
 * Deletes a program after checking for student enrollments.
 * @param mysqli $db The database connection.
 * @param string $program_code The program code to delete.
 * @return array Status and message.
 */
function delete_program(mysqli $db, $program_code) {
    // Check for student enrollments first to maintain data integrity
    $check_stmt = $db->prepare("SELECT COUNT(*) as count FROM student_program WHERE program_code = ?");
    $check_stmt->bind_param("s", $program_code);
    $check_stmt->execute();
    $result = $check_stmt->get_result()->fetch_assoc();
    
    if ($result['count'] > 0) {
        return ['status' => 'error', 'message' => "Cannot delete program. {$result['count']} students are enrolled."];
    }

    try {
        $db->begin_transaction();

        $courseCodes = [];
        $courses_stmt = $db->prepare("SELECT DISTINCT course_code FROM program_courses WHERE program_code = ?");
        $courses_stmt->bind_param("s", $program_code);
        $courses_stmt->execute();
        $courses_result = $courses_stmt->get_result();
        while ($row = $courses_result->fetch_assoc()) {
            $courseCodes[] = (string)$row['course_code'];
        }
        $courses_stmt->close();

        $mapping_stmt = $db->prepare("DELETE FROM program_courses WHERE program_code = ?");
        $mapping_stmt->bind_param("s", $program_code);
        $mapping_stmt->execute();
        $removedMappings = max(0, $mapping_stmt->affected_rows);
        $mapping_stmt->close();

        $deactivatedCourses = deactivate_unmapped_courses($db, $courseCodes);

        $delete_stmt = $db->prepare("DELETE FROM programs WHERE program_code = ?");
        $delete_stmt->bind_param("s", $program_code);
        $delete_stmt->execute();
        $deletedPrograms = $delete_stmt->affected_rows;
        $delete_stmt->close();

        if ($deletedPrograms < 1) {
            $db->rollback();
            return ['status' => 'error', 'message' => 'Program not found.'];
        }

        $db->commit();
        return [
            'status' => 'success',
            'message' => "Program deleted successfully. Removed {$removedMappings} course assignment(s); {$deactivatedCourses} course(s) with no remaining program were marked inactive."
        ];
    } catch (Throwable $e) {
        $db->rollback();
        error_log('Program deletion error: ' . $e->getMessage());
        return ['status' => 'error', 'message' => 'Error deleting program. Please try again.'];
    }
}

/**
 * Fetches all departments for dropdowns.
 * @param mysqli $db
 * @return array
 */
function get_all_departments(mysqli $db) {
    $departments = [];
    
    // Detect the correct ID column
    $deptIdCol = detect_column($db, 'departments', ['id', 'DeptID', 'department_id']) ?? 'id';
    $deptNameCol = detect_column($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']) ?? 'department_name';
    
    $result = $db->query("SELECT `{$deptIdCol}` as id, `{$deptNameCol}` as department_name FROM departments ORDER BY `{$deptNameCol}`");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $departments[] = $row;
        }
        $result->free();
    }
    return $departments;
}

/**
 * Runs comprehensive database schema fixes for the programs system
 * @param mysqli $db The database connection
 * @return array Status and message
 */
function run_schema_fix(mysqli $db) {
    $messages = [];

    try {
        // Check and add missing columns to programs table
        $required_columns = [
            'program_type' => "ENUM('degree','diploma','certificate') NOT NULL DEFAULT 'degree' AFTER program_name",
            'study_mode' => "VARCHAR(50) DEFAULT NULL AFTER program_type",
            // Registration period model (drives Registration & Enrolment: semester vs term).
            'period_mode' => "ENUM('semester','term') NOT NULL DEFAULT 'semester' AFTER study_mode",
            'program_duration' => "DECIMAL(4,2) DEFAULT NULL COMMENT 'Duration in years' AFTER study_mode",
            'program_description' => "TEXT AFTER program_duration",
            'department_id' => "INT(11) DEFAULT NULL AFTER program_description",
            'is_active' => "TINYINT(1) NOT NULL DEFAULT 1 AFTER department_id"
        ];

        // Get current columns
        $result = $db->query("DESCRIBE programs");
        $current_columns = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $current_columns[] = $row['Field'];
            }
            $result->free();
        }

        // Add missing columns
        foreach ($required_columns as $column_name => $column_definition) {
            if (!in_array($column_name, $current_columns)) {
                $sql = "ALTER TABLE programs ADD COLUMN $column_name $column_definition";
                if ($db->query($sql)) {
                    $messages[] = "Added missing column: $column_name";
                } else {
                    return ['status' => 'error', 'message' => "Failed to add column $column_name: " . $db->error];
                }
            }
        }

        if (in_array('program_duration', $current_columns, true)) {
            $durationCol = $db->query("SHOW COLUMNS FROM programs LIKE 'program_duration'")->fetch_assoc();
            if ($durationCol && stripos((string)$durationCol['Type'], 'decimal(4,2)') === false) {
                $db->query("ALTER TABLE programs MODIFY COLUMN program_duration DECIMAL(4,2) DEFAULT NULL COMMENT 'Duration in years'");
                $messages[] = "Updated program_duration precision to years with decimals";
            }
        }

        // Ensure departments table exists
        $result = $db->query("SHOW TABLES LIKE 'departments'");
        if ($result->num_rows == 0) {
            $create_dept_sql = "CREATE TABLE departments (
                id INT(11) NOT NULL AUTO_INCREMENT,
                department_name VARCHAR(255) NOT NULL,
                deptId VARCHAR(50) DEFAULT NULL,
                department_code VARCHAR(50) DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY deptId (deptId),
                UNIQUE KEY department_code (department_code)
            )";

            if ($db->query($create_dept_sql)) {
                $messages[] = "Created departments table";

                // Add default departments
                $default_depts = [
                    ['Computer Science', 'CS', 'COMP001'],
                    ['Mathematics', 'MATH', 'MATH001'],
                    ['Physics', 'PHYS', 'PHYS001']
                ];

                foreach ($default_depts as $dept) {
                    $stmt = $db->prepare("INSERT INTO departments (department_name, deptId, department_code) VALUES (?, ?, ?)");
                    $stmt->bind_param("sss", $dept[0], $dept[1], $dept[2]);
                    if ($stmt->execute()) {
                        $messages[] = "Added department: {$dept[0]}";
                    }
                    $stmt->close();
                }
            } else {
                return ['status' => 'error', 'message' => "Failed to create departments table: " . $db->error];
            }
        }

        // Ensure student_program table exists
        $result = $db->query("SHOW TABLES LIKE 'student_program'");
        if ($result->num_rows == 0) {
            $create_sp_sql = "CREATE TABLE student_program (
                id INT(11) NOT NULL AUTO_INCREMENT,
                student_id VARCHAR(50) NOT NULL,
                program_code VARCHAR(50) NOT NULL,
                enrollment_date DATE DEFAULT CURDATE(),
                status ENUM('active','inactive','completed','suspended') DEFAULT 'active',
                PRIMARY KEY (id),
                KEY student_id (student_id),
                KEY program_code (program_code),
                FOREIGN KEY (program_code) REFERENCES programs(program_code) ON DELETE CASCADE
            )";

            if ($db->query($create_sp_sql)) {
                $messages[] = "Created student_program table";
            } else {
                return ['status' => 'error', 'message' => "Failed to create student_program table: " . $db->error];
            }
        }

        $message = empty($messages)
            ? "Database schema is already up to date."
            : "Schema fixes applied successfully:\n" . implode("\n", $messages);

        return ['status' => 'success', 'message' => $message];

    } catch (Exception $e) {
        return ['status' => 'error', 'message' => 'Schema fix failed: ' . $e->getMessage()];
    }
}


// 4. ============================== REQUEST HANDLING (Controller Logic) ==============================
$success_message = $_SESSION[SUCCESS_MSG] ?? null;
$error_message = $_SESSION[ERROR_MSG] ?? null;
unset($_SESSION[SUCCESS_MSG], $_SESSION[ERROR_MSG]);

$view_program = null;
$edit_program = null;

// Generate CSRF token for all forms on the page
$csrf_token = generate_csrf_token();

// --- Handle POST requests for all actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $action = $_POST['action'];
        $result = [];

        switch ($action) {
            case 'add_program':
                $requested_code = trim($_POST['program_code'] ?? '');
                $result = save_program($db, $_POST);
                // If duplicate, pre-open the edit form after redirect
                if (isset($result['status']) && $result['status'] === 'error' && stripos($result['message'] ?? '', 'already exists') !== false && $requested_code !== '') {
                    $_SESSION['open_edit_program_code'] = $requested_code;
                }
                break;
            case 'update_program':
                $result = save_program($db, $_POST);
                break;
            case 'delete_program':
                $result = delete_program($db, $_POST['program_code']);
                break;
            case 'fix_schema':
                $result = run_schema_fix($db);
                break;
        }

        if (!empty($result)) {
            redirect_with_message($result['status'], $result['message']);
        }
    } catch (Throwable $e) {
        error_log('POST handling error: ' . $e->getMessage());
        $_SESSION[ERROR_MSG] = 'Request failed: ' . $e->getMessage();
        header('Location: programs.php');
        exit;
    }
}

// Lightweight AJAX endpoint to check program code availability
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'check_program_code') {
    header('Content-Type: application/json');
    $code = trim($_GET['program_code'] ?? '');
    $exists = false;
    if ($code !== '') {
        $stmt = $db->prepare('SELECT 1 FROM programs WHERE program_code = ? LIMIT 1');
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
    }
    echo json_encode(['exists' => $exists]);
    exit;
}

// --- Handle GET requests for viewing/editing ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];
    $program_code = $_GET['program_code'] ?? '';

    if (!empty($program_code)) {
        if ($action === 'view') {
            $view_program = get_program_details($db, $program_code);
            if (!$view_program) $error_message = "Program not found.";
        } elseif ($action === 'edit') {
            $edit_program = get_program_details($db, $program_code);
            if (!$edit_program) $error_message = "Program not found.";
        }
    }
}

// Auto-open edit if last add failed due to duplicate
if (isset($_SESSION['open_edit_program_code']) && empty($edit_program)) {
    $codeToOpen = $_SESSION['open_edit_program_code'];
    unset($_SESSION['open_edit_program_code']);
    $maybe = get_program_details($db, $codeToOpen);
    if ($maybe) { $edit_program = $maybe; }
}

// Only now include the header to avoid output before redirects
require_once 'includes/header.php';
?>
<style>
    /* stat-card, stat-icon → assets/css/dashboard.css */
    .program-code-badge {
        font-family: 'JetBrains Mono', 'Fira Code', monospace;
        background: #f8f9fa; border: 1px solid #e9ecef;
        padding: 2px 6px; border-radius: 4px; font-size: 0.85rem;
    }
    .modal-header.bg-primary { background: linear-gradient(135deg, #6f42c1 0%, #4e2a84 100%) !important; border: none !important; }
</style>
<?php

// 5. ============================== DATA FETCHING FOR PAGE DISPLAY ==============================
// These will be used to render the final HTML view.

// Initialize defaults in case of errors
$departments = [];
$programs = [];
$stats = [
    'total_programs' => 0,
    'active_programs' => 0,
    'degree' => 0,
    'diploma' => 0,
    'certificate' => 0,
    'departments' => 0
];
$program_enrollments = [];

try {
    $departments = get_all_departments($db);
    $programs = []; // Fetch all programs
    $join = determine_programs_departments_join($db);
    $program_result = $db->query("SELECT p.*, " . $join['deptNameExpr'] . " AS department_name FROM programs p " . $join['joinSql'] . " ORDER BY p.program_name");
    if($program_result) {
        while ($row = $program_result->fetch_assoc()) {
            $programs[] = $row;
        }
    }

    // Fetch stats (simplified from original)
    $stats = [
        'total_programs' => count($programs),
        'active_programs' => count(array_filter($programs, fn($p) => ($p['is_active'] ?? 0) == 1)),
        'degree' => count(array_filter($programs, fn($p) => strtolower($p['program_type'] ?? '') === 'degree')),
        'diploma' => count(array_filter($programs, fn($p) => strtolower($p['program_type'] ?? '') === 'diploma')),
        'certificate' => count(array_filter($programs, fn($p) => strtolower($p['program_type'] ?? '') === 'certificate')),
        'departments' => count($departments)
    ];

    // Fetch enrollment data
    $program_enrollments = [];
    $enrollment_query = "SELECT p.program_name, COUNT(*) as student_count
                                    FROM student_program sp
                                    JOIN programs p ON sp.program_code = p.program_code
                         GROUP BY sp.program_code, p.program_name
                         ORDER BY student_count DESC LIMIT 10";

    try {
        $enrollment_result = $db->query($enrollment_query);
        if ($enrollment_result) {
            while ($row = $enrollment_result->fetch_assoc()) {
                $program_enrollments[] = $row;
            }
            $enrollment_result->free();
        } else {
            error_log("Enrollment query failed: " . $db->error);
        }
    } catch (Exception $e) {
        error_log("Error fetching enrollment data: " . $e->getMessage());
        $program_enrollments = []; // Ensure it's empty on error
    }

} catch (Exception $e) {
    $error_message = 'A critical error occurred while fetching page data: ' . $e->getMessage();
    // In a real app, you might want to render a dedicated error page here.
}


// 6. ============================== HTML VIEW ==============================
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-graduation-cap me-2 text-primary"></i>Programs Management</h5>
                <p class="page-subtitle mb-0">Manage academic programs, degree types, and enrollment settings</p>
            </div>
            <div class="header-actions d-flex gap-2">
                <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                    <i class="fas fa-plus me-1"></i>Add Program
                </button>
                <a href="index.php" class="btn btn-outline-primary shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
        <?= htmlspecialchars($success_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
        <?= htmlspecialchars($error_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($edit_program)): ?>
    <div class="alert alert-warning alert-dismissible fade show py-2" role="alert">
        <h5>Edit Program: <?= htmlspecialchars($edit_program['program_name'] ?? 'Unknown Program') ?></h5>
        <form method="POST" class="mt-3 needs-validation" novalidate>
            <input type="hidden" name="original_code" value="<?= htmlspecialchars($edit_program['program_code'] ?? '') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="edit_program_code" class="form-label">Program Code *</label>
                        <input type="text" class="form-control" id="edit_program_code" name="program_code"
                               value="<?= htmlspecialchars($edit_program['program_code'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_program_name" class="form-label">Program Name *</label>
                        <input type="text" class="form-control" id="edit_program_name" name="program_name"
                               value="<?= htmlspecialchars($edit_program['program_name'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="edit_program_type" class="form-label">Program Type *</label>
                        <select class="form-control" id="edit_program_type" name="program_type" required>
                            <option value="">Select Type</option>
                            <option value="degree" <?= strtolower($edit_program['program_type'] ?? '') == 'degree' ? 'selected' : '' ?>>Degree</option>
                            <option value="diploma" <?= strtolower($edit_program['program_type'] ?? '') == 'diploma' ? 'selected' : '' ?>>Diploma</option>
                            <option value="certificate" <?= strtolower($edit_program['program_type'] ?? '') == 'certificate' ? 'selected' : '' ?>>Certificate</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_academic_structure" class="form-label">Academic Structure *</label>
                        <select class="form-control" id="edit_academic_structure" name="academic_structure" required onchange="toggleEditDurationFields()">
                            <option value="short_course" <?= ($edit_program['academic_structure'] ?? '') == 'short_course' ? 'selected' : '' ?>>Short Course</option>
                            <option value="certificate_term" <?= ($edit_program['academic_structure'] ?? '') == 'certificate_term' ? 'selected' : '' ?>>Certificate (Term-based)</option>
                            <option value="diploma_term" <?= ($edit_program['academic_structure'] ?? '') == 'diploma_term' ? 'selected' : '' ?>>Diploma (Term-based)</option>
                            <option value="semester_exception" <?= ($edit_program['academic_structure'] ?? '') == 'semester_exception' ? 'selected' : '' ?>>Transport and Logistics (Semester-based)</option>
                        </select>
                    </div>
                    <div class="mb-3" id="edit_duration_fields" style="display: <?= ($edit_program['academic_structure'] ?? '') == 'short_course' ? 'block' : 'none' ?>;">
                        <div class="row">
                            <div class="col-6">
                                <label for="edit_duration_value" class="form-label">Duration Value</label>
                                <input type="number" class="form-control" id="edit_duration_value" name="duration_value" value="<?= htmlspecialchars((string)($edit_program['duration_value'] ?? '')) ?>">
                            </div>
                            <div class="col-6">
                                <label for="edit_duration_unit" class="form-label">Duration Unit</label>
                                <select class="form-control" id="edit_duration_unit" name="duration_unit">
                                    <option value="days" <?= ($edit_program['duration_unit'] ?? '') == 'days' ? 'selected' : '' ?>>Days</option>
                                    <option value="weeks" <?= ($edit_program['duration_unit'] ?? '') == 'weeks' ? 'selected' : '' ?>>Weeks</option>
                                    <option value="months" <?= ($edit_program['duration_unit'] ?? '') == 'months' ? 'selected' : '' ?>>Months</option>
                                    <option value="years" <?= ($edit_program['duration_unit'] ?? '') == 'years' ? 'selected' : '' ?>>Years</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_examination_type" class="form-label">Examination Type</label>
                        <select class="form-control" id="edit_examination_type" name="examination_type">
                            <option value="external" <?= ($edit_program['examination_type'] ?? 'external') == 'external' ? 'selected' : '' ?>>External Examination</option>
                            <option value="internal" <?= ($edit_program['examination_type'] ?? '') == 'internal' ? 'selected' : '' ?>>Internal Examination (Short Courses only)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_program_duration" class="form-label">Program Duration (Years)</label>
                        <input type="number" class="form-control" id="edit_program_duration" name="program_duration" 
                               value="<?= htmlspecialchars((string)($edit_program['program_duration'] ?? '')) ?>" step="0.25" min="0.25" max="10">
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label for="edit_program_description" class="form-label">Program Description</label>
                <textarea class="form-control" id="edit_program_description" name="program_description" rows="3"><?= htmlspecialchars($edit_program['program_description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="edit_department_id" class="form-label">Department *</label>
                <select class="form-control" id="edit_department_id" name="department_id" required>
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= ($edit_program['department_id'] ?? '') == $dept['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1" <?= isset($edit_program['is_active']) && $edit_program['is_active'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="edit_is_active">Is Active</label>
                        </div>
            <button type="submit" name="action" value="update_program" class="btn btn-primary">Update Program</button>
            <a href="programs.php" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-list"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($stats['total_programs']) ?></h3>
                        <p class="text-muted mb-0">Total Programs</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($stats['active_programs']) ?></h3>
                        <p class="text-muted mb-0">Active Programs</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-user-graduate"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($stats['degree']) ?></h3>
                        <p class="text-muted mb-0">Degree Programs</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3 text-white"><i class="fas fa-building"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($stats['departments']) ?></h3>
                        <p class="text-muted mb-0">Departments</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12">
            <div class="data-table-card mb-4">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Programs List
                        </h5>
                        <div class="header-actions">
                            <button id="exportPrograms" class="btn btn-success btn-sm" type="button">
                                <i class="fas fa-file-excel me-2"></i>Export
                            </button>
                            <button id="printPrograms" class="btn btn-outline-secondary btn-sm" type="button">
                                <i class="fas fa-print me-2"></i>Print
                            </button>
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" id="filterProgramsDropdown" data-bs-toggle="dropdown">
                                    <i class="fas fa-filter me-2"></i>Filter
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="filterProgramsDropdown">
                                    <li><a class="dropdown-item" href="#" data-programs-filter="all">All Programs</a></li>
                                    <li><a class="dropdown-item" href="#" data-programs-filter="active">Active Only</a></li>
                                    <li><a class="dropdown-item" href="#" data-programs-filter="inactive">Inactive Only</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="programsTable" class="table table-hover align-middle table-full-width">
                            <thead class="table-light">
                                <tr>
                                    <th>Program Code</th>
                                    <th>Program Name</th>
                                    <th>Type</th>
                                    <th>Department</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($programs)): ?>
                                    <tr><td colspan="7" class="text-center py-4 text-muted">No programs found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($programs as $program): ?>
                                        <tr>
                                            <td><span class="program-code-badge fw-bold"><?= htmlspecialchars($program['program_code']) ?></span></td>
                                            <td class="fw-semibold text-dark"><?= htmlspecialchars($program['program_name']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= strtolower($program['program_type'] ?? '') === 'degree' ? 'primary' : (strtolower($program['program_type'] ?? '') === 'diploma' ? 'info' : 'secondary') ?>-subtle text-<?= strtolower($program['program_type'] ?? '') === 'degree' ? 'primary' : (strtolower($program['program_type'] ?? '') === 'diploma' ? 'info' : 'secondary') ?> border">
                                                    <?= htmlspecialchars(ucfirst(strtolower($program['program_type'] ?? ''))) ?>
                                                </span>
                                            </td>
                                            <td><span class="small text-muted"><?= htmlspecialchars($program['department_name'] ?? 'N/A') ?></span></td>
                                            <td><span class="small"><?= htmlspecialchars(format_program_duration_years($program['program_duration'] ?? null)) ?></span></td>
                                            <td>
                                                <span class="badge bg-<?= ($program['is_active'] ?? 0) ? 'success' : 'danger' ?>-subtle text-<?= ($program['is_active'] ?? 0) ? 'success' : 'danger' ?> border">
                                                    <?= ($program['is_active'] ?? 0) ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-1">
                                                    <a href="?action=view&program_code=<?= urlencode($program['program_code']) ?>" class="btn btn-sm btn-outline-info" title="View">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="?action=edit&program_code=<?= urlencode($program['program_code']) ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this program?');">
                                                        <input type="hidden" name="action" value="delete_program">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                        <input type="hidden" name="program_code" value="<?= htmlspecialchars($program['program_code']) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-12">
            <div class="data-table-card mb-4">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-bar me-2"></i>Enrollment by Program
                        </h5>
                        <small class="text-muted">
                            <i class="fas fa-keyboard me-1"></i>
                            <span class="d-none d-md-inline">Ctrl+R: Refresh, Ctrl+E: Export, Ctrl+1-3: Chart Types</span>
                            <span class="d-md-none">Shortcuts available</span>
                        </small>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($program_enrollments)): ?>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div id="chartLoadingSpinner" class="d-none">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <span class="ms-2 small text-muted">Loading chart...</span>
                            </div>
                            <div class="d-flex gap-2">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button id="chartTypeBar" class="btn btn-outline-primary btn-sm active" title="Bar Chart">
                                        <i class="fas fa-chart-bar"></i>
                                    </button>
                                    <button id="chartTypeLine" class="btn btn-outline-primary btn-sm" title="Line Chart">
                                        <i class="fas fa-chart-line"></i>
                                    </button>
                                    <button id="chartTypeDoughnut" class="btn btn-outline-primary btn-sm" title="Doughnut Chart">
                                        <i class="fas fa-chart-pie"></i>
                                    </button>
                                </div>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button id="refreshChart" class="btn btn-outline-secondary btn-sm" title="Refresh Chart">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                    <button id="exportChart" class="btn btn-outline-success btn-sm" title="Export as PNG">
                                        <i class="fas fa-download"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div style="position: relative; height: 350px;">
                            <canvas id="programEnrollmentChart"></canvas>
                        </div>
                        <div class="mt-3">
                            <div class="row text-center">
                                <div class="col-6 col-md-3 mb-3">
                                    <div class="stat-summary-item">
                                        <div class="h4 text-primary mb-0" id="totalStudents">-</div>
                                        <small class="text-muted">Total Students</small>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3 mb-3">
                                    <div class="stat-summary-item">
                                        <div class="h4 text-info mb-0" id="avgEnrollment">-</div>
                                        <small class="text-muted">Avg. per Program</small>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3 mb-3">
                                    <div class="stat-summary-item">
                                        <div class="h4 text-success mb-0" id="topProgramName">-</div>
                                        <small class="text-muted" id="topProgramLabel">Top Program</small>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3 mb-3">
                                    <div class="stat-summary-item">
                                        <div class="h4 text-warning mb-0" id="medianEnrollment">-</div>
                                        <small class="text-muted">Median Enrollment</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No enrollment data available.</p>
                            <small class="text-warning">Check if the student_program table has data.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="addProgramModal" tabindex="-1" aria-labelledby="addProgramModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="addProgramForm" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="add_program">
            <div class="modal-header">
                <h5 class="modal-title" id="addProgramModalLabel">Add New Program</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="program_code" class="form-label">Program Code *</label>
                            <input type="text" class="form-control" id="program_code" name="program_code" 
                                   required pattern="[A-Z0-9\-.]+" 
                                   title="Program code should contain only uppercase letters, numbers, and hyphens"
                                   placeholder="e.g., MATH-BSC">
                            <div class="invalid-feedback">
                                Please enter a valid program code.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="program_name" class="form-label">Program Name *</label>
                            <input type="text" class="form-control" id="program_name" name="program_name" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                         <div class="col-md-6">
                             <label for="program_type" class="form-label">Program Type *</label>
                             <select class="form-select" id="program_type" name="program_type" required>
                                 <option value="">Select type</option>
                                 <option value="degree">Degree</option>
                                 <option value="diploma">Diploma</option>
                                 <option value="certificate">Certificate</option>
                             </select>
                         </div>
                         <div class="col-md-6">
                              <label for="academic_structure" class="form-label">Academic Structure *</label>
                              <select class="form-select" id="academic_structure" name="academic_structure" required onchange="toggleAddDurationFields()">
                                  <option value="short_course">Short Course</option>
                                  <option value="certificate_term" selected>Certificate (Term-based)</option>
                                  <option value="diploma_term">Diploma (Term-based)</option>
                                  <option value="semester_exception">Transport and Logistics (Semester-based)</option>
                              </select>
                         </div>
                    </div>
                    <div class="row mb-3" id="add_duration_fields" style="display: none;">
                          <div class="col-md-6">
                              <label for="duration_value" class="form-label">Duration Value</label>
                              <input type="number" class="form-control" id="duration_value" name="duration_value">
                          </div>
                          <div class="col-md-6">
                              <label for="duration_unit" class="form-label">Duration Unit</label>
                              <select class="form-select" id="duration_unit" name="duration_unit">
                                  <option value="days">Days</option>
                                  <option value="weeks">Weeks</option>
                                  <option value="months">Months</option>
                                  <option value="years">Years</option>
                              </select>
                          </div>
                    </div>
                    <div class="row mb-3">
                         <div class="col-md-6">
                              <label for="examination_type" class="form-label">Examination Type</label>
                              <select class="form-select" id="examination_type" name="examination_type">
                                  <option value="external" selected>External Examination</option>
                                  <option value="internal">Internal Examination (Short Courses only)</option>
                              </select>
                         </div>
                         <div class="col-md-6">
                             <label for="department_id" class="form-label">Department *</label>
                             <select class="form-select" id="department_id" name="department_id" required>
                                 <option value="" disabled selected>Select department</option>
                                 <?php foreach ($departments as $department): ?>
                                     <option value="<?= $department['id'] ?>"><?= htmlspecialchars($department['department_name']) ?></option>
                                 <?php endforeach; ?>
                             </select>
                         </div>
                    </div>
                    <div class="row mb-3">
                         <div class="col-md-6">
                             <div class="form-check mt-4">
                                 <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active" checked>
                                 <label class="form-check-label" for="is_active">Active Program</label>
                             </div>
                         </div>
                    </div>
                     <div class="row mb-3">
                         <div class="col-md-6">
                             <label for="program_duration" class="form-label">Duration (years)</label>
                             <input type="number" class="form-control" id="program_duration" name="program_duration" min="0.25" max="10" step="0.25">
                         </div>
                         <div class="col-md-6">
                             <label for="program_description" class="form-label">Description</label>
                             <textarea class="form-control" id="program_description" name="program_description" rows="3"></textarea>
                         </div>
                     </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Program</button>
                </div>
            </form>
        </div>
    </div>
</div>


<style>
.dt-buttons{display:none}

/* Enhanced Chart Styles */
#programEnrollmentChart {
    max-height: 350px;
}

/* Statistics Cards Enhancement */
#totalStudents, #avgEnrollment, #topProgramName, #medianEnrollment {
    transition: all 0.3s ease;
    font-weight: 600;
}

.stat-summary-item {
    padding: 0.5rem;
    border-right: 1px solid #dee2e6;
}
.col-6:last-child .stat-summary-item, .col-md-3:last-child .stat-summary-item {
    border-right: none;
}

@media (max-width: 767.98px) {
    .col-6:nth-child(2n) .stat-summary-item {
        border-right: none;
    }
    .col-6:nth-child(2n+1) .stat-summary-item {
        border-right: 1px solid #dee2e6;
    }
     .col-6.mb-3 {
        border-bottom: 1px solid #dee2e6;
    }
    .col-6:nth-last-child(-n+2).mb-3 {
        border-bottom: none;
    }
}

.border-end {
    border-right: 1px solid #dee2e6 !important;
}

@media (max-width: 576px) {
    .border-end {
        border-right: none !important;
        border-bottom: 1px solid #dee2e6 !important;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
    }
}

/* Chart Action Buttons */
.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

.btn-group-sm .btn i {
    font-size: 0.75rem;
}

/* Loading Spinner */
.spinner-border-sm {
    width: 1rem;
    height: 1rem;
}

/* Enhanced Empty State */
.text-center i.fa-3x {
    opacity: 0.5;
    margin-bottom: 1rem;
}

/* Statistics Animation */
@keyframes countUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

#totalStudents, #avgEnrollment, #topProgramName, #medianEnrollment {
    animation: countUp 0.6s ease-out;
}

/* Responsive Chart */
@media (max-width: 768px) {
    #programEnrollmentChart {
        max-height: 300px;
    }
}
</style>
<script>
function toggleAddDurationFields() {
    var structure = document.getElementById('academic_structure').value;
    var durationFields = document.getElementById('add_duration_fields');
    if (structure === 'short_course') {
        durationFields.style.display = 'flex';
    } else {
        durationFields.style.display = 'none';
    }
}
function toggleEditDurationFields() {
    var structure = document.getElementById('edit_academic_structure').value;
    var durationFields = document.getElementById('edit_duration_fields');
    if (structure === 'short_course') {
        durationFields.style.display = 'block';
    } else {
        durationFields.style.display = 'none';
    }
}
// Create enrollment chart if data is available
$(function() {
    // Exit early if canvas doesn't exist
    const chartCanvas = document.getElementById('programEnrollmentChart');
    if (!chartCanvas) {
        console.info('Chart canvas not found. Chart will not be rendered.');
    }
    
    <?php if (!empty($program_enrollments)): ?>
        const enrollmentData = <?php echo json_encode($program_enrollments); ?>;

        // Color palette for different programs
        const colors = [
            'rgba(54, 162, 235, 0.8)',   // Blue
            'rgba(255, 99, 132, 0.8)',   // Red
            'rgba(75, 192, 192, 0.8)',   // Green
            'rgba(255, 205, 86, 0.8)',   // Yellow
            'rgba(153, 102, 255, 0.8)',  // Purple
            'rgba(255, 159, 64, 0.8)',   // Orange
            'rgba(199, 199, 199, 0.8)',  // Grey
            'rgba(83, 102, 255, 0.8)',   // Indigo
            'rgba(255, 99, 255, 0.8)',   // Pink
            'rgba(99, 255, 132, 0.8)'    // Lime
        ];

        const borderColors = colors.map(color => color.replace('0.8)', '1)'));

        let chartInstance = null;
        let currentChartType = 'bar';

        function createChart(chartType = 'bar') {
            const ctx = document.getElementById('programEnrollmentChart');
            if (!ctx) {
                console.warn('Chart canvas not found. Skipping chart initialization.');
                return;
            }

            currentChartType = chartType;

            // Show loading spinner
            $('#chartLoadingSpinner').removeClass('d-none');

            // Destroy existing chart if it exists
            if (chartInstance) {
                chartInstance.destroy();
            }

            // Update button states
            $('#chartTypeBar, #chartTypeLine, #chartTypeDoughnut').removeClass('active');
            $(`#chartType${chartType.charAt(0).toUpperCase() + chartType.slice(1)}`).addClass('active');

            setTimeout(() => {
                chartInstance = new Chart(ctx, {
                    type: chartType,
                    data: {
                        labels: enrollmentData.map((item, index) =>
                            item.program_name.length > 25 ?
                            `${item.program_name.substring(0, 22)}...` :
                            item.program_name
                        ),
                        datasets: chartType === 'doughnut' ? [{
                            label: 'Enrolled Students',
                            data: enrollmentData.map(item => parseInt(item.student_count)),
                            backgroundColor: colors.slice(0, enrollmentData.length),
                            borderColor: borderColors.slice(0, enrollmentData.length),
                            borderWidth: 2,
                            hoverBackgroundColor: colors.slice(0, enrollmentData.length).map(color => color.replace('0.8)', '1)')),
                            hoverBorderColor: borderColors.slice(0, enrollmentData.length),
                            hoverBorderWidth: 3,
                            hoverOffset: 4
                        }] : [{
                            label: 'Enrolled Students',
                            data: enrollmentData.map(item => parseInt(item.student_count)),
                            backgroundColor: enrollmentData.map((_, index) => colors[index % colors.length]),
                            borderColor: enrollmentData.map((_, index) => borderColors[index % borderColors.length]),
                            borderWidth: chartType === 'line' ? 3 : 2,
                            borderRadius: chartType === 'bar' ? 6 : 0,
                            borderSkipped: false,
                            fill: chartType === 'line' ? false : true,
                            tension: chartType === 'line' ? 0.4 : 0,
                            pointBackgroundColor: colors.map(color => color.replace('0.8)', '1)')),
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 6,
                            pointHoverRadius: 8,
                            hoverBackgroundColor: enrollmentData.map((_, index) =>
                                colors[index % colors.length].replace('0.8)', '1)')
                            ),
                            hoverBorderColor: enrollmentData.map((_, index) =>
                                borderColors[index % borderColors.length]
                            ),
                            hoverBorderWidth: 3,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: {
                            duration: 1000,
                            easing: 'easeOutQuart'
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: 'Top 10 Programs by Enrollment',
                                font: {
                                    size: 16,
                                    weight: 'bold'
                                },
                                padding: {
                                    top: 10,
                                    bottom: 30
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: 'rgba(255, 255, 255, 0.2)',
                                borderWidth: 1,
                                cornerRadius: 8,
                                displayColors: true,
                                callbacks: {
                                    title: function(context) {
                                        return enrollmentData[context[0].dataIndex].program_name;
                                    },
                                    label: function(context) {
                                        // Handle both doughnut (context.parsed) and bar/line (context.parsed.y)
                                        const count = chartType === 'doughnut' ? context.parsed : context.parsed.y;
                                        const percentage = ((count / enrollmentData.reduce((sum, item) => sum + parseInt(item.student_count), 0)) * 100).toFixed(1);
                                        return `Students: ${count} (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        scales: chartType === 'doughnut' ? {} : {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0,
                                    font: {
                                        size: 12
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                }
                            },
                            x: {
                                ticks: {
                                    font: {
                                        size: 11
                                    },
                                    maxRotation: 45,
                                    minRotation: 45
                                },
                                grid: {
                                    display: false
                                }
                            }
                        },
                        onClick: function(event, elements) {
                            if (elements.length > 0) {
                                const dataIndex = elements[0].index;
                                const program = enrollmentData[dataIndex];
                                // You can add navigation to program details here
                                console.log('Clicked program:', program);
                            }
                        }
                    }
                });

                // Hide loading spinner
                $('#chartLoadingSpinner').addClass('d-none');

                // Update statistics
                updateStatistics();
            }, 500);
        }

        function updateStatistics() {
            const counts = enrollmentData.map(item => parseInt(item.student_count)).sort((a, b) => a - b);
            const totalStudents = counts.reduce((sum, count) => sum + count, 0);
            const avgEnrollment = Math.round(totalStudents / counts.length);
            
            // Median calculation
            const mid = Math.floor(counts.length / 2);
            const medianEnrollment = counts.length % 2 !== 0 ? counts[mid] : (counts[mid - 1] + counts[mid]) / 2;

            const topProgram = enrollmentData[0]; // Already sorted DESC by count in PHP

            $('#totalStudents').text(totalStudents.toLocaleString());
            $('#avgEnrollment').text(avgEnrollment.toLocaleString());
            $('#medianEnrollment').text(Math.round(medianEnrollment).toLocaleString());

            if (topProgram) {
                const topProgramName = topProgram.program_name.length > 15 ? topProgram.program_name.substring(0, 12) + '...' : topProgram.program_name;
                $('#topProgramName').text(topProgramName);
                $('#topProgramLabel').html(`Top Program <span class="badge bg-success ms-1">${parseInt(topProgram.student_count).toLocaleString()}</span>`);
            } else {
                $('#topProgramName').text('N/A');
                $('#topProgramLabel').text('Top Program');
            }
        }

        // Initialize chart
        createChart();

        // Refresh chart functionality
        $('#refreshChart').on('click', function() {
            $(this).find('i').addClass('fa-spin');
            createChart();
            setTimeout(() => {
                $(this).find('i').removeClass('fa-spin');
            }, 1000);
        });

        // Chart type switching functionality
        $('#chartTypeBar').on('click', function() {
            if (currentChartType !== 'bar') {
                createChart('bar');
            }
        });

        $('#chartTypeLine').on('click', function() {
            if (currentChartType !== 'line') {
                createChart('line');
            }
        });

        $('#chartTypeDoughnut').on('click', function() {
            if (currentChartType !== 'doughnut') {
                createChart('doughnut');
            }
        });

        // Export chart functionality
        $('#exportChart').on('click', function() {
            if (chartInstance) {
                const link = document.createElement('a');
                link.download = `enrollment-chart-${currentChartType}-` + new Date().toISOString().split('T')[0] + '.png';
                link.href = chartInstance.toBase64Image();
                link.click();

                // Show success feedback
                const originalIcon = $(this).find('i').attr('class');
                $(this).find('i').attr('class', 'fas fa-check');
                setTimeout(() => {
                    $(this).find('i').attr('class', originalIcon);
                }, 1500);
            }
        });

        // Add keyboard shortcuts
        $(document).on('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'r':
                        e.preventDefault();
                        $('#refreshChart').click();
                        break;
                    case 'e':
                        e.preventDefault();
                        $('#exportChart').click();
                        break;
                    case '1':
                        e.preventDefault();
                        $('#chartTypeBar').click();
                        break;
                    case '2':
                        e.preventDefault();
                        $('#chartTypeLine').click();
                        break;
                    case '3':
                        e.preventDefault();
                        $('#chartTypeDoughnut').click();
                        break;
                }
            }
        });

    <?php endif; ?>
});

// Enhance Programs table with DataTables, export/print, and filtering
$(function() {
    // Bootstrap client-side validation for add/edit program forms
    document.querySelectorAll('.needs-validation').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
            form.classList.add('was-validated');
        }, false);
    });

    // Live check for program code availability in Add Program form
    const $code = $('#program_code');
    const $addBtn = $('#addProgramForm button[type="submit"]');
    const $feedback = $('<div class="form-text mt-1" id="programCodeFeedback"></div>').insertAfter($code);
    let debounceTimer;
    function checkCode() {
        const val = ($code.val() || '').trim();
        if (!val) { $feedback.text(''); $addBtn.prop('disabled', false); return; }
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            $.get('programs.php', { ajax: 'check_program_code', program_code: val }, function(resp) {
                if (resp && resp.exists) {
                    $feedback.html('<span class="text-danger">Code already exists. <a href="?action=edit&program_code=' + encodeURIComponent(val) + '">Edit existing</a></span>');
                    $addBtn.prop('disabled', true);
                } else {
                    $feedback.html('<span class="text-success">Code is available</span>');
                    $addBtn.prop('disabled', false);
                }
            }, 'json').fail(() => {
                $feedback.text('');
                $addBtn.prop('disabled', false);
            });
        }, 300);
    }
    $code.on('input blur', checkCode);

    const durationDefaults = { degree: '4', diploma: '2', certificate: '1' };
    function applyDurationDefault(typeSelector, durationSelector) {
        const $type = $(typeSelector);
        const $duration = $(durationSelector);
        const key = (($type.val() || '') + '').toLowerCase();
        if (!$duration.val() && durationDefaults[key]) {
            $duration.val(durationDefaults[key]);
        }
    }
    $('#program_type').on('change', function() {
        applyDurationDefault('#program_type', '#program_duration');
    });
    $('#edit_program_type').on('change', function() {
        applyDurationDefault('#edit_program_type', '#edit_program_duration');
    });

    if ($('#programsTable').length && $.fn.DataTable) {
        const programsTable = $('#programsTable').DataTable({
            pageLength: 15,
            responsive: true,
            processing: true,
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>Brtip',
            buttons: [
                { extend: 'excelHtml5', title: 'Programs', exportOptions: { columns: [0,1,2,3,4,5] } },
                { extend: 'print', title: 'Programs', exportOptions: { columns: [0,1,2,3,4,5] } }
            ],
            columnDefs: [
                { targets: [6], orderable: false, searchable: false, className: 'text-center' },
                { targets: [2, 4, 5], className: 'text-center' },
                { targets: [0, 6], className: 'dt-nowrap' }
            ],
            order: [[1, 'asc']],
            language: {
                search: '',
                searchPlaceholder: 'Search programs...',
                lengthMenu: 'Show _MENU_ entries',
                processing: '<div class="text-center"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Loading programs...</p></div>',
                zeroRecords: '<div class="text-center py-5"><i class="fas fa-graduation-cap fa-3x text-muted mb-3"></i><p class="text-muted">No programs match your search criteria</p></div>',
                emptyTable: 'No programs available',
                info: 'Showing _START_ to _END_ of _TOTAL_ programs',
                infoEmpty: 'Showing 0 to 0 of 0 programs',
                infoFiltered: '(filtered from _MAX_ total)',
                paginate: {
                    first: '<i class="fas fa-angle-double-left"></i>',
                    last: '<i class="fas fa-angle-double-right"></i>',
                    next: '<i class="fas fa-angle-right"></i>',
                    previous: '<i class="fas fa-angle-left"></i>'
                }
            }
        });

        $('#exportPrograms').on('click', function() { programsTable.button('.buttons-excel').trigger(); });
        $('#printPrograms').on('click', function() { programsTable.button('.buttons-print').trigger(); });

        $('[data-programs-filter]').on('click', function(e) {
            e.preventDefault();
            const val = $(this).data('programs-filter');
            if (val === 'active') {
                programsTable.column(5).search('Active').draw();
            } else if (val === 'inactive') {
                programsTable.column(5).search('Inactive').draw();
            } else {
                programsTable.column(5).search('').draw();
            }
        });
    }
});
</script>


<?php
// Close the database connection.
if (isset($db) && $db instanceof mysqli) {
    $db->close();
}
?>
