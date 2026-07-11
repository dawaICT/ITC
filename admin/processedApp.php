<?php
// ===== STEP 1: SECURITY + SESSION =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== STEP 2: AUTHENTICATION CHECK (BEFORE ANY PROCESSING) =====
require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';

if (!isset($_SESSION['staff_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in again.']);
        exit;
    }
    header('Location: ../staff_login.php');
    exit;
}

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' data: https:;");

// Environment setup
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
$isDebug = ($WUC_ENV === 'development' || isset($_GET['debug']));

if ($isDebug) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// ===== STEP 3: CSRF TOKEN =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if (!function_exists('processed_app_h')) {
    function processed_app_h($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// ===== STEP 4: DUPLICATE CHECK HELPER =====
function isApplicantAlreadyAdded(mysqli $db, ?int $applicant_id, ?string $nrc_pass, ?string $email, ?string $mobile, bool $tracking_table_exists): array {
    $nrc_pass = trim((string)$nrc_pass);
    $email = trim((string)$email);
    $mobile = trim((string)$mobile);

    // Check tracking table first (fastest)
    if ($tracking_table_exists && $applicant_id !== null) {
        $check = $db->prepare("SELECT 1 as source, 'tracking' as reason FROM processed_applicants_added WHERE applicant_id = ? LIMIT 1");
        if ($check) {
            $check->bind_param("i", $applicant_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $check->close();
                return ['status' => true, 'reason' => 'Already processed in tracking system'];
            }
            $check->close();
        }
    }
    
    // Build dynamic WHERE clause for students table
    $checks = [];
    $params = [];
    $types = "";
    
    if (!empty($nrc_pass)) {
        $checks[] = "(LOWER(TRIM(nrc_pass)) = LOWER(TRIM(?)))";
        $params[] = $nrc_pass;
        $types .= "s";
    }
    if (!empty($email)) {
        $checks[] = "(LOWER(TRIM(email)) = LOWER(TRIM(?)))";
        $params[] = $email;
        $types .= "s";
    }
    if (!empty($mobile)) {
        $checks[] = "(TRIM(mobile) = TRIM(?))";
        $params[] = $mobile;
        $types .= "s";
    }
    
    if (empty($checks)) {
        return ['status' => false, 'reason' => ''];
    }
    
    $query = "SELECT SID, Fname, Lname, 'student' as source FROM students WHERE " . implode(" OR ", $checks) . " LIMIT 1";
    $stmt = $db->prepare($query);
    
    if ($stmt && !empty($params)) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            $stmt->close();
            return [
                'status' => true, 
                'reason' => "Exists as student: {$student['Fname']} {$student['Lname']} ({$student['SID']})"
            ];
        }
        $stmt->close();
    }
    
    return ['status' => false, 'reason' => ''];
}

// Tracking table availability (computed once)
$tracking_table_exists = false;
if (isset($db) && $db instanceof mysqli) {
    $tracking_table_exists = $db->query("SHOW TABLES LIKE 'processed_applicants_added'")->num_rows > 0;
}
// ===== STEP 5: AJAX HANDLER (BEFORE PAGE RENDER) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
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
            case 'check_duplicate':
                $nrc = $_POST['nrc'] ?? '';
                $email = $_POST['email'] ?? '';
                $mobile = $_POST['mobile'] ?? '';
                $check = isApplicantAlreadyAdded($db, null, $nrc, $email, $mobile, $tracking_table_exists);
                $response = ['success' => true, 'exists' => $check['status'], 'message' => $check['reason']];
                break;
                
            case 'get_details':
                $applicant_id = filter_var($_POST['applicant_id'] ?? 0, FILTER_VALIDATE_INT);
                if (!$applicant_id || $applicant_id <= 0) {
                    throw new Exception('Invalid applicant ID');
                }
                
                $detail_stmt = $db->prepare("SELECT * FROM processed_applicants WHERE id = ?");
                if (!$detail_stmt) throw new Exception('Database error');
                
                $detail_stmt->bind_param("i", $applicant_id);
                $detail_stmt->execute();
                $details = $detail_stmt->get_result()->fetch_assoc();
                $detail_stmt->close();
                
                if (!$details) {
                    throw new Exception('Applicant not found');
                }
                
                $check = isApplicantAlreadyAdded(
                    $db,
                    $details['id'],
                    $details['nrc_pass'] ?? null,
                    $details['email'] ?? null,
                    $details['mobile'] ?? null,
                    $tracking_table_exists
                );
                
                $details['already_added'] = $check['status'];
                $details['duplicate_reason'] = $check['reason'];
                $response = ['success' => true, 'data' => $details];
                break;
                
            default:
                throw new Exception('Invalid AJAX action');
        }
    } catch (Exception $e) {
        error_log("Admin Processed Apps AJAX Error ({$_POST['ajax_action']}): " . $e->getMessage());
        $response = [
            'success' => false,
            'message' => $isDebug ? $e->getMessage() : 'An error occurred'
        ];
    }
    
    echo json_encode($response);
    exit;
}

