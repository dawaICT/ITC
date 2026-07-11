<?php
/**
 * Manage Admitted Students
 * Admin module page following unified include pattern:
 *   header.php (→ admin.php → connect.php → nav.php → nav_unified.php) → page content → footer.php
 */

// ===== STEP 1: SESSION & AUTH (via header.php chain) =====
// header.php → admin.php handles session, auth, DB, and nav output
// POST handler MUST run after admin.php sets up $db, but we need CSRF token first
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../includes/helpers/academic_structure_helpers.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function normalize_admitted_student_mode($mode) {
    $mode = trim((string)$mode);
    if ($mode === '') {
        return '';
    }

    $key = strtolower(preg_replace('/[\s_-]+/', ' ', $mode));
    $key = str_replace([' (', '( ', ' )'], ['(', '(', ')'], $key);
    $mode_map = [
        'full time' => 'Full-Time',
        'fulltime' => 'Full-Time',
        'full-time' => 'Full-Time',
        'part time(evening)' => 'Part-Time(Evening)',
        'part time evening' => 'Part-Time(Evening)',
        'part-time(evening)' => 'Part-Time(Evening)',
        'part-time evening' => 'Part-Time(Evening)',
        'distance' => 'Distance',
        'short course' => 'Short Course',
    ];

    return $mode_map[$key] ?? $mode;
}

