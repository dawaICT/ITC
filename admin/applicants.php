<?php
require "includes/admin.php";
$page_title = "Online Applicants";
require_once "includes/header.php";
?>

<style>
    html,
    body.has-unified-sidebar,
    .main-wrapper,
    .main-content {
        background: #f6f8fb !important;
    }

    .applicants-page {
        min-height: calc(100vh - 3rem);
    }

    .applicants-page .page-header,
    .applicants-page .data-table-card,
    .applicants-page .stat-card {
        border: 1px solid #e9eef5;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    }

    .applicants-page .nav-tabs {
        border-bottom-color: #dbe4ef;
    }

    .applicants-page .nav-tabs .nav-link {
        border-radius: 8px 8px 0 0;
        color: #64748b;
        font-weight: 600;
        letter-spacing: 0;
    }

    .applicants-page .nav-tabs .nav-link.active {
        color: #1f2937;
        border-color: #dbe4ef #dbe4ef #fff;
    }

    /* .avatar-circle → assets/css/dashboard.css; smaller circular variant: */
    .applicants-page .avatar-circle { width: 38px; height: 38px; border-radius: 50%; font-size: 13px; }

    .gender-pill {
        font-size: 0.72rem;
        padding: 2px 8px;
        border-radius: 50px;
        font-weight: 600;
        display: inline-block;
    }

    .gender-pill.male {
        background: #eff6ff;
        color: #3b82f6;
    }

    .gender-pill.female {
        background: #fdf2f8;
        color: #db2777;
    }

    .program-badge {
        font-size: 0.8rem;
        font-weight: 700;
        color: #6f42c1;
    }

    /* .stat-card hover → assets/css/dashboard.css */

    .applicants-page .dataTables_wrapper .dataTables_length,
    .applicants-page .dataTables_wrapper .dataTables_filter {
        margin-bottom: 1rem;
    }

    .applicants-page .table-responsive {
        overflow-x: auto;
        overflow-y: visible;
    }

    .applicants-page .dataTables_wrapper .dataTables_filter input {
        border: 1px solid #dbe4ef;
        border-radius: 8px;
        min-height: 38px;
        padding: 0.375rem 0.75rem;
    }

    .applicants-page .table th {
        white-space: nowrap;
    }

    .applicants-page .table td {
        vertical-align: middle;
    }

    @media (max-width: 767.98px) {
        .applicants-page {
            padding-left: 0.75rem !important;
            padding-right: 0.75rem !important;
        }

        .applicants-page .header-actions {
            width: 100%;
        }

        .applicants-page .header-actions .btn {
            flex: 1 1 0;
        }

        .applicants-page .stat-card {
            padding: 1rem;
        }

        .applicants-page .dataTables_wrapper .dataTables_length,
        .applicants-page .dataTables_wrapper .dataTables_filter {
            text-align: left;
            width: 100%;
        }

        .applicants-page .dataTables_wrapper .dataTables_length label,
        .applicants-page .dataTables_wrapper .dataTables_filter label {
            display: block;
            width: 100%;
        }

        .applicants-page .dataTables_wrapper .dataTables_filter input {
            display: block;
            width: 100%;
            max-width: 100%;
            margin-left: 0;
            margin-top: 0.35rem;
            box-sizing: border-box;
        }

        .applicants-page .table-responsive {
            max-height: none;
            overflow-x: auto;
            overflow-y: visible;
        }
    }
</style>
<?php
// Use centralized error handling configured in header.php

// Debug logging function
function debugLog($message, $data = null) {
    $logMessage = date('[Y-m-d H:i:s] ') . $message;
    if ($data !== null) {
        $logMessage .= "\nData: " . print_r($data, true);
    }
    error_log($logMessage, 3, __DIR__ . '/debug.log');
}

// Database connection check
if (!isset($db)) {
    debugLog("Database connection object not found");
    die("Database connection error. Please contact the administrator.");
}

if (!$db->ping()) {
    debugLog("Database connection failed", [
        'error' => $db->error,
        'errno' => $db->errno
    ]);
    die("Database connection error. Please contact the administrator.");
}

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Database configuration
if (!$db->set_charset("utf8mb4")) {
    debugLog("Failed to set database charset", [
        'error' => $db->error,
        'errno' => $db->errno
    ]);
}
// Restore collation after set_charset() resets it to the server default
$db->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

// Error handling function
function handleDatabaseError($error, $context = '') {
    $errorDetails = [
        'message' => $error,
        'context' => $context,
        'timestamp' => date('Y-m-d H:i:s'),
        'file' => __FILE__,
        'line' => __LINE__,
        'session' => isset($_SESSION) ? $_SESSION : null
    ];
    
    debugLog("Database Error", $errorDetails);
    
    return '<div class="alert alert-danger">
                <h5 class="alert-heading">An error occurred</h5>
                <p>We encountered an error while processing your request. Our team has been notified.</p>
                <hr>
                <p class="mb-0">Error Details: ' . htmlspecialchars($context) . '</p>
                <p class="mb-0">Please try again later or contact support if the problem persists.</p>
            </div>';
}

// Input validation
function validateInput($input) {
    if (is_null($input)) {
        return '';
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Normalize the sex value for display. The DB stores ENUM('M','F'); some legacy
// rows may already hold 'Male'/'Female'. Returns a human label and a CSS class.
function formatSex($sex) {
    $s = strtolower(trim((string) $sex));
    if ($s === 'm' || $s === 'male') {
        return ['label' => 'Male', 'class' => 'male'];
    }
    if ($s === 'f' || $s === 'female') {
        return ['label' => 'Female', 'class' => 'female'];
    }
    return ['label' => $s === '' ? 'N/A' : ucfirst($s), 'class' => 'male'];
}

// Prepare the database query with pagination and proper error handling
try {
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

    debugLog("Starting database query", [
        'page' => $page,
        'per_page' => $per_page,
        'offset' => $offset
    ]);

    // Resolve programme by code or catalogue name (online form may store either).
    $query = "SELECT SQL_CALC_FOUND_ROWS
              oa.id,
              oa.Fname,
              oa.Lname,
              oa.email,
              oa.mobile,
              oa.sex,
              oa.program,
              oa.intake,
              oa.mode,
              oa.status,
              oa.results,
              oa.dte_adm,
              COALESCE(p_code.program_name, p_name.program_name, oa.program) AS program_name
              FROM online_applicants oa
              LEFT JOIN programs p_code ON oa.program COLLATE utf8mb4_unicode_ci = p_code.program_code COLLATE utf8mb4_unicode_ci
              LEFT JOIN programs p_name ON oa.program COLLATE utf8mb4_unicode_ci = p_name.program_name COLLATE utf8mb4_unicode_ci
              WHERE oa.status = 'pending'
              ORDER BY oa.dte_adm DESC
              LIMIT ?, ?";

    debugLog("Preparing query", ['query' => $query]);

$stmt = $db->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error, 1002);
    }

$stmt->bind_param("ii", $offset, $per_page);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error, 1003);
    }

    $result = $stmt->get_result();
    if (!$result) {
        throw new Exception("Failed to get result set: " . $stmt->error, 1004);
    }

    debugLog("Query executed successfully", [
        'num_rows' => $result->num_rows
    ]);

    $total_query = $db->query("SELECT FOUND_ROWS()");
    if (!$total_query) {
        throw new Exception("Failed to get total rows: " . $db->error, 1005);
    }

    $total_rows = $total_query->fetch_row()[0];
    $total_pages = ceil($total_rows / $per_page);

    debugLog("Pagination details", [
        'total_rows' => $total_rows,
        'total_pages' => $total_pages
    ]);

} catch (Exception $e) {
    debugLog("Exception caught", [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // More user-friendly error message
    $errorMessage = $e->getMessage();
    $errorCode = $e->getCode();
    
    // Map error codes to user-friendly messages
    $errorMessages = [
        1001 => "Database configuration error: Required table is missing or could not be created",
        1002 => "Database query preparation failed",
        1003 => "Database query execution failed",
        1004 => "Failed to retrieve query results",
        1005 => "Failed to get total record count",
        1054 => "Database structure error: Column mismatch detected"
    ];
    
    $userMessage = $errorMessages[$errorCode] ?? "An unexpected error occurred";
    
    echo handleDatabaseError($userMessage, $errorCode);
    exit;
}
?>

<div class="container-fluid px-4 portal-dashboard applicants-page">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-user-plus me-2 text-primary"></i>Online Applicants</h5>
                <p class="page-subtitle mb-0">Review and process new applications for admission</p>
            </div>
            <div class="header-actions d-flex gap-2">
                <a href="students_by_admin.php" class="btn btn-outline-primary">
                    <i class="fas fa-users me-2"></i>Students
                </a>
                <button class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card h-100">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary me-3 text-white"><i class="fas fa-file-alt"></i></div>
                    <div>
                        <h3 class="mb-0"><?= number_format($total_rows) ?></h3>
                        <p class="text-muted mb-0">Pending Review</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($_SESSION['successMssg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['successMssg']; unset($_SESSION['successMssg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['errorMssg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['errorMssg']; unset($_SESSION['errorMssg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link active" href="#">Applications</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="processedApp.php">Processed Applications</a>
        </li>
    </ul>

    <?php
        if ($result->num_rows > 0) {
            ?>
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Applications
                        </h5>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="applicantsTable" class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Applicant</th>
                                    <th>Contact Details</th>
                                    <th>Program & Intake</th>
                                    <th>Date Applied</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $number = $offset + 1;
                            while ($row = $result->fetch_object()) {
                                // Sanitize output
                                $id = validateInput($row->id);
                                $name = validateInput($row->Fname . ' ' . $row->Lname);
                                $email = validateInput($row->email);
                                $program_name = validateInput($row->program_name ?? $row->program);
                                $intake_name = validateInput($row->intake);
                                $sex = formatSex($row->sex);
                                ?>
                                <tr>
                                    <td class="text-center fw-bold text-muted"><?= $number++ ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-circle me-3">
                                                <?= strtoupper(substr($row->Fname, 0, 1) . substr($row->Lname, 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?= $name ?></div>
                                                <span class="gender-pill <?= $sex['class'] ?>">
                                                    <?= validateInput($sex['label']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div class="mb-1"><i class="fas fa-envelope text-muted me-2" style="width:14px;"></i><?= $email ?></div>
                                            <div><i class="fas fa-phone text-muted me-2" style="width:14px;"></i><?= validateInput($row->mobile) ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <div class="program-badge mb-1"><?= $program_name ?></div>
                                            <div class="text-muted">
                                                <i class="far fa-calendar-alt me-1"></i> <?= $intake_name ?>
                                                <span class="ms-2 px-2 py-0 bg-light border rounded"><?= validateInput($row->mode) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="small text-muted">
                                            <?= $row->dte_adm ? date('M d, Y', strtotime($row->dte_adm)) : 'N/A' ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">
                                            <?php if(!empty($row->results)):
                                                $safe_results_path = validateInput(basename($row->results));
                                            ?>
                                                <a href="../online_services/uploads/<?= $safe_results_path ?>"
                                                   class="btn btn-sm btn-outline-info"
                                                   target="_blank"
                                                   rel="noopener noreferrer"
                                                   data-bs-toggle="tooltip"
                                                   title="View Results">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            <?php endif; ?>

                                            <form method="post" action="acceptApplicant.php" style="display:inline;">
                                                <input type="hidden" name="mov" value="<?= $id ?>">
                                                <input type="hidden" name="token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <button type="submit"
                                                        class="btn btn-success btn-sm"
                                                        data-bs-toggle="tooltip"
                                                        title="Accept Application"
                                                        aria-label="Accept application for <?= $name ?>"
                                                        onclick="return confirm('Are you sure you want to accept this application?')">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>

                                            <form method="post" action="rejectApplicant.php" style="display:inline;">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <button type="submit"
                                                        class="btn btn-danger btn-sm"
                                                        aria-label="Reject application for <?= $name ?>"
                                                        title="Reject Application"
                                                        onclick="return confirm('Reject this application and move it to processed applications?')">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php
        } else {
            echo '<div class="alert alert-info">No applications found.</div>';
        }

        // Clean up
        $result->free();
        $stmt->close();
    ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize DataTables with configuration
    $('#applicantsTable').DataTable({
        pageLength: 25,
        responsive: true,
        language: {
            search: "Search applications:",
            lengthMenu: "Show _MENU_ applications per page",
        },
        columnDefs: [
            { orderable: false, targets: -1 } // Disable sorting on action column
        ]
    });

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php require_once "includes/footer.php"; ?>