// ===== STEP 6: NORMAL PAGE RENDER =====
$page_title = "Processed Applications";
require_once "includes/header.php";

// Validate database connection
if (!isset($db) || !$db instanceof mysqli) {
    error_log("Database connection failed in admin/processedApp.php");
    die("<div class='alert alert-danger'>System error: Database unavailable</div>");
}

// Check required tables
$required_tables = ['students', 'processed_applicants'];
foreach ($required_tables as $table) {
    $escaped = $db->real_escape_string($table);
    $check = $db->query("SHOW TABLES LIKE '$escaped'");
    if ($check->num_rows === 0) {
        error_log("Missing required table: $table");
        die("<div class='alert alert-danger'>Configuration error: Missing table '$table'</div>");
    }
}
// ===== STEP 7: OPTIMIZED QUERY WITH PAGINATION =====
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Get total count
$count_stmt = $db->prepare("SELECT COUNT(*) as total FROM processed_applicants");
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

// Fetch paginated results
$query = "SELECT 
    pa.id, pa.Fname, pa.Lname, pa.sex, pa.email, pa.mobile, 
    pa.country, pa.nrc_pass, pa.program, pa.mode, pa.intake, 
    pa.year, pa.dte_adm, pa.results, pa.status
FROM processed_applicants pa
ORDER BY pa.dte_adm DESC
LIMIT ? OFFSET ?";

$stmt = $db->prepare($query);
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

$applications = [];
while ($row = $result->fetch_assoc()) {
    $applications[] = $row;
}
$stmt->close();

// Batch duplicate check
$nrc_list = array_filter(array_column($applications, 'nrc_pass'));
$email_list = array_filter(array_column($applications, 'email'));
$mobile_list = array_filter(array_column($applications, 'mobile'));

$duplicates = [];
if (!empty($nrc_list) || !empty($email_list) || !empty($mobile_list)) {
    $where_clauses = [];
    $params = [];
    $types = "";
    
    if (!empty($nrc_list)) {
        $placeholders = implode(',', array_fill(0, count($nrc_list), '?'));
        $where_clauses[] = "nrc_pass IN ($placeholders)";
        $params = array_merge($params, $nrc_list);
        $types .= str_repeat('s', count($nrc_list));
    }
    if (!empty($email_list)) {
        $placeholders = implode(',', array_fill(0, count($email_list), '?'));
        $where_clauses[] = "email IN ($placeholders)";
        $params = array_merge($params, $email_list);
        $types .= str_repeat('s', count($email_list));
    }
    if (!empty($mobile_list)) {
        $placeholders = implode(',', array_fill(0, count($mobile_list), '?'));
        $where_clauses[] = "mobile IN ($placeholders)";
        $params = array_merge($params, $mobile_list);
        $types .= str_repeat('s', count($mobile_list));
    }
    
    if (!empty($where_clauses)) {
        $query = "SELECT SID, nrc_pass, email, mobile FROM students WHERE " . implode(' OR ', $where_clauses);
        $stmt = $db->prepare($query);
        if ($stmt && !empty($params)) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $duplicates[$row['nrc_pass']] = $row['SID'];
                $duplicates[$row['email']] = $row['SID'];
                $duplicates[$row['mobile']] = $row['SID'];
            }
            $stmt->close();
        }
    }
}