function is_valid_admitted_student_intake($intake) {
    $intake = trim((string)$intake);
    if ($intake === '') {
        return true;
    }

    return (bool)preg_match('/^(January|March|July|September)(\s+[0-9]{4})?$/', $intake);
}

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// ===== STEP 2: AJAX HANDLER (before any output) =====
// Must run before header.php outputs HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    // Need DB connection for AJAX
    require_once __DIR__ . '/includes/admin.php';

    // Explicit auth guard — admin.php (required above) already redirects truly
    // unauthenticated users, so reaching here means the admin session is valid.
    // Check the SAME flag admin.php establishes ($_SESSION['user_id'] / staff_id);
    // the old check looked at $_SESSION['admin_logged_in'], which nothing ever
    // sets, so every AJAX action (e.g. Edit student) wrongly returned 401
    // "Session expired" even when logged in.
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id'])) {
        if (ob_get_length()) { ob_clean(); }
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in again.']);
        exit;
    }
    
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh the page.']);
        exit;
    }
    
    if (!isset($db) || !$db instanceof mysqli) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database unavailable']);
        exit;
    }
    
    $response = ['success' => false, 'message' => 'Unknown action'];
    
    try {
        switch ($_POST['ajax_action']) {
            case 'get_student':
                $sid = trim($_POST['sid'] ?? '');
                if (empty($sid)) throw new Exception('Student ID is required');
                
                $stmt = $db->prepare("SELECT 
                    s.SID, s.Fname, s.Lname, s.sex, s.email, s.mobile, s.nrc_pass, s.country, s.dob,
                    sp.program_code, p.program_name, sp.intake, sp.mode, sp.startYear, sp.endYear,
                    sp.term_start_date as admission_date
                FROM students s
                INNER JOIN student_program sp ON TRIM(UPPER(s.SID)) = TRIM(UPPER(sp.Sid))
                INNER JOIN programs p ON TRIM(UPPER(sp.program_code)) = TRIM(UPPER(p.program_code))
                WHERE TRIM(UPPER(s.SID)) = TRIM(UPPER(?))
                  AND COALESCE(s.status, 'active') <> 'inactive'
                  AND COALESCE(sp.status, 'active') <> 'inactive'
                ORDER BY sp.id DESC
                LIMIT 1");
                if (!$stmt) throw new Exception('Database error');
                
                $stmt->bind_param("s", $sid);
                $stmt->execute();
                $student = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if (!$student) throw new Exception('Student not found');
                
                // Also get list of all programs for the edit dropdown
                $programs = [];
                $prog_result = $db->query("SELECT program_code, program_name FROM programs WHERE COALESCE(is_active, 1) = 1 ORDER BY program_name");
                if ($prog_result) {
                    while ($row = $prog_result->fetch_assoc()) {
                        $programs[] = $row;
                    }
                    $prog_result->free();
                }
                
                $response = ['success' => true, 'data' => $student, 'programs' => $programs];
                break;
                
            case 'update_student':
                // Validate inputs
                $sid = trim($_POST['sid'] ?? '');
                $fname = trim($_POST['Fname'] ?? '');
                $lname = trim($_POST['Lname'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $mobile = trim($_POST['mobile'] ?? '');
                $program_code = trim($_POST['program_code'] ?? '');
                $intake = trim($_POST['intake'] ?? '');
                $mode = normalize_admitted_student_mode($_POST['mode'] ?? '');
                $startYear = trim($_POST['startYear'] ?? '');
                $endYear = trim($_POST['endYear'] ?? '');
                
                if (empty($sid) || empty($fname) || empty($lname)) {
                    throw new Exception('Student ID, First Name, and Last Name are required');
                }
                if ($program_code === '') {
                    throw new Exception('Program is required');
                }
                
                // Verify student exists
                $check = $db->prepare("SELECT SID FROM students WHERE TRIM(UPPER(SID)) = TRIM(UPPER(?))");
                $check->bind_param("s", $sid);
                $check->execute();
                if ($check->get_result()->num_rows === 0) {
                    $check->close();
                    throw new Exception('Student not found');
                }
                $check->close();
                
                // Validate email format if provided
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email format');
                }

                $programCheck = $db->prepare("SELECT program_code FROM programs WHERE TRIM(UPPER(program_code)) = TRIM(UPPER(?)) AND COALESCE(is_active, 1) = 1 LIMIT 1");
                if (!$programCheck) throw new Exception('Failed to prepare program validation');
                $programCheck->bind_param("s", $program_code);
                $programCheck->execute();
                if ($programCheck->get_result()->num_rows === 0) {
                    $programCheck->close();
                    throw new Exception('Selected program is not available');
                }
                $programCheck->close();
                
                // Validate intake. Existing records may store "Month YYYY"; keep that data intact.
                if (!is_valid_admitted_student_intake($intake)) {
                    throw new Exception('Invalid intake value');
                }
                
                // Validate mode
                $valid_modes = ['Full-Time', 'Part-Time(Evening)', 'Distance'];
                if (!empty($mode) && !in_array($mode, $valid_modes, true)) {
                    throw new Exception('Invalid study mode');
                }

                $currentYear = (int)date('Y');
                $startYear = $startYear !== '' ? (int)$startYear : null;
                $endYear = $endYear !== '' ? (int)$endYear : null;
                if ($startYear !== null && ($startYear < 1980 || $startYear > $currentYear + 10)) {
                    throw new Exception('Start year is outside the valid range');
                }
                if ($endYear !== null && ($endYear < 1980 || $endYear > $currentYear + 15)) {
                    throw new Exception('End year is outside the valid range');
                }
                if ($startYear !== null && $endYear !== null && $endYear < $startYear) {
                    throw new Exception('End year cannot be earlier than start year');
                }

                $periodSource = $_POST;
                $periodSource['intake'] = $intake;
                $periodPayload = wuc_student_program_period_payload($db, $program_code, wuc_student_program_period_input($db, $program_code, $periodSource, 1));
                if (!$periodPayload['ok']) {
                    throw new Exception($periodPayload['reason']);
                }
                $periodFields = $periodPayload['fields'];
                
                $db->begin_transaction();
                
                try {
                    // Update students table — contact details only. Name fields
                    // are protected identity data and are corrected only through
                    // the audited registrar flow (admissions/editStudent.php).
                    $update_student = $db->prepare("UPDATE students SET email = ?, mobile = ? WHERE TRIM(UPPER(SID)) = TRIM(UPPER(?))");
                    if (!$update_student) throw new Exception('Failed to prepare student update');
                    $update_student->bind_param("sss", $email, $mobile, $sid);
                    $update_student->execute();
                    $update_student->close();
                    
                    // Update student_program table
                    $update_program = $db->prepare("UPDATE student_program
                        SET program_code = ?,
                            intake = ?,
                            mode = ?,
                            term = ?,
                            semester = ?,
                            current_term_number = ?,
                            current_semester_number = ?,
                            current_level_number = ?,
                            startYear = ?,
                            endYear = ?
                      WHERE TRIM(UPPER(Sid)) = TRIM(UPPER(?)) AND COALESCE(status, 'active') <> 'inactive'");
                    if (!$update_program) throw new Exception('Failed to prepare program update');
                    $update_program->bind_param("sssssssssss", $program_code, $intake, $mode, $periodFields['term'], $periodFields['semester'], $periodFields['current_term_number'], $periodFields['current_semester_number'], $periodFields['current_level_number'], $startYear, $endYear, $sid);
                    $update_program->execute();
                    if ($update_program->affected_rows < 0) {
                        throw new Exception('Failed to update student program');
                    }
                    $update_program->close();
                    
                    $db->commit();
                    $response = ['success' => true, 'message' => 'Student information updated successfully'];
                } catch (Exception $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
                
            case 'delete_student':
                $sid = trim($_POST['sid'] ?? '');
                if (empty($sid)) throw new Exception('Student ID is required');
                
                // Verify student exists
                $check = $db->prepare("SELECT SID FROM students WHERE TRIM(UPPER(SID)) = TRIM(UPPER(?))");
                $check->bind_param("s", $sid);
                $check->execute();
                if ($check->get_result()->num_rows === 0) {
                    $check->close();
                    throw new Exception('Student not found');
                }
                $check->close();
                
                $db->begin_transaction();
                
                try {
                    // Preserve related records; remove the student from this admitted list by deactivating.
                    $del_prog = $db->prepare("UPDATE student_program SET status = 'inactive' WHERE TRIM(UPPER(Sid)) = TRIM(UPPER(?))");
                    if (!$del_prog) throw new Exception('Failed to prepare program deactivation');
                    $del_prog->bind_param("s", $sid);
                    $del_prog->execute();
                    $del_prog->close();
                    
                    $del_student = $db->prepare("UPDATE students SET status = 'inactive' WHERE TRIM(UPPER(SID)) = TRIM(UPPER(?))");
                    if (!$del_student) throw new Exception('Failed to prepare student deactivation');
                    $del_student->bind_param("s", $sid);
                    $del_student->execute();
                    $del_student->close();
                    
                    $db->commit();
                    $response = ['success' => true, 'message' => 'Student removed from the admitted list successfully'];
                } catch (Exception $e) {
                    $db->rollback();
                    throw $e;
                }
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (mysqli_sql_exception $e) {
        // Technical database errors: log the detail, never expose it to the client.
        error_log("Admin Manage Students AJAX DB Error ({$_POST['ajax_action']}): " . $e->getMessage());
        $response = [
            'success' => false,
            'message' => 'A database error occurred while processing your request. Please try again or contact support.'
        ];
    } catch (Exception $e) {
        // Intentional validation messages (e.g. "Student not found") are safe to show.
        error_log("Admin Manage Students AJAX Error ({$_POST['ajax_action']}): " . $e->getMessage());
        $response = [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }

    echo json_encode($response);
    exit;
}

// ===== STEP 3: PAGE RENDER =====
$page_title = 'Manage Admitted Students';
require_once "includes/header.php";
?>
<style>
    /* Page-specific — shared components in assets/css/dashboard.css */
    .page-title { letter-spacing: -0.02em; font-weight: 800 !important; }
    .modal-header.bg-primary { background: linear-gradient(135deg, #6f42c1 0%, #4e2a84 100%) !important; border: none !important; }
    body.has-unified-sidebar .sidebar,
    body.has-unified-sidebar .sidebar-toggle,
    body.has-unified-sidebar .sidebar-backdrop,
    body.has-unified-sidebar .navbar,
    body.has-unified-sidebar .topbar,
    body.has-unified-sidebar .floating-action,
    body.has-unified-sidebar .floating-buttons,
    body.has-unified-sidebar .quick-actions { z-index: 1045 !important; }
</style>
<?php

// Database already connected via admin.php → connect.php
if (!isset($db) || !$db instanceof mysqli) {
    echo '<div class="alert alert-danger">Database connection failed. Please check your configuration.</div>';
    require 'includes/footer.php';
    return;
}

// Pre-fetch programs list (used for all modals, avoids N+1 queries)
$programs_list = [];
$prog_result = $db->query("SELECT program_code, program_name FROM programs WHERE COALESCE(is_active, 1) = 1 ORDER BY program_name");
if ($prog_result) {
    while ($row = $prog_result->fetch_assoc()) {
        $programs_list[] = $row;
    }
    $prog_result->free();
}

// Get admitted students with pagination-ready query
$query = "SELECT 
    s.SID, s.Fname, s.Lname, s.sex, s.email, s.mobile,
    sp.program_code, p.program_name, sp.intake, sp.mode, sp.startYear, sp.endYear,
    sp.term_start_date as admission_date
FROM students s
INNER JOIN student_program sp ON TRIM(UPPER(s.SID)) = TRIM(UPPER(sp.Sid))
INNER JOIN programs p ON TRIM(UPPER(sp.program_code)) = TRIM(UPPER(p.program_code))
WHERE COALESCE(s.status, 'active') <> 'inactive'
  AND COALESCE(sp.status, 'active') <> 'inactive'
ORDER BY sp.term_start_date DESC";

$result = $db->query($query);
$students = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Stats
$total_students = count($students);
$program_counts = [];
$mode_counts = [];
foreach ($students as $s) {
    $prog = $s['program_name'] ?? 'Unknown';
    $program_counts[$prog] = ($program_counts[$prog] ?? 0) + 1;
    // Normalize legacy mode values (e.g. "Full Time" -> "Full-Time") so the stat
    // cards key off the same canonical labels used everywhere else on the page.
    $mode = normalize_admitted_student_mode($s['mode'] ?? '');
    if ($mode === '') { $mode = 'Unknown'; }
    $mode_counts[$mode] = ($mode_counts[$mode] ?? 0) + 1;
}
$total_programs = count($program_counts);
?>

<!-- Main Content -->
<div class="container-fluid px-4 portal-dashboard">
    
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-user-graduate me-2 text-primary"></i>Manage Admitted Students</h5>
                <p class="page-subtitle mb-0">Review enrollment data, edit student details, and manage student lifecycle</p>
            </div>
            <div class="header-actions d-flex gap-2">
                <button class="btn btn-outline-primary" onclick="refreshData()">
                    <i class="fas fa-sync-alt me-1"></i>Refresh
                </button>
                <a href="students_by_admin.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>All Students
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-user-graduate"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_students) ?></h3>
                        <p class="text-muted mb-0">Total Admitted</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3 text-white"><i class="fas fa-graduation-cap"></i></div>
                    <div>
                        <h3 class="mb-0"><?= $total_programs ?></h3>
                        <p class="text-muted mb-0">Active Programs</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-info me-3 text-white"><i class="fas fa-clock"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($mode_counts['Full-Time'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Full-Time Students</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-warning me-3 text-white"><i class="fas fa-moon"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($mode_counts['Part-Time(Evening)'] ?? 0) ?></h3>
                        <p class="text-muted mb-0">Part-Time Students</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="card border-0 shadow-sm data-table-card mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <h5 class="mb-0 text-primary">
                    <i class="fas fa-table me-2"></i>Admitted Students
                </h5>
            </div>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-download me-1"></i>Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                    <li><a class="dropdown-item" href="#" onclick="exportTable('csv')"><i class="fas fa-file-csv me-2"></i>CSV</a></li>
                    <li><a class="dropdown-item" href="#" onclick="exportTable('excel')"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="#" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</a></li>
                </ul>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($students)): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="admittedTable" width="100%">
                    <thead class="table-light">
                        <tr class="text-uppercase small-header">
                            <th width="4%" class="text-center">#</th>
                            <th width="8%">Student ID</th>
                            <th width="18%">Student</th>
                            <th width="8%">Gender</th>
                            <th width="20%">Program</th>
                            <th width="8%">Intake</th>
                            <th width="8%">Mode</th>
                            <th width="8%">Start</th>
                            <th width="8%">End</th>
                            <th width="10%" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $index => $student):
                            $initials = strtoupper(substr($student['Fname'], 0, 1) . substr($student['Lname'], 0, 1));
                            $mode_display = normalize_admitted_student_mode($student['mode'] ?? '');
                            $mode_class = match($mode_display) {
                                'Full-Time' => 'bg-success-subtle text-success',
                                'Part-Time(Evening)' => 'bg-warning-subtle text-warning',
                                'Distance' => 'bg-info-subtle text-info',
                                'Short Course' => 'bg-secondary-subtle text-secondary',
                                default => 'bg-light text-dark'
                            };
                        ?>
                        <tr data-sid="<?= htmlspecialchars($student['SID']) ?>">
                            <td class="text-center fw-bold text-muted"><?= $index + 1 ?></td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace">
                                    <?= htmlspecialchars($student['SID']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle me-3"><?= $initials ?></div>
                                    <div>
                                        <div class="fw-bold"><?= htmlspecialchars($student['Fname'] . ' ' . $student['Lname']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($student['email'] ?? '') ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($student['sex'] === 'M'): ?>
                                    <span class="badge bg-blue-subtle text-primary border border-primary-subtle rounded-pill">
                                        <i class="fas fa-mars me-1"></i>Male
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-pink-subtle text-danger border border-danger-subtle rounded-pill">
                                        <i class="fas fa-venus me-1"></i>Female
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="text-truncate fw-semibold text-primary" style="max-width: 220px;" title="<?= htmlspecialchars($student['program_name']) ?>">
                                    <?= htmlspecialchars($student['program_name']) ?>
                                </div>
                                <small class="text-muted font-monospace"><?= htmlspecialchars($student['program_code']) ?></small>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($student['intake'] ?? '') ?></span></td>
                            <td><span class="badge <?= $mode_class ?> border rounded-pill"><?= htmlspecialchars($mode_display !== '' ? $mode_display : ($student['mode'] ?? '')) ?></span></td>
                            <td class="small text-muted"><?= htmlspecialchars($student['startYear'] ?? 'N/A') ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($student['endYear'] ?? 'N/A') ?></td>
                            <td class="text-end pe-3">
                                <div class="btn-group btn-group-sm action-btns" role="group" aria-label="Student actions">
                                    <button type="button" class="btn btn-outline-primary edit-btn"
                                        data-sid="<?= htmlspecialchars($student['SID']) ?>"
                                        title="Edit student" aria-label="Edit student">
                                        <i class="fas fa-pen"></i><span class="d-none d-lg-inline ms-1">Edit</span>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger delete-btn"
                                        data-sid="<?= htmlspecialchars($student['SID']) ?>"
                                        data-name="<?= htmlspecialchars($student['Fname'] . ' ' . $student['Lname']) ?>"
                                        title="Delete student" aria-label="Delete student">
                                        <i class="fas fa-trash-alt"></i><span class="d-none d-lg-inline ms-1">Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-5">
                <div class="mb-3">
                    <span class="fa-stack fa-2x text-muted">
                        <i class="fas fa-circle fa-stack-2x opacity-25"></i>
                        <i class="fas fa-user-graduate fa-stack-1x"></i>
                    </span>
                </div>
                <h5>No Admitted Students Found</h5>
                <p class="text-muted">Students will appear here after being admitted through the admissions process.</p>
                <a href="processedApp.php" class="btn btn-primary rounded-pill px-4">
                    <i class="fas fa-tasks me-2"></i>View Processed Applications
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Page-specific override — shared components in assets/css/dashboard.css */
.text-xs { font-size: 0.7rem; font-weight: 700; }
</style>

<!-- Page JavaScript (jQuery, Bootstrap, DataTables, SweetAlert2 loaded by nav_unified.php) -->
<script>
let dataTable = null;
const csrfToken = '<?= $csrf_token ?>';

// Programs list (pre-fetched, avoids per-row queries)
const programsList = <?= json_encode($programs_list) ?>;

$(document).ready(function() {
    initializeDataTable();
    initializeEventListeners();
    
    // Auto-hide alerts
    setTimeout(() => {
        $('.alert:not(.alert-permanent)').fadeOut('slow');
    }, 5000);
});

function initializeDataTable() {
    if (!$.fn.DataTable) return;
    dataTable = $('#admittedTable').DataTable({
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[1, 'asc']],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search students...",
            lengthMenu: "Show _MENU_",
            info: "Showing _START_ to _END_ of _TOTAL_ students",
            emptyTable: "No admitted students found",
            zeroRecords: "No matching students found"
        },
        columnDefs: [
            { orderable: false, targets: -1 },
            { responsivePriority: 1, targets: [1, 2, -1] }
        ],
        dom: '<"row align-items-center mb-3"<"col-md-6"l><"col-md-6"f>>' +
             '<"table-responsive"t>' +
             '<"row align-items-center mt-3"<"col-md-5"i><"col-md-7"p>>'
    });
}

function initializeEventListeners() {
    // Edit button
    $(document).on('click', '.edit-btn', function(e) {
        e.preventDefault();
        const sid = $(this).data('sid');
        if (sid) loadEditForm(sid);
    });
    
    // Delete button
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        const sid = $(this).data('sid');
        const name = $(this).data('name');
        if (sid) confirmDelete(sid, name);
    });
    
    // Save button in modal (delegated)
    $(document).on('click', '#saveStudentBtn', function() {
        saveStudent();
    });
}

// ===== Unified action feedback (consistent with the rest of the portal) =====
const BRAND_PURPLE = '#6f42c1';

// Normalise any AJAX failure into a user-friendly message.
function ajaxErrorMessage(xhr, fallback) {
    if (xhr.status === 403) return 'Security token expired. Please refresh the page.';
    if (xhr.status === 401) return 'Session expired. Please log in again.';
    if (xhr.responseText) {
        try {
            const err = JSON.parse(xhr.responseText);
            if (err && err.message) return err.message;
        } catch (e) { /* non-JSON response */ }
    }
    return fallback || 'Something went wrong. Please try again.';
}

function notifySuccess(title, message) {
    return Swal.fire({
        icon: 'success',
        title: title || 'Success',
        html: `<small class="text-muted">${escapeHtml(message || '')}</small>`,
        confirmButtonText: 'Done',
        confirmButtonColor: BRAND_PURPLE
    });
}

// Show an error dialog. On a 403 it offers to refresh the page (token expiry).
function notifyError(message, opts) {
    opts = opts || {};
    return Swal.fire({
        icon: 'error',
        title: opts.title || 'Action Failed',
        html: `<small class="text-muted">${escapeHtml(message || 'Please try again.')}</small>`,
        confirmButtonText: opts.confirmButtonText || 'OK',
        confirmButtonColor: BRAND_PURPLE
    });
}

// Standard handler for a jqXHR failure: pick a message, show it, refresh on 403.
function handleAjaxError(xhr, fallback) {
    const msg = ajaxErrorMessage(xhr, fallback);
    return notifyError(msg, {
        title: 'Error',
        confirmButtonText: xhr.status === 403 ? 'Refresh Page' : 'OK'
    }).then((result) => {
        if (result.isConfirmed && xhr.status === 403) location.reload();
    });
}

function loadEditForm(sid) {
    const modalEl = document.getElementById('editStudentModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    $('#editModalContent').html(`
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">Loading student data...</p>
        </div>
    `);
    modal.show();
    
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: { ajax_action: 'get_student', sid: sid, csrf_token: csrfToken },
        dataType: 'json',
        timeout: 10000,
        success: function(response) {
            if (response.success && response.data) {
                renderEditForm(response.data, response.programs || programsList);
            } else {
                $('#editModalContent').html(`
                    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>${escapeHtml(response.message || 'Failed to load student data')}</div>
                `);
            }
        },
        error: function(xhr) {
            const msg = ajaxErrorMessage(xhr, 'Error loading student data');
            $('#editModalContent').html(`<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-triangle me-2"></i>${escapeHtml(msg)}</div>`);
        }
    });
}

function normalizeMode(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';

    const key = raw
        .toLowerCase()
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .replace(/\s*\(\s*/g, '(')
        .replace(/\s*\)\s*/g, ')');

    const modeMap = {
        'full time': 'Full-Time',
        'fulltime': 'Full-Time',
        'part time(evening)': 'Part-Time(Evening)',
        'part time evening': 'Part-Time(Evening)',
        'distance': 'Distance'
    };

    return modeMap[key] || raw;
}

function renderEditForm(data, programs) {
    // Build program options
    let programOptions = '';
    (programs || []).forEach(p => {
        const selected = p.program_code === data.program_code ? 'selected' : '';
        programOptions += `<option value="${escapeHtml(p.program_code)}" ${selected}>${escapeHtml(p.program_name)}</option>`;
    });
    if (data.program_code && !programOptions.includes(`value="${escapeHtml(data.program_code)}"`)) {
        programOptions = `<option value="${escapeHtml(data.program_code)}" selected>${escapeHtml(data.program_name || data.program_code)} (current)</option>` + programOptions;
    }
    
    // Build intake options
    const intakes = ['January', 'March', 'July', 'September'];
    const currentIntake = String(data.intake || '').trim();
    if (currentIntake && !intakes.includes(currentIntake)) {
        intakes.unshift(currentIntake);
    }
    let intakeOptions = intakes.map(i => `<option value="${escapeHtml(i)}" ${i === currentIntake ? 'selected' : ''}>${escapeHtml(i)}</option>`).join('');
    
    // Build mode options
    const modes = ['Full-Time', 'Part-Time(Evening)', 'Distance'];
    const currentMode = normalizeMode(data.mode || '');
    if (currentMode && currentMode !== 'Short Course' && !modes.includes(currentMode)) {
        modes.unshift(currentMode);
    }
    let modeOptions = modes.map(m => `<option value="${escapeHtml(m)}" ${m === currentMode ? 'selected' : ''}>${escapeHtml(m)}</option>`).join('');
    
    const html = `
        <form id="editStudentForm">
            <input type="hidden" name="sid" value="${escapeHtml(data.SID)}">
            
            <div class="mb-3 p-3 bg-light rounded">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="avatar-circle" style="width:50px;height:50px;font-size:18px;">
                            ${escapeHtml((data.Fname || '')[0] + (data.Lname || '')[0])}
                        </div>
                    </div>
                    <div class="col">
                        <h6 class="mb-0">${escapeHtml(data.Fname + ' ' + data.Lname)}</h6>
                        <small class="text-muted font-monospace">SID: ${escapeHtml(data.SID)}</small>
                    </div>
                </div>
            </div>
            
            <h6 class="text-uppercase text-muted small fw-bold mb-3 border-bottom pb-2">
                <i class="fas fa-user me-2"></i>Personal Information
            </h6>
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="editFname" name="Fname" value="${escapeHtml(data.Fname || '')}" readonly required>
                        <label for="editFname">First Name</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="editLname" name="Lname" value="${escapeHtml(data.Lname || '')}" readonly required>
                        <label for="editLname">Last Name</label>
                    </div>
                </div>
                <div class="col-12 mt-2">
                    <small class="text-muted">Name corrections are handled through the audited registrar identity flow.</small>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="form-floating">
                        <input type="email" class="form-control" id="editEmail" name="email" value="${escapeHtml(data.email || '')}">
                        <label for="editEmail">Email</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-floating">
                        <input type="tel" class="form-control" id="editMobile" name="mobile" value="${escapeHtml(data.mobile || '')}">
                        <label for="editMobile">Mobile Number</label>
                    </div>
                </div>
            </div>
            
            <h6 class="text-uppercase text-muted small fw-bold mb-3 border-bottom pb-2 mt-4">
                <i class="fas fa-graduation-cap me-2"></i>Academic Information
            </h6>
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="form-floating">
                        <select class="form-select" id="editProgram" name="program_code" required>
                            ${programOptions}
                        </select>
                        <label for="editProgram">Program</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-floating">
                        <select class="form-select" id="editIntake" name="intake" required>
                            ${intakeOptions}
                        </select>
                        <label for="editIntake">Intake</label>
                    </div>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="form-floating">
                        <select class="form-select" id="editMode" name="mode" required>
                            ${modeOptions}
                        </select>
                        <label for="editMode">Mode of Study</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-floating">
                        <input type="number" class="form-control" id="editStartYear" name="startYear" value="${escapeHtml(data.startYear || '')}" min="1980" max="2040" step="1">
                        <label for="editStartYear">Start Year</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-floating">
                        <input type="number" class="form-control" id="editEndYear" name="endYear" value="${escapeHtml(data.endYear || '')}" min="1980" max="2045" step="1">
                        <label for="editEndYear">End Year</label>
                    </div>
                </div>
            </div>
            
            <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveStudentBtn">
                    <i class="fas fa-save me-2"></i>Save Changes
                </button>
            </div>
        </form>
    `;
    
    $('#editModalContent').html(html);
}

function saveStudent() {
    const form = document.getElementById('editStudentForm');
    if (!form) return;
    
    // Basic HTML5 validation
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    formData.append('ajax_action', 'update_student');
    formData.append('csrf_token', csrfToken);
    
    const saveBtn = $('#saveStudentBtn');
    saveBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');
    
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        timeout: 15000,
        success: function(response) {
            if (response.success) {
                const modalEl = document.getElementById('editStudentModal');
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                notifySuccess('Updated!', response.message || 'Student information updated successfully')
                    .then(() => location.reload());
            } else {
                notifyError(response.message || 'Update failed', { title: 'Update Failed' });
                saveBtn.prop('disabled', false).html('<i class="fas fa-save me-2"></i>Save Changes');
            }
        },
        error: function(xhr) {
            handleAjaxError(xhr, 'Failed to save changes');
            saveBtn.prop('disabled', false).html('<i class="fas fa-save me-2"></i>Save Changes');
        }
    });
}

