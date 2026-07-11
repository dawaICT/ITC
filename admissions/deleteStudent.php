<?php
require "includes/nav.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
$csrfToken = wuc_csrf_token();

// Validate and sanitize input using prepared statements
$sid = isset($_GET['sid']) ? trim($_GET['sid']) : '';
$confirm = isset($_GET['confirm']) ? 1 : 0;

if (empty($sid)) {
    $_SESSION['errorMessage'] = "No student ID provided.";
    header('Location:regOldStud.php');
    die();
}

// Validate student ID format
if (!preg_match('/^[A-Za-z0-9]+$/', $sid)) {
    $_SESSION['errorMessage'] = "Invalid student ID format.";
    header('Location:regOldStud.php');
    die();
}

// Fetch student data using prepared statement
$student = null;
$stmt = $db->prepare("SELECT SID, Fname, Lname, nrc_pass FROM students WHERE SID = ?");
if ($stmt) {
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
    }
    $stmt->close();
}

if (!$student) {
    $_SESSION['errorMessage'] = "Student not found.";
    header('Location:regOldStud.php');
    die();
}

// Handle deletion if confirmed (using prepared statement)
if ($confirm == 1 && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid request.');
    }
    // First delete from related tables
    $delete_program = $db->prepare("DELETE FROM student_program WHERE Sid = ?");
    if ($delete_program) {
        $delete_program->bind_param('s', $sid);
        $delete_program->execute();
        $delete_program->close();
    }
    
    // Then delete the student record
    $delete_stmt = $db->prepare("DELETE FROM students WHERE SID = ?");
    if ($delete_stmt) {
        $delete_stmt->bind_param('s', $sid);
        if ($delete_stmt->execute()) {
            $_SESSION['successMessage'] = "Student record deleted successfully!";
            header('Location:regOldStud.php');
            die();
        } else {
            $_SESSION['errorMessage'] = "Error deleting student: " . $delete_stmt->error;
        }
        $delete_stmt->close();
    } else {
        $_SESSION['errorMessage'] = "Database error occurred.";
    }
}
?>

<div class="container-fluid px-4 mt-4">
    <div class="row">
        <div class="col-lg-6 offset-lg-3">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>Delete Student Record
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($confirm != 1): ?>
                        <div class="alert alert-warning mb-4">
                            <i class="fas fa-warning me-2"></i>
                            <strong>Warning:</strong> This action will permanently delete the student record and cannot be undone.
                        </div>

                        <div class="bg-light p-3 rounded mb-4">
                            <h6 class="mb-3">Student to be deleted:</h6>
                            <div class="row mb-2">
                                <div class="col-md-4"><strong>Student ID:</strong></div>
                                <div class="col-md-8"><?php echo htmlspecialchars($student['SID']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-4"><strong>Full Name:</strong></div>
                                <div class="col-md-8"><?php echo htmlspecialchars($student['Fname'] . ' ' . $student['Lname']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-4"><strong>NRC/Passport:</strong></div>
                                <div class="col-md-8"><?php echo htmlspecialchars($student['nrc_pass']); ?></div>
                            </div>
                        </div>

                        <p class="text-muted mb-4">
                            Click "Confirm Delete" below to proceed with deletion, or click "Cancel" to return to the transfer students list.
                        </p>

                        <div class="d-flex gap-2 justify-content-center">
                            <a href="regOldStud.php" class="btn btn-secondary px-4">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <a href="deleteStudent.php?sid=<?php echo htmlspecialchars($sid); ?>&confirm=1" class="btn btn-danger px-4">
                                <i class="fas fa-trash me-2"></i>Confirm Delete
                            </a>
                        </div>

                    <?php else: ?>
                        <div class="text-center">
                            <h5 class="mb-4">Are you absolutely sure?</h5>
                            <p class="text-muted mb-4">
                                This will permanently delete all data associated with this student.<br>
                                <strong><?php echo htmlspecialchars($student['Fname'] . ' ' . $student['Lname'] . ' (' . $student['SID'] . ')'); ?></strong>
                            </p>

                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="btn btn-danger btn-lg me-3" onclick="return confirm('This action cannot be undone. Delete anyway?')">
                                    <i class="fas fa-check me-2"></i>Yes, Delete Permanently
                                </button>
                            </form>
                            <a href="regOldStud.php" class="btn btn-secondary btn-lg">
                                <i class="fas fa-times me-2"></i>No, Cancel
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