// Batch tracking-table lookup to avoid N+1 queries
$trackedIds = [];
if ($tracking_table_exists && !empty($applications)) {
    $applicationIds = array_map(static fn($a) => (int)$a['id'], $applications);
    $placeholders = implode(',', array_fill(0, count($applicationIds), '?'));
    $trackedQuery = "SELECT applicant_id FROM processed_applicants_added WHERE applicant_id IN ($placeholders)";
    $trackedStmt = $db->prepare($trackedQuery);
    if ($trackedStmt) {
        $trackedTypes = str_repeat('i', count($applicationIds));
        $trackedStmt->bind_param($trackedTypes, ...$applicationIds);
        $trackedStmt->execute();
        $trackedResult = $trackedStmt->get_result();
        while ($trackedRow = $trackedResult->fetch_assoc()) {
            $trackedIds[(int)$trackedRow['applicant_id']] = true;
        }
        $trackedStmt->close();
    }
}
// Add duplicate status to each applicant
$ready_to_add = 0;
foreach ($applications as &$app) {
    $app['already_added'] = false;
    $app['duplicate_reason'] = '';
    
    $appNrc = (string)($app['nrc_pass'] ?? '');
    $appEmail = (string)($app['email'] ?? '');
    $appMobile = (string)($app['mobile'] ?? '');

    if ($appNrc !== '' && isset($duplicates[$appNrc])) {
        $app['already_added'] = true;
        $app['duplicate_reason'] = "Exists as student (NRC match)";
    } elseif ($appEmail !== '' && isset($duplicates[$appEmail])) {
        $app['already_added'] = true;
        $app['duplicate_reason'] = "Exists as student (Email match)";
    } elseif ($appMobile !== '' && isset($duplicates[$appMobile])) {
        $app['already_added'] = true;
        $app['duplicate_reason'] = "Exists as student (Mobile match)";
    }
    if (!$app['already_added'] && isset($trackedIds[(int)$app['id']])) {
        $app['already_added'] = true;
        $app['duplicate_reason'] = "Already processed in tracking system";
    }

    if (!$app['already_added']) $ready_to_add++;
}
unset($app);

?>

