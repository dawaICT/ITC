<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
require_once __DIR__ . '/includes/student_handlers.php';
require_once dirname(__DIR__) . '/includes/short_course_db.php';

// Session validation with timeout check
if (!checkSessionTimeout(30)) {
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: /wucportal/staff_login.php');
    exit;
}

// Generate CSRF token for GET/Initial requests
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Consolidated POST Handling (CSRF + AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Initial Setup & Validation
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input');
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $input = json_decode($raw, true) ?? [];
    } else {
        $input = $_POST;
    }
    
    // 2. CSRF Check
    if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'CSRF Token Mismatch/Session Expired',
            'debug_received' => $input['csrf_token'] ?? 'none'
        ]);
        exit;
    }
    
    // 3. Database Check
    if (!isset($db) || !($db instanceof mysqli) || $db->connect_error) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database unavailable']);
        exit;
    }
    
    // 4. Action Routing
    if (ob_get_level() > 0) ob_end_clean();
    $response = ['success' => false, 'message' => 'Unknown action'];
    $action = $input['action'] ?? '';
    
    try {
        switch ($action) {
            case 'get_students':
                $response = handleGetStudents($db, $input);
                break;
            case 'get_stats':
                $response = handleGetStats($db);
                break;
            case 'bulk_export':
                $response = handleBulkExport($db, $input);
                break;
            case 'get_student_details':
                $response = handleGetStudentDetails($db, $input);
                break;
            case 'delete_student':
                $response = handleDeleteStudent($db, $input);
                break;
            case 'admit_student':
                $response = handleAdmitStudent($db, array_merge($input, ['files' => $_FILES ?? []]));
                break;
            default:
                $response['message'] = 'Invalid action specified: ' . htmlspecialchars($action);
        }
    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage());
        http_response_code(500);
        $response = ['success' => false, 'message' => 'Internal Server Error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// Authorization check
if (!isAdminAuthenticated()) {
    setFlashMessage('error', 'You do not have permission to access this page.');
    header('Location: /wucportal/staff_login.php');
    exit;
}

// Debug mode (admin only)
$isDebug = isset($_GET['debug']) && ($_SESSION['user_role'] === 'admin' || $_SESSION['role'] === 'admin');
if ($isDebug) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
}

// Check required tables for page load
$requiredTables = ['students', 'student_program', 'programs'];
foreach ($requiredTables as $table) {
    if ($db->query("SHOW TABLES LIKE '{$table}'")->num_rows === 0) {
        error_log("Missing table: {$table}");
        die("<div class='alert alert-danger'>Configuration error: Missing table '{$table}'</div>");
    }
}

// ===== FIX: Define $programs for admission modal =====
// The intake period is driven by the AUTHORITATIVE programs.period_mode
// ('semester'|'term'), NOT study_mode (which is Full/Part-time attendance and is
// 'Full Time' for every program — using it made every program look semester-based
// and hid term intakes). Transport rows are only surfaced as rolling short
// courses when their duration is six months or less. Longer transport entries
// are academic programmes and must live in the programs catalogue.
$programs = [];
$hasPeriodMode = false;
if ($pmChk = $db->query("SHOW COLUMNS FROM programs LIKE 'period_mode'")) {
    $hasPeriodMode = $pmChk->num_rows > 0;
    $pmChk->free();
}
$periodModeExpr = $hasPeriodMode
    ? "LOWER(COALESCE(NULLIF(period_mode, ''), CASE WHEN LOWER(COALESCE(study_mode, '')) = 'term' THEN 'term' ELSE 'semester' END))"
    : "CASE WHEN LOWER(COALESCE(study_mode, '')) = 'term' THEN 'term' ELSE 'semester' END";
