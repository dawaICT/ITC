<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';

// Ensure DB is available
if (!isset($db) || !$db) {
    require_once "../db/connect.php";
}

// Security: Ensure user is logged in. Not-logged-in is an authentication
// failure, so send to the staff login — not '../index.php' (the student portal).
if (!isset($_SESSION['staff_id']) && !isset($_SESSION['user_id'])) {
    header("Location: /wucportal/staff_login.php");
    exit();
}

if (function_exists('canEnterExamMarks') && !canEnterExamMarks()) {
    $_SESSION['errorMsg'] = 'Access denied. You do not have permission to publish exam results.';
    header('Location: index.php');
    exit();
}

// Generate CSRF Token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_submit'])) {
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['errorMsg'] = 'Security validation failed (CSRF). Please try again.';
        header('Location: finalExams.php');
        exit();
    }

    // Basic sanitization
    $status      = $_POST['status'] ?? '';
    $semester    = $_POST['publish_semester'] ?? '';
    $year        = $_POST['publish_year'] ?? '';
    $dte_publish = $_POST['dte_publish'] ?? '';

    if ($status !== '' && $semester !== '' && $year !== '' && $dte_publish !== '') {
        // Check for existing record to Upsert
        $checkSql = "SELECT id FROM publish_results WHERE semester = ? AND year = ?";
        if ($checkStmt = $db->prepare($checkSql)) {
            $checkStmt->bind_param("is", $semester, $year);
            $checkStmt->execute();
            $checkStmt->store_result();
            
            if ($checkStmt->num_rows > 0) {
                // UPDATE existing record
                $sql = "UPDATE publish_results SET status = ?, dte_publish = ?, dte = NOW() WHERE semester = ? AND year = ?";
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param('isis', $status, $dte_publish, $semester, $year);
                    if ($stmt->execute()) {
                        $_SESSION['successMsg'] = 'Publishing date updated successfully.';
                    } else {
                        $_SESSION['errorMsg'] = 'Database error during update: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            } else {
                // INSERT new record
                $sql = "INSERT INTO publish_results (status, semester, year, dte_publish, dte) VALUES (?, ?, ?, ?, NOW())";
                if ($stmt = $db->prepare($sql)) {
                    $stmt->bind_param('iiss', $status, $semester, $year, $dte_publish);
                    if ($stmt->execute()) {
                        $_SESSION['successMsg'] = 'Publishing date set successfully.';
                    } else {
                        $_SESSION['errorMsg'] = 'Database error during insert: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            $checkStmt->close();
        } else {
            $_SESSION['errorMsg'] = 'Database error: Could not prepare check statement.';
        }
    } else {
        $_SESSION['errorMsg'] = 'All fields are required.';
    }

    header('Location: finalExams.php');
    exit();
}

// Determine if this file is accessed directly vs included
$isDirect = (basename($_SERVER['PHP_SELF']) === basename(__FILE__));
if ($isDirect) {
    require_once 'includes/header.php';
}
?>

<?php if ($isDirect): ?>
<div class="container-fluid px-4 portal-dashboard">
    <div class="page-header mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-paper-plane me-2 text-primary"></i>Publish Final Results</h5>
                <p class="page-subtitle mb-0">Configure when exam results become visible to students.</p>
            </div>
            <a href="finalExams.php" class="btn btn-primary">
                <i class="fas fa-arrow-left me-1"></i>Back to Final Exams
            </a>
        </div>
    </div>

    <div class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-cog me-2"></i>Publishing Configuration</h5>
        </div>
        <div class="card-body">
            <form action="publishResults.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="mb-4">
                    <label class="form-label fw-semibold">Publishing Status</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-toggle-on text-muted"></i></span>
                        <select class="form-select" name="status" required>
                            <option value="" disabled selected>Select Status</option>
                            <option value="1">Live (Visible to Students)</option>
                            <option value="0">Draft (Hidden)</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Semester</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="fas fa-university text-muted"></i></span>
                            <select class="form-select" name="publish_semester" required>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                                <option value="3">Term 3</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Year</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="fas fa-calendar-alt text-muted"></i></span>
                            <input type="number" class="form-control" name="publish_year" min="2020" max="2099"
                                value="<?php echo date('Y'); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Publication Date</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-clock text-muted"></i></span>
                        <input type="date" class="form-control" name="dte_publish" required>
                    </div>
                </div>

                <button class="btn btn-primary" type="submit" name="publish_submit">
                    <i class="fas fa-save me-2"></i>Save & Publish
                </button>
            </form>
        </div>
    </div>
</div>
<?php else: ?>
<div id="PublishExams" class="modal fade" tabindex="-1" aria-labelledby="publishExamsLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header admin-modal">
                <h5 class="modal-title" id="publishExamsLabel">
                    <i class="fas fa-paper-plane me-2"></i>Publish Final Results
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-4">Configure when exam results become visible to students.</p>

                <form action="publishResults.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Publishing Status</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="fas fa-toggle-on text-muted"></i></span>
                            <select class="form-select" name="status" required>
                                <option value="" disabled selected>Select Status</option>
                                <option value="1">Live (Visible to Students)</option>
                                <option value="0">Draft (Hidden)</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Semester</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-university text-muted"></i></span>
                                <select class="form-select" name="publish_semester" required>
                                    <option value="1">Semester 1</option>
                                    <option value="2">Semester 2</option>
                                    <option value="3">Term 3</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Year</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-calendar-alt text-muted"></i></span>
                                <input type="number" class="form-control" name="publish_year" min="2020" max="2099"
                                    value="<?php echo date('Y'); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Publication Date</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="fas fa-clock text-muted"></i></span>
                            <input type="date" class="form-control" name="dte_publish" required>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button class="btn btn-primary" type="submit" name="publish_submit">
                            <i class="fas fa-save me-2"></i>Save & Publish
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isDirect) require_once 'includes/footer.php'; ?>