<!-- Main Content -->
<div class="container-fluid px-4 portal-dashboard processed-app-page">

    <!-- Page Header -->
    <div class="page-header mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-clipboard-check me-2 text-primary"></i>Processed Applications</h5>
                <p class="page-subtitle mb-0">Manage and convert approved applicants to students &middot; Direct Enrollment</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary" onclick="refreshData()">
                    <i class="fas fa-sync-alt me-1"></i>Refresh
                </button>
                <a href="applicants.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <div id="alertContainer">
        <?php if (isset($_SESSION['successMessage'])): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['successMessage']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['successMessage']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['errorMessage'])): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['errorMessage']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['errorMessage']); ?>
        <?php endif; ?>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3"><i class="fas fa-users text-white"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total) ?></h3>
                        <p class="text-muted mb-0">Total Processed</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-success me-3"><i class="fas fa-user-check text-white"></i></div>
                    <div>
                        <h3 class="mb-0" id="readyCount"><?= $ready_to_add ?></h3>
                        <p class="text-muted mb-0">Ready to Add</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="data-table-card">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Approved Applications</h5>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-1"></i>Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                        <li><a class="dropdown-item" href="#" onclick="exportTable('csv')"><i class="fas fa-file-csv me-2"></i>CSV</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="#" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (!empty($applications)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="processedTable">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center">#</th>
                                <th>Applicant</th>
                                <th>Contact Details</th>
                                <th>Program & Intake</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $counter = $offset + 1;
                            foreach ($applications as $row):
                                $already_added = $row['already_added'];
                                $duplicate_reason = $row['duplicate_reason'];

                                $status_val = strtolower($row['status'] ?? '');
                                $status_dot = match ($status_val) {
                                    'accepted' => 'accepted',
                                    'pending' => 'pending',
                                    'rejected' => 'rejected',
                                    default => 'default'
                                };
                            ?>
                                <tr data-applicant-id="<?= $row['id'] ?>" class="<?= $already_added ? 'table-secondary opacity-75' : '' ?>">
                                    <td class="text-center fw-bold text-muted"><?= $counter++ ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-circle me-3">
                                                <?= strtoupper(substr((string)($row['Fname'] ?? ''), 0, 1) . substr((string)($row['Lname'] ?? ''), 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?= processed_app_h(($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? '')) ?></div>
                                                <div class="d-flex gap-1 mt-1">
                                                    <span class="gender-pill <?= $row['sex'] === 'M' ? 'male' : 'female' ?>">
                                                        <?= $row['sex'] === 'M' ? 'Male' : 'Female' ?>
                                                    </span>
                                                    <?php if ($already_added): ?>
                                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill small duplicate-badge" title="<?= processed_app_h($duplicate_reason) ?>">
                                                            <i class="fas fa-check-circle me-1"></i>Already Student
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div class="mb-1">
                                                <i class="fas fa-envelope text-muted me-2 contact-icon-fixed"></i><?= processed_app_h($row['email'] ?: 'N/A') ?>
                                            </div>
                                            <div class="mb-1">
                                                <i class="fas fa-phone text-muted me-2 contact-icon-fixed"></i><?= processed_app_h($row['mobile']) ?>
                                            </div>
                                            <div>
                                                <i class="fas fa-id-card text-muted me-2 contact-icon-fixed"></i><?= processed_app_h($row['nrc_pass']) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div class="program-badge mb-1"><?= processed_app_h($row['program']) ?></div>
                                            <div class="text-muted">
                                                <i class="far fa-calendar-alt me-1"></i> <?= processed_app_h($row['intake']) ?> <?= processed_app_h($row['year']) ?>
                                                <span class="program-mode-tag"><?= processed_app_h($row['mode']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="status-dot <?= $status_dot ?>"></span>
                                        <span class="small fw-semibold"><?= ucfirst($status_val ?: 'N/A') ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">
                                            <button class="btn btn-sm btn-outline-primary view-btn" title="View Details" data-applicant-id="<?= $row['id'] ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-success add-btn <?= $already_added ? 'disabled' : '' ?>"
                                                title="<?= $already_added ? 'Already Added' : 'Add as Student' ?>"
                                                data-applicant-id="<?= $row['id'] ?>"
                                                data-applicant-name="<?= processed_app_h(($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? '')) ?>"
                                                data-applicant-nrc="<?= processed_app_h($row['nrc_pass']) ?>"
                                                data-applicant-email="<?= processed_app_h($row['email']) ?>"
                                                data-applicant-mobile="<?= processed_app_h($row['mobile']) ?>">
                                                <i class="fas fa-<?= $already_added ? 'check' : 'user-plus' ?>"></i>
                                            </button>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                    <?php if (!empty($row['results'])): ?>
                                                        <li>
                                                            <a class="dropdown-item" href="../online_services/uploads/<?= htmlspecialchars(basename($row['results'])) ?>" target="_blank">
                                                                <i class="fas fa-file-alt text-info me-2"></i>Check Results
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
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
                        <i class="fas fa-inbox fa-stack-1x"></i>
                    </span>
                </div>
                <h5>No processed applications found</h5>
                <p class="text-muted">Applications will appear here after processing.</p>
                <a href="applicants.php" class="btn btn-primary rounded-pill px-4">
                    <i class="fas fa-arrow-left me-2"></i>Go to Applications
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Applicant Details Modal -->
<div class="modal fade" id="applicantModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-id-card-alt me-2"></i>Applicant Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="modalContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Loading applicant information...</p>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-primary" onclick="printModal()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
                <button type="button" class="btn btn-success" id="modalAddBtn" onclick="addFromModal()">
                    <i class="fas fa-user-plus me-2"></i>Add as Student
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center p-4">
                <div class="mb-3">
                    <span class="fa-stack fa-2x text-warning">
                        <i class="fas fa-circle fa-stack-2x opacity-25"></i>
                        <i class="fas fa-question fa-stack-1x"></i>
                    </span>
                </div>
                <h5 class="mb-2">Add Student?</h5>
                <p class="text-muted small mb-4" id="confirmMessage">Are you sure you want to add this applicant as a student?</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-success" id="confirmActionBtn">
                        <i class="fas fa-check me-2"></i>Yes, Proceed
                    </button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Page JavaScript (jQuery, Bootstrap, DataTables, SweetAlert2 loaded by nav_unified.php) -->
<script>
// Global state
let dataTable = null;
let currentApplicant = null;
const csrfToken = '<?= $csrf_token ?>';
const trackingTableExists = <?= $tracking_table_exists ? 'true' : 'false' ?>;

$(document).ready(function() {
    initializeDataTable();
    $('#processedTable').on('draw.dt', updateStats);
    initializeEventListeners();
    updateStats();
    
    // Auto-hide alerts
    setTimeout(() => {
        $('.alert:not(.alert-permanent)').fadeOut('slow');
    }, 5000);
});

function initializeDataTable() {
    if (!$.fn.DataTable) {
        console.warn('DataTables not loaded, skipping initialization');
        return;
    }
    dataTable = $('#processedTable').DataTable({
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'desc']],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search applicants...",
            lengthMenu: "Show _MENU_",
            info: "Showing _START_ to _END_ of _TOTAL_ applicants",
            emptyTable: "No processed applications found",
            zeroRecords: "No matching applicants found"
        },
        columnDefs: [
            { orderable: false, targets: -1 },
            { responsivePriority: 1, targets: [1, -1] }
        ],
        dom: '<"row align-items-center mb-3"<"col-md-6"l><"col-md-6"f>>' +
             't' +
             '<"row align-items-center mt-3"<"col-md-5"i><"col-md-7"p>>'
    });
}