$programSql = "
    SELECT program_code, program_name, period_mode, CAST((period_mode = 'term') AS UNSIGNED) AS term_based
    FROM (
        SELECT program_code, program_name, {$periodModeExpr} AS period_mode
        FROM programs
        WHERE COALESCE(is_active, 1) = 1
";
if ($db->query("SHOW TABLES LIKE 'transport_programs'")->num_rows > 0) {
    $programSql .= "
        UNION ALL
        SELECT tp.program_code, CONCAT(tp.program_name, ' (Transport)') AS program_name, 'rolling' AS period_mode
        FROM transport_programs tp
        WHERE tp.status = 'active'
          AND COALESCE(tp.duration_days, 0) BETWEEN 1 AND " . SC_MAX_SHORT_COURSE_DAYS . "
          AND NOT EXISTS (
              SELECT 1 FROM programs p
              WHERE p.program_code COLLATE utf8mb4_unicode_ci = tp.program_code COLLATE utf8mb4_unicode_ci
          )
    ";
}
$programSql .= "
    ) available_programs
    ORDER BY program_name
";
$progQuery = $db->query($programSql);
if ($progQuery) {
    while ($row = $progQuery->fetch_assoc()) {
        $programs[] = $row;
    }
}

// ===== FIX: Define $modal_csrf =====
$modal_csrf = $_SESSION['csrf_token'];

// ===== FIX: Define $filterOptions for filters =====
$filterOptions = [
    'programs' => [],
    'intakes' => [],
    'modes' => ['Full-time', 'Part-time', 'Distance']
];

$filterProgramSql = "
    SELECT program_name
    FROM (
        SELECT DISTINCT program_name FROM programs WHERE COALESCE(is_active, 1) = 1
";
if ($db->query("SHOW TABLES LIKE 'transport_programs'")->num_rows > 0) {
    $filterProgramSql .= "
        UNION
        SELECT DISTINCT CONCAT(program_name, ' (Transport)') AS program_name
        FROM transport_programs
        WHERE status = 'active'
          AND COALESCE(duration_days, 0) BETWEEN 1 AND " . SC_MAX_SHORT_COURSE_DAYS . "
    ";
}
$filterProgramSql .= "
    ) filter_programs
    ORDER BY program_name
";
$progResult = $db->query($filterProgramSql);
if ($progResult) {
    while ($row = $progResult->fetch_assoc()) {
        $filterOptions['programs'][] = $row['program_name'];
    }
}

$intakeResult = $db->query("SELECT DISTINCT intake FROM student_program WHERE intake IS NOT NULL ORDER BY intake DESC");
if ($intakeResult) {
    while ($row = $intakeResult->fetch_assoc()) {
        $filterOptions['intakes'][] = $row['intake'];
    }
}

$page_title = 'Student Records';
require "includes/nav.php";
?>

<!-- Vue.js Application -->
<div 
    id="app"
    v-cloak
    class="container-fluid px-4 py-4 portal-dashboard"