function confirmDelete(sid, name) {
    Swal.fire({
        title: 'Remove Student?',
        html: `Remove <strong>${escapeHtml(name)}</strong> (${escapeHtml(sid)}) from the admitted list?<br><small class="text-muted">Related academic records are preserved.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: '<i class="fas fa-user-slash me-2"></i>Remove',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: { ajax_action: 'delete_student', sid: sid, csrf_token: csrfToken },
                dataType: 'json',
                timeout: 15000,
                success: function(response) {
                    if (response.success) {
                        // Remove the row from the table (DataTable-aware).
                        const row = $('#admittedTable tbody tr').filter(function() {
                            return String($(this).data('sid')) === String(sid);
                        });
                        if (dataTable) { dataTable.row(row).remove().draw(); }
                        else { row.remove(); }
                        notifySuccess('Removed!', response.message || 'Student removed from the admitted list successfully');
                    } else {
                        notifyError(response.message || 'Delete failed', { title: 'Delete Failed' });
                    }
                },
                error: function(xhr) {
                    handleAjaxError(xhr, 'Failed to delete student');
                }
            });
        }
    });
}

function refreshData() {
    window.location.reload();
}

function exportTable(format) {
    if (!dataTable) { Swal.fire('Info', 'Table not initialized', 'info'); return; }
    
    // Use DataTables Buttons API if available
    try {
        if (format === 'csv') {
            dataTable.button('.buttons-csv').trigger();
            return;
        } else if (format === 'excel') {
            dataTable.button('.buttons-excel').trigger();
            return;
        }
    } catch(e) { /* fallback below */ }
    
    // Manual CSV fallback
    let csv = [];
    const headers = [];
    $('#admittedTable thead th').each(function() {
        if (!$(this).hasClass('text-end')) headers.push('"' + $(this).text().trim().replace(/"/g, '""') + '"');
    });
    csv.push(headers.join(','));
    
    $('#admittedTable tbody tr').each(function() {
        const row = [];
        $(this).find('td').each(function(index) {
            if (index < 9) {
                let text = $(this).text().trim().replace(/"/g, '""').replace(/\s+/g, ' ');
                row.push('"' + text + '"');
            }
        });
        if (row.length > 0) csv.push(row.join(','));
    });
    
    const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `admitted_students_${new Date().toISOString().slice(0,10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}
</script>

<?php
$admin_footer_before_body_close = <<<'HTML'
<!-- Edit Student Modal (single reusable modal, outside main content for Bootstrap stacking) -->
<div class="modal fade" id="editStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Student Information</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="editModalContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Loading student data...</p>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
require 'includes/footer.php';
?>