function initializeEventListeners() {
    $(document).on('click', '.view-btn', function(e) {
        e.preventDefault();
        const applicantId = $(this).data('applicant-id');
        if (applicantId) loadApplicantDetails(applicantId);
    });
    
    $(document).on('click', '.add-btn:not(.disabled)', function(e) {
        e.preventDefault();
        const btn = $(this);
        const applicantData = {
            id: btn.data('applicant-id'),
            name: btn.data('applicant-name'),
            nrc: btn.data('applicant-nrc'),
            email: btn.data('applicant-email'),
            mobile: btn.data('applicant-mobile')
        };
        if (applicantData.id) showConfirmModal(applicantData);
    });
    
    $('#confirmActionBtn').on('click', function() {
        if (currentApplicant?.id) {
            addStudent(currentApplicant);
            $('#confirmModal').modal('hide');
        }
    });
}

function updateStats() {
    const nodes = dataTable ? $(dataTable.rows({search:'applied'}).nodes()) : $();
    const total = nodes.length;
    const processed = nodes.filter('.table-secondary').length;
    $('#readyCount').text(Math.max(0, total - processed));
}

function loadApplicantDetails(applicantId) {
    $('#applicantModal').modal('show');
    $('#modalContent').html(`
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">Loading applicant information...</p>
        </div>
    `);
    
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: {
            ajax_action: 'get_details',
            applicant_id: applicantId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        timeout: 10000,
        success: function(response) {
            if (response.success && response.data) {
                currentApplicant = response.data;
                renderApplicantDetails(response.data);
            } else {
                showErrorInModal(response.message || 'Failed to load details');
            }
        },
        error: function(xhr, status, error) {
            let msg = 'Error loading details';
            if (xhr.status === 403) msg = 'Security token expired. Please refresh the page.';
            else if (xhr.status === 401) msg = 'Session expired. Please log in again.';
            else if (xhr.responseText) {
                try {
                    const err = JSON.parse(xhr.responseText);
                    msg = err.message || msg;
                } catch (e) {
                    msg = xhr.responseText.substring(0, 100);
                }
            }
            showErrorInModal(msg);
        }
    });
}

function showErrorInModal(message) {
    $('#modalContent').html(`
        <div class="alert alert-danger d-flex align-items-center" role="alert">
            <i class="fas fa-exclamation-triangle me-2 fa-lg"></i>
            <div>${escapeHtml(message)}</div>
        </div>
    `);
}