>
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb bg-white p-3 rounded shadow-sm">
            <li class="breadcrumb-item"><a href="/wucportal/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Students</li>
            <li class="breadcrumb-item active" aria-current="page">Student Records</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="h3 mb-1 text-gray-800">Student Records</h1>
                <p class="text-muted mb-0">Manage and view all registered students</p>
            </div>
            <div class="col-auto">
                <div class="d-flex gap-2">
                    <button 
                        class="btn btn-outline-info" 
                        @click="refreshData()" 
                        :disabled="loading" 
                        title="Refresh Data"
                    >
                        <i class="fas fa-sync-alt" :class="{ 'fa-spin': loading }"></i>
                    </button>
                    <button 
                        class="btn btn-primary" 
                        data-bs-toggle="modal" 
                        data-bs-target="#admitModal"
                    >
                        <i class="fas fa-user-plus me-2"></i>Admit Student
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <div 
        v-show="successMessage" 
        class="alert alert-success alert-dismissible fade show shadow-sm border-start border-success border-4" 
        role="alert"
    >
        <div class="d-flex align-items-center">
            <i class="fas fa-check-circle me-3 fs-4 text-success"></i>
            <div>
                <strong class="d-block">Success!</strong>
                <span v-text="successMessage"></span>
            </div>
        </div>
        <button type="button" class="btn-close" @click="successMessage = ''" aria-label="Close"></button>
    </div>

    <!-- Stats Row -->
    <div class="row g-4 mb-4">
        <!-- Total Students -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 border-left-primary shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col me-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1" v-text="stats.total.label"></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" v-text="formatNumber(stats.total.value)"></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Students -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 border-left-success shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col me-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1" v-text="stats.active.label"></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" v-text="formatNumber(stats.active.value)"></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-check fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transfer Students -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 border-left-info shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col me-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1" v-text="stats.transfer.label"></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" v-text="formatNumber(stats.transfer.value)"></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exchange-alt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Programs -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 border-left-warning shadow-sm h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col me-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1" v-text="stats.programs.label"></div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800" v-text="formatNumber(stats.programs.value)"></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-graduation-cap fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="card shadow-sm border-0 mb-4">
        <!-- Card Header -->
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Registered Students
                <span class="badge bg-secondary ms-2" v-text="formatNumber(pagination.total)"></span>
            </h6>
            
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <!-- Bulk Actions -->
                <div class="dropdown" v-show="selectedStudents.length > 0">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" id="bulkActions" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-tasks me-2"></i>Bulk Actions ({{ selectedStudents.length }})
                    </button>
                    <ul class="dropdown-menu shadow" aria-labelledby="bulkActions">
                        <li>
                            <a class="dropdown-item" href="#" @click.prevent="bulkExport('csv')">
                                <i class="fas fa-file-csv me-2 text-success"></i>Export to CSV
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="#" @click.prevent="bulkPrint()">
                                <i class="fas fa-print me-2 text-primary"></i>Print Selected
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Card Body -->
        <div class="card-body">
            <!-- Filter Section -->
            <div class="row g-3 mb-4">
                <!-- Search -->
                <div class="col-md-4">
                    <label class="form-label text-muted small fw-bold">Search Student</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input 
                            type="text" 
                            class="form-control border-start-0" 
                            v-model="filters.search"
                            @input="applyFilters()"
                            placeholder="Search by ID, name, NRC..."
                        >
                        <button 
                            class="btn btn-outline-secondary" 
                            type="button" 
                            v-show="filters.search"
                            @click="resetFilters()"
                        >
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <!-- Program Filter -->
                <div class="col-md-3">
                    <label class="form-label text-muted small fw-bold">Filter Program</label>
                    <select class="form-select" v-model="filters.program" @change="applyFilters()">
                        <option value="">All Programs</option>
                        <template v-for="prog in filterOptions.programs" :key="prog">
                            <option :value="prog" v-text="prog"></option>
                        </template>
                    </select>
                </div>

                <!-- Intake Filter -->
                <div class="col-md-2">
                    <label class="form-label text-muted small fw-bold">Filter Intake</label>
                    <select class="form-select" v-model="filters.intake" @change="applyFilters()">
                        <option value="">All Intakes</option>
                        <template v-for="intake in filterOptions.intakes" :key="intake">
                            <option :value="intake" v-text="intake"></option>
                        </template>
                    </select>
                </div>

                <!-- Mode Filter -->
                <div class="col-md-2">
                    <label class="form-label text-muted small fw-bold">Filter Study Mode</label>
                    <select class="form-select" v-model="filters.mode" @change="applyFilters()">
                        <option value="">All Modes</option>
                        <template v-for="mode in filterOptions.modes" :key="mode">
                            <option :value="mode" v-text="mode"></option>
                        </template>
                    </select>
                </div>

                <!-- Reset Button -->
                <div class="col-md-1 d-flex align-items-end">
                    <button class="btn btn-outline-danger w-100" @click="resetFilters()" title="Reset Filters">
                        <i class="fas fa-undo"></i>
                    </button>
                </div>
            </div>

            <!-- Loader -->
            <div v-show="loading" class="text-center py-5">
                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-3">Loading student records...</p>
            </div>

            <!-- Empty State -->
            <div v-show="!loading && students.length === 0" class="text-center py-5">
                <div class="empty-state mb-3">
                    <i class="fas fa-user-slash fs-1 text-muted"></i>
                </div>
                <h5>No Student Records Found</h5>
                <p class="text-muted">Try adjusting your filters or search criteria.</p>
                <button class="btn btn-primary btn-sm" @click="resetFilters()">
                    <i class="fas fa-undo me-2"></i>Clear Filters
                </button>
            </div>

            <!-- Table Section -->
            <div v-show="!loading && students.length > 0" class="table-responsive">
                <table class="table table-hover align-middle" id="studentsTable" width="100%">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    :checked="isPageSelected()"
                                    @change="toggleSelectPage()"
                                >
                            </th>
                            <th style="width: 50px;">#</th>
                            <th style="width: 60px;">Photo</th>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Gender</th>
                            <th>Program</th>
                            <th>Intake</th>
                            <th>Mode</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(student, index) in students" :key="student.sid">
                            <tr :class="{ 'table-selected': isSelected(student.sid) }">
                                <td>
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        :value="student.sid"
                                        v-model="selectedStudents"
                                    >
                                </td>
                                <td class="text-muted small" v-text="(pagination.page - 1) * pagination.limit + index + 1"></td>
                                <td>
                                    <div class="avatar-circle">
                                        <img 
                                            :src="student.profile_image" 
                                            :alt="student.name" 
                                            class="avatar-img"
                                            @error="$event.target.src = '/wucportal/admissions/images/avatar.png'"
                                        >
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-bold text-primary small font-monospace" v-text="student.sid"></span>
                                </td>
                                <td class="fw-medium" v-text="student.name"></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <i class="fas me-2 text-muted" :class="student.gender === 'M' ? 'fa-mars text-primary' : 'fa-venus text-danger'"></i>
                                        <span v-text="student.gender"></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-truncate fw-medium" style="max-width: 200px;" :title="student.program"
                                        v-text="student.program"
                                    ></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border" v-text="student.intake"></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border rounded-pill" v-text="student.mode"></span>
                                </td>
                                <td>
                                    <span 
                                        class="badge rounded-pill d-inline-flex align-items-center gap-1"
                                        :class="student.is_transfer ? 'bg-info-subtle text-info border border-info' : 'bg-success-subtle text-success border border-success'"
                                    >
                                        <span class="status-indicator" :class="student.is_transfer ? 'bg-info' : 'bg-success'"></span>
                                        <span v-text="student.is_transfer ? 'Transfer' : 'Regular'"></span>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button 
                                            class="btn btn-outline-primary" 
                                            @click="showStudentDetails(student)" 
                                            title="View Details"
                                        >
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a 
                                            :href="'editStudent.php?edit=' + encodeURIComponent(student.sid)" 
                                            class="btn btn-outline-secondary" 
                                            title="Edit Record"
                                        >
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button 
                                            class="btn btn-outline-danger" 
                                            @click="confirmDelete(student)" 
                                            title="Delete Record"
                                        >
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Section -->
            <div v-show="pagination.pages > 1" class="d-flex justify-content-between align-items-center mt-4">
                <div class="text-muted small">
                    Showing 
                    <span v-text="formatNumber((pagination.page - 1) * pagination.limit + 1)"></span> 
                    to 
                    <span v-text="formatNumber(Math.min(pagination.page * pagination.limit, pagination.total))"></span> 
                    of 
                    <span v-text="formatNumber(pagination.total)"></span> 
                    records
                </div>
                <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm m-0">
                        <li class="page-item" :class="{ disabled: pagination.page === 1 }">
                            <a class="page-link" href="#" @click.prevent="goToPage(pagination.page - 1)">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <template v-for="page in displayedPages()" :key="page">
                            <li class="page-item" :class="{ active: pagination.page === page }">
                                <a class="page-link" href="#" @click.prevent="goToPage(page)" v-text="page"></a>
                            </li>
                        </template>
                        <li class="page-item" :class="{ disabled: pagination.page === pagination.pages }">
                            <a class="page-link" href="#" @click.prevent="goToPage(pagination.page + 1)">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    <!-- Student Detail Modal -->
    <div class="modal fade" id="studentModal" tabindex="-1" aria-labelledby="studentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" v-show="selectedStudent">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="studentModalLabel">
                        <i class="fas fa-user-graduate me-2"></i>Student Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" v-if="selectedStudent">
                    <div class="row align-items-center">
                        <div class="col-md-4 text-center border-end py-3">
                            <img 
                                :src="selectedStudent.profile_image" 
                                :alt="selectedStudent.name" 
                                class="img-fluid rounded-circle mb-3 border p-1"
                                style="width: 120px; height: 120px; object-fit: cover;"
                                @error="$event.target.src = '/wucportal/admissions/images/avatar.png'"
                            >
                            <h5 v-text="selectedStudent.name"></h5>
                            <p class="text-muted mb-0" v-text="selectedStudent.sid"></p>
                        </div>
                        <div class="col-md-8">
                            <table class="table table-hover align-middle">
                                <tr>
                                    <td class="text-muted" width="30%">Program:</td>
                                    <td class="fw-bold" v-text="selectedStudent.program"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Intake:</td>
                                    <td v-text="selectedStudent.intake"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Mode:</td>
                                    <td v-text="selectedStudent.mode"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Gender:</td>
                                    <td v-text="selectedStudent.gender === 'M' ? 'Male' : 'Female'"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Email:</td>
                                    <td v-text="selectedStudent.email || 'N/A'"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted">Mobile:</td>
                                    <td v-text="selectedStudent.mobile || 'N/A'"></td>
                                </tr>
                                <template v-if="selectedStudent.is_transfer">
                                    <tr>
                                        <td class="text-muted">Previous School:</td>
                                        <td v-text="selectedStudent.previous_institution"></td>
                                    </tr>
                                </template>
                                <template v-if="selectedStudent.is_transfer">
                                    <tr>
                                        <td class="text-muted">Credits Transferred:</td>
                                        <td v-text="selectedStudent.credits_transferred"></td>
                                    </tr>
                                </template>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/includes/admit_modal.php'; ?>
</div>

<!-- Dependencies (load first, synchronously) -->
<script src="https://cdn.jsdelivr.net/npm/axios@1.4.0/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<!-- Vue.js 3 -->
<script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.js"></script>

<script>
const { createApp, ref, reactive, computed, onMounted } = Vue;

createApp({
    setup() {
        // State
        const loading = ref(false);
        const students = ref([]);
        const selectedStudents = ref([]);
        const selectedStudent = ref(null);
        const successMessage = ref('');
        const stats = reactive({
            total: { key: 'total', label: 'Total Students', value: 0 },
            active: { key: 'active', label: 'Active Students', value: 0 },
            transfer: { key: 'transfer', label: 'Transfer Students', value: 0 },
            programs: { key: 'programs', label: 'Total Programs', value: 0 }
        });
        const filters = reactive({
            search: '',
            program: '',
            intake: '',
            mode: ''
        });
        const pagination = reactive({
            page: 1,
            limit: 50,
            total: 0,
            pages: 0
        });
        const filterOptions = ref(<?= json_encode($filterOptions ?? ['programs' => [], 'intakes' => [], 'modes' => ['Full-time', 'Part-time', 'Distance']]) ?>);
        const csrfToken = '<?= $csrf_token ?? "" ?>';
        
        // Admission State
        const currentStep = ref(1);
        const searchQuery = ref('');
        const isSearching = ref(false);
        const searchPerformed = ref(false);
        const searchResults = ref([]);
        const selectedAdmissionStudent = ref(null);
        const admissionData = reactive({
            program_code: '',
            intake: '',
            mode: '',
            startYear: new Date().getFullYear(),
            endYear: new Date().getFullYear() + 1,
            term_start_date: '',
            term_end_date: '',
            is_transfer: false,
            previous_institution: '',
            credits_transferred: 0,
            transfer_document: null
        });
        const isSubmittingAdmission = ref(false);
        const programs = ref(<?= json_encode($programs) ?>);
        const intakeOptions = ref([]);
        const modalCsrf = '<?= $modal_csrf ?>';

        // Modals instances
        let studentModal = null;
        let admitModalInstance = null;

        // Methods
        const isPageSelected = () => {
            if (!students.value.length) return false;
            return students.value.every(s => selectedStudents.value.includes(s.sid));
        };

        const displayedPages = () => {
            const pages = [];
            const current = pagination.page;
            const total = pagination.pages;
            const start = Math.max(1, current - 2);
            const end = Math.min(total, current + 2);
            for (let i = start; i <= end; i++) pages.push(i);
            return pages;
        };

        const canSearch = () => {
            return searchQuery.value.trim().length >= 3;
        };

        const programPeriodMode = () => {
            const prog = programs.value.find(p => p.program_code === admissionData.program_code);
            return prog && prog.period_mode ? String(prog.period_mode).toLowerCase() : 'semester';
        };

        const isTermBased = () => {
            return programPeriodMode() === 'term';
        };

        const isRolling = () => {
            return programPeriodMode() === 'rolling';
        };

        const canProceed = () => {
            if (currentStep.value === 1) return selectedAdmissionStudent.value !== null;
            if (currentStep.value === 2) {
                const baseValid = admissionData.program_code &&
                    admissionData.intake &&
                    admissionData.mode &&
                    admissionData.startYear &&
                    admissionData.endYear;
                
                if (isTermBased()) {
                    if (!admissionData.term_start_date || !admissionData.term_end_date) return false;
                }
                
                if (admissionData.is_transfer) {
                    return baseValid && admissionData.previous_institution;
                }
                
                return baseValid;
            }
            return true;
        };

        const searchStudent = async () => {
            if (searchQuery.value.trim().length < 3) return;
            isSearching.value = true;
            searchPerformed.value = true;
            searchResults.value = [];
            
            try {
                const response = await axios.get('search_student.php', {
                    params: { 
                        q: searchQuery.value.trim(), 
                        type: 'admission', 
                        csrf_token: modalCsrf, 
                        ajax: '1' 
                    }
                });
                
                if (response.data.success) {
                    searchResults.value = response.data.students || [];
                } else {
                    Swal.fire({ icon: 'error', title: 'Search failed', text: response.data.error, timer: 3000 });
                }
            } finally {
                isSearching.value = false;
            }
        };

        const selectStudent = (student) => {
            selectedAdmissionStudent.value = student;
            setTimeout(() => { if(currentStep.value < 3 && canProceed()) currentStep.value++; }, 300);
        };

        const updateIntakeOptions = () => {
            const year = new Date().getFullYear();
            const mode = programPeriodMode();
            if (mode === 'term') {
                intakeOptions.value = [
                    { value: 'Term1', text: `Term 1 (Jan-Apr ${year})` },
                    { value: 'Term2', text: `Term 2 (May-Aug ${year})` },
                    { value: 'Term3', text: `Term 3 (Sep-Dec ${year})` }
                ];
            } else if (mode === 'rolling') {
                const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                const label = `${months[new Date().getMonth()]} ${year}`;
                intakeOptions.value = [ { value: label, text: `Rolling intake (${label})` } ];
                admissionData.intake = label;
            } else {
                intakeOptions.value = [
                    { value: 'January', text: `January ${year}` },
                    { value: 'June', text: `June ${year}` },
                    { value: 'January', text: `January ${year + 1}` }
                ];
            }
        };

        const resetAdmitForm = () => {
            currentStep.value = 1;
            searchQuery.value = '';
            searchResults.value = [];
            selectedAdmissionStudent.value = null;
            searchPerformed.value = false;
        };

        const changeStep = (step) => {
            if (step < currentStep.value) {
                currentStep.value = step;
            } else if (step > currentStep.value) {
                if (canProceed()) {
                    currentStep.value = step;
                }
            }
        };

        const prevStep = () => {
            if (currentStep.value > 1) {
                currentStep.value--;
            }
        };

        const nextStep = () => {
            if (currentStep.value < 3 && canProceed()) {
                currentStep.value++;
            }
        };

        let searchTimeout = null;
        const handleSearchInput = () => {
            clearTimeout(searchTimeout);
            if (searchQuery.value.trim().length >= 3) {
                searchTimeout = setTimeout(() => {
                    searchStudent();
                }, 300);
            }
        };

        const handleProgramChange = () => {
            updateIntakeOptions();
            admissionData.intake = '';
        };

        const handleIntakeChange = () => {
            // Optional custom handler
        };

        const handleFileUpload = (event) => {
            const file = event.target.files[0];
            if (file) {
                admissionData.transfer_document = file;
            }
        };

        const getProgramName = (code) => {
            const prog = programs.value.find(p => p.program_code === code);
            return prog ? prog.program_name : code;
        };

        const submitAdmission = async () => {
            isSubmittingAdmission.value = true;
            try {
                const payload = {
                    action: 'admit_student',
                    csrf_token: csrfToken,
                    student_id: selectedAdmissionStudent.value.SID,
                    program_code: admissionData.program_code,
                    intake: admissionData.intake,
                    mode: admissionData.mode,
                    startYear: admissionData.startYear,
                    endYear: admissionData.endYear,
                    term_start_date: admissionData.term_start_date || '',
                    term_end_date: admissionData.term_end_date || '',
                    is_transfer: admissionData.is_transfer ? '1' : '0'
                };
                
                if (admissionData.is_transfer) {
                    payload.previous_institution = admissionData.previous_institution;
                    payload.credits_transferred = admissionData.credits_transferred;
                }

                const response = await axios.post(window.location.href, payload);

                if (response.data.success) {
                    Swal.fire('Success!', response.data.message || 'Student admitted successfully', 'success');
                    if (admitModalInstance) admitModalInstance.hide();
                    resetAdmitForm();
                    fetchStudents();
                    fetchStats();
                } else {
                    Swal.fire('Error', response.data.message || 'Admission failed', 'error');
                }
            } catch (error) {
                console.error(error);
                Swal.fire('Error', 'An error occurred while processing admission', 'error');
            } finally {
                isSubmittingAdmission.value = false;
            }
        };

        const fetchStudents = async () => {
            loading.value = true;
            try {
                const response = await axios.post(window.location.href, {
                    action: 'get_students',
                    page: pagination.page,
                    limit: pagination.limit,
                    search: filters.search,
                    program: filters.program,
                    intake: filters.intake,
                    mode: filters.mode,
                    csrf_token: csrfToken
                });
                if (response.data.success) {
                    students.value = response.data.data || [];
                    pagination.total = response.data.total || 0;
                    pagination.pages = response.data.pages || 1;
                }
            } catch (error) {
                Swal.fire('Error', 'Failed to load students', 'error');
            } finally {
                loading.value = false;
            }
        };

        const fetchStats = async () => {
            try {
                const response = await axios.post(window.location.href, { action: 'get_stats', csrf_token: csrfToken });
                if (response.data.success) {
                    const data = response.data.data;
                    stats.total.value = data.total || 0;
                    stats.active.value = data.active || 0;
                    stats.transfer.value = data.transfer || 0;
                    stats.programs.value = data.programs || 0;
                }
            } catch (error) { console.error(error); }
        };

        let searchDebounceTimeout = null;
        const applyFilters = () => {
            pagination.page = 1;
            clearTimeout(searchDebounceTimeout);
            searchDebounceTimeout = setTimeout(() => fetchStudents(), 300);
        };

        const resetFilters = () => {
            filters.search = '';
            filters.program = '';
            filters.intake = '';
            filters.mode = '';
            applyFilters();
        };

        const goToPage = (page) => {
            if (page >= 1 && page <= pagination.pages) {
                pagination.page = page;
                fetchStudents();
            }
        };

        const isSelected = (sid) => selectedStudents.value.includes(sid);

        const toggleSelectPage = () => {
            if (isPageSelected()) {
                students.value.forEach(s => {
                    const idx = selectedStudents.value.indexOf(s.sid);
                    if (idx > -1) selectedStudents.value.splice(idx, 1);
                });
            } else {
                students.value.forEach(s => {
                    if (!selectedStudents.value.includes(s.sid)) selectedStudents.value.push(s.sid);
                });
            }
        };

        const showStudentDetails = (student) => {
            selectedStudent.value = student;
            if (studentModal) studentModal.show();
        };

        const confirmDelete = (student) => {
            Swal.fire({
                title: 'Delete Student?',
                text: `Are you sure you want to delete ${student.name}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, delete'
            }).then((result) => {
                if (result.isConfirmed) deleteStudent(student.sid);
            });
        };

        const deleteStudent = async (sid) => {
            try {
                const response = await axios.post(window.location.href, { action: 'delete_student', sid: sid, csrf_token: csrfToken });
                if (response.data.success) {
                    Swal.fire('Deleted!', response.data.message, 'success');
                    fetchStudents();
                    fetchStats();
                    const idx = selectedStudents.value.indexOf(sid);
                    if (idx > -1) selectedStudents.value.splice(idx, 1);
                } else throw new Error(response.data.message);
            } catch (error) { Swal.fire('Error', error.message, 'error'); }
        };

        const bulkExport = async (format) => {
            try {
                const response = await axios.post(window.location.href, {
                    action: 'bulk_export',
                    student_ids: selectedStudents.value.length ? selectedStudents.value : students.value.map(s => s.sid),
                    format: format,
                    csrf_token: csrfToken
                });
                if (response.data.success) window.location.href = response.data.download_url;
            } catch (error) { Swal.fire('Error', 'Export failed', 'error'); }
        };

        const bulkPrint = () => window.print();

        const refreshData = () => { fetchStudents(); fetchStats(); };

        const formatNumber = (num) => new Intl.NumberFormat().format(num);

        onMounted(() => {
            const modalEl = document.getElementById('studentModal');
            if (modalEl) studentModal = new bootstrap.Modal(modalEl);
            const admitModalEl = document.getElementById('admitModal');
            if (admitModalEl) {
                admitModalInstance = new bootstrap.Modal(admitModalEl);
                admitModalEl.addEventListener('hidden.bs.modal', () => resetAdmitForm());
            }
            fetchStudents();
            fetchStats();
        });

        return {
            loading, students, selectedStudents, selectedStudent, successMessage, stats, filters, pagination, filterOptions,
            csrfToken, currentStep, searchQuery, isSearching, searchPerformed, searchResults, selectedAdmissionStudent,
            admissionData, isSubmittingAdmission, programs, intakeOptions, modalCsrf, isPageSelected, displayedPages, canSearch,
            isTermBased, isRolling, canProceed, searchStudent, selectStudent, updateIntakeOptions, resetAdmitForm,
            fetchStudents, fetchStats, applyFilters, resetFilters, goToPage, isSelected, toggleSelectPage, showStudentDetails,
            confirmDelete, deleteStudent, bulkExport, bulkPrint, refreshData, formatNumber,
            changeStep, prevStep, nextStep, handleSearchInput, handleProgramChange, handleIntakeChange, handleFileUpload,
            getProgramName, submitAdmission
        };
    }
}).mount('#app');
</script>

<style>
.border-left-primary { border-left: 4px solid #4e73df !important; }
.border-left-success { border-left: 4px solid #1cc88a !important; }
.border-left-info { border-left: 4px solid #36b9cc !important; }
.border-left-warning { border-left: 4px solid #f6c23e !important; }
.text-gray-300 { color: #dddfeb !important; }
.text-gray-800 { color: #5a5c69 !important; }
.avatar-circle { position: relative; display: inline-block; }
.avatar-img { object-fit: cover; width: 40px; height: 40px; border-radius: 50%; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
.table-selected { background-color: rgba(78, 115, 223, 0.1) !important; }
.status-indicator { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
[v-cloak] { display: none !important; }
@media print {
    .no-print, .btn-group, .dropdown, .card-header .btn { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>

<?php require "includes/footer.php"; ?>