function renderApplicantDetails(data) {
    const isAdded = data.already_added;
    
    $('#modalAddBtn')
        .prop('disabled', isAdded)
        .html(isAdded ? 
            '<i class="fas fa-check me-2"></i>Already Added' : 
            '<i class="fas fa-user-plus me-2"></i>Add as Student'
        );
    
    const html = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-uppercase text-muted small fw-bold mb-3 border-bottom pb-2">Profile</h6>
                <div class="mb-2"><span class="text-muted d-block small">Full Name</span> <span class="fw-bold">${escapeHtml(data.Fname + ' ' + data.Lname)}</span></div>
                <div class="mb-2"><span class="text-muted d-block small">Gender</span> <span>${data.sex === 'M' ? 'Male' : 'Female'}</span></div>
                <div class="mb-2"><span class="text-muted d-block small">DOB</span> <span>${data.dob || 'N/A'}</span></div>
                <div class="mb-2"><span class="text-muted d-block small">Nationality</span> <span>${escapeHtml(data.country)}</span></div>
                <div class="p-2 bg-light rounded mt-3">
                    <span class="text-muted d-block small">NRC / Passport</span>
                    <span class="font-monospace">${escapeHtml(data.nrc_pass)}</span>
                </div>
            </div>
            <div class="col-md-6">
                <h6 class="text-uppercase text-muted small fw-bold mb-3 border-bottom pb-2">Contact Info</h6>
                <div class="mb-2"><i class="fas fa-envelope text-muted me-2"></i>${escapeHtml(data.email)}</div>
                <div class="mb-2"><i class="fas fa-phone text-muted me-2"></i>${escapeHtml(data.mobile)}</div>
                <div class="mb-2"><i class="fas fa-map-marker-alt text-muted me-2"></i>${escapeHtml(data.address || 'No address provided')}</div>
            </div>
        </div>
        <div class="row mt-4">
            <div class="col-12">
                <h6 class="text-uppercase text-muted small fw-bold mb-3 border-bottom pb-2">Admission Data</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-3 border rounded h-100">
                            <small class="text-muted d-block">Program</small>
                            <span class="fw-bold text-primary">${escapeHtml(data.program)}</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 border rounded h-100">
                            <small class="text-muted d-block">Intake</small>
                            <span>${escapeHtml(data.intake)} ${data.year}</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 border rounded h-100">
                            <small class="text-muted d-block">Status</small>
                            <span class="badge bg-${data.status === 'accepted' ? 'success' : 'warning'}">${escapeHtml(data.status)}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        ${isAdded ? `<div class="alert alert-warning mt-3 mb-0"><i class="fas fa-exclamation-triangle me-2"></i>${escapeHtml(data.duplicate_reason)}</div>` : ''}
    `;
    
    $('#modalContent').html(html);
}

function showConfirmModal(applicantData) {
    currentApplicant = applicantData;
    $('#confirmMessage').html(`
        Add <strong>${escapeHtml(applicantData.name)}</strong> as a student?<br>
        <small class="text-muted">NRC: ${escapeHtml(applicantData.nrc)}</small>
    `);
    $('#confirmModal').modal('show');
}

function addStudent(applicantData) {
    Swal.fire({
        title: 'Processing...',
        html: 'Adding student to registry',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    const formData = new FormData();
    formData.append('view', applicantData.id);
    formData.append('csrf_token', csrfToken);
    formData.append('applicant_nrc', applicantData.nrc);
    formData.append('applicant_email', applicantData.email);
    formData.append('applicant_mobile', applicantData.mobile);
    
    $.ajax({
        url: 'addonlineStudent.php',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        timeout: 30000,
        success: function(response) {
            let isSuccess = false;
            let message = '';
            let studentId = '';
            let defaultPassword = '';
            let warnings = [];

            try {
                const data = typeof response === 'string' ? JSON.parse(response) : response;
                isSuccess = data.success === true || data.status === 'success';
                message = data.message || data.msg || '';
                studentId = data.student_id || '';
                defaultPassword = data.default_password || '';
                warnings = Array.isArray(data.warnings) ? data.warnings : [];
            } catch (e) {
                const respStr = typeof response === 'string' ? response : JSON.stringify(response);
                isSuccess = respStr.toLowerCase().includes('success') ||
                           respStr.includes('Successfully');
                message = isSuccess ? 'Student added successfully' : 'Unexpected server response';
            }
            
            if (isSuccess) {
                const btn = $(`.add-btn[data-applicant-id="${applicantData.id}"]`);
                btn.addClass('disabled')
                   .html('<i class="fas fa-check text-success me-2"></i>Already Added')
                   .closest('tr')
                   .addClass('table-secondary opacity-75');
                
                const nameCell = btn.closest('tr').find('td:nth-child(2) .badge');
                if (nameCell.length === 0) {
                    btn.closest('tr').find('td:nth-child(2) div:last')
                       .append('<span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill ms-2"><i class="fas fa-exclamation-circle me-1"></i>Exists</span>');
                }
                
                $('#applicantModal').modal('hide');
                updateStats();

                // Build a credentials + warnings summary so the officer can hand
                // the student their student number and first-login password.
                let html = `<p class="mb-2">${escapeHtml(message || applicantData.name + ' has been registered.')}</p>`;
                if (studentId) {
                    html += `<div class="text-start small bg-light border rounded p-2 mb-2">
                        <div><strong>Student No:</strong> <code>${escapeHtml(studentId)}</code></div>`;
                    if (defaultPassword) {
                        html += `<div><strong>First password:</strong> <code>${escapeHtml(defaultPassword)}</code>
                            <span class="text-muted">(must change on first login)</span></div>`;
                    }
                    html += `</div>`;
                }
                if (warnings.length) {
                    html += `<div class="text-start small text-warning-emphasis">
                        <i class="fas fa-exclamation-triangle me-1"></i>` +
                        warnings.map(w => escapeHtml(w)).join('<br>') + `</div>`;
                }

                Swal.fire({
                    icon: warnings.length ? 'warning' : 'success',
                    title: 'Student Admitted!',
                    html: html,
                    confirmButtonText: 'Done'
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Action Failed',
                    html: `<small class="text-muted">${escapeHtml(message || 'Could not add student. Please try again.')}</small>`,
                    confirmButtonText: 'OK'
                });
            }
        },
        error: function(xhr, status, error) {
            let message = 'Failed to communicate with server';
            if (xhr.status === 403) message = 'Security token expired. Please refresh the page.';
            else if (xhr.status === 401) message = 'Session expired. Please log in again.';
            else if (xhr.responseText) {
                try {
                    const err = JSON.parse(xhr.responseText);
                    message = err.message || message;
                } catch (e) {
                    message = xhr.responseText.substring(0, 150);
                }
            }
            
            Swal.fire({
                icon: 'error',
                title: 'Error',
                html: `<small class="text-muted">${escapeHtml(message)}</small>`,
                confirmButtonText: xhr.status === 403 ? 'Refresh Page' : 'OK'
            }).then((result) => {
                if (result.isConfirmed && xhr.status === 403) location.reload();
            });
        }
    });
}

function addFromModal() {
    if (currentApplicant && !currentApplicant.already_added) {
        const data = {
            id: currentApplicant.id,
            name: currentApplicant.Fname + ' ' + currentApplicant.Lname,
            nrc: currentApplicant.nrc_pass,
            email: currentApplicant.email,
            mobile: currentApplicant.mobile
        };
        addStudent(data);
    }
}

function refreshData() {
    window.location.reload();
}

function printModal() {
    const content = document.getElementById('modalContent').innerHTML;
    const printWindow = window.open('', '', 'height=600,width=800');
    printWindow.document.write(`
        <html>
        <head>
            <title>Applicant Details</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { padding: 20px; }
                .border-bottom { border-bottom: 1px solid #dee2e6 !important; }
                .bg-light { background-color: #f8f9fa !important; }
            </style>
        </head>
        <body>
            <h3 class="text-center mb-4">Applicant Record</h3>
            <div class="container">${content}</div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => { 
        printWindow.print(); 
        printWindow.close();
    }, 500);
}

function exportTable(format) {
    if (format !== 'csv') {
        Swal.fire('Info', 'Only CSV export is available in this version', 'info');
        return;
    }
    
    let csv = [];
    const headers = [];
    $('#processedTable thead th').each(function(index) {
        if (index < 5) {
            headers.push('"' + $(this).text().replace(/"/g, '""') + '"');
        }
    });
    csv.push(headers.join(','));
    
    $('#processedTable tbody tr').each(function() {
        const row = [];
        $(this).find('td').each(function(index) {
            if (index < 5) {
                let text = $(this).text().trim().replace(/"/g, '""');
                if ($(this).find('.badge').length) {
                    text = $(this).find('.badge').text().trim();
                }
                row.push('"' + text + '"');
            }
        });
        if (row.length > 0) csv.push(row.join(','));
    });
    
    const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `processed_applications_${new Date().toISOString().slice(0,10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php require_once "includes/footer.php"; ?>
