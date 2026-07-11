<?php
require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
require "includes/nav.php";

// Check session timeout
if (!checkSessionTimeout(30)) {
    setFlashMessage('error', 'Your session has expired. Please log in again.');
    header('Location: regOldStud.php');
    exit;
}

// Validate and sanitize input
$sid = isset($_GET['sid']) ? trim($_GET['sid']) : '';

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
$stmt = $db->prepare("SELECT s.*, COUNT(sp.Sid) as program_count FROM students s 
          LEFT JOIN student_program sp ON s.SID = sp.Sid
          WHERE s.SID = ?
          GROUP BY s.SID");
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
?>

<div class="container-fluid px-4 py-4 portal-dashboard">
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Student Details</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="text-muted">Student ID</h6>
                            <p class="h5"><strong><?php echo htmlspecialchars($student['SID']); ?></strong></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Full Name</h6>
                            <p class="h5"><strong><?php echo htmlspecialchars($student['Fname'] . ' ' . $student['Lname']); ?></strong></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">NRC/Passport</h6>
                            <p><?php echo htmlspecialchars($student['nrc_pass']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Date of Birth</h6>
                            <p><?php echo date('M d, Y', strtotime($student['dob'])); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Mobile</h6>
                            <p><?php echo htmlspecialchars($student['mobile']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Email</h6>
                            <p><?php echo htmlspecialchars($student['email'] ?: 'N/A'); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Previous Institution</h6>
                            <p><?php echo htmlspecialchars($student['school']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Transfer Credits</h6>
                            <p><span class="badge bg-primary"><?php echo $student['transfer_credits']; ?></span></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Next of Kin</h6>
                            <p><?php echo htmlspecialchars($student['next_kin']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Status</h6>
                            <p>
                                <?php if ($student['program_count'] > 0): ?>
                                    <span class="badge bg-success">Admitted</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-12">
                            <h6 class="text-muted">Registration Date</h6>
                            <p><?php echo date('M d, Y H:i', strtotime($student['dte_adm'])); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <button class="btn btn-secondary" onclick="window.history.back()">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </button>
                    <button class="btn btn-warning" onclick="window.location.href='editStudent.php?sid=<?php echo $student['SID']; ?>'">
                        <i class="fas fa-edit me-2"></i>Edit
                    </button>
                    <?php if ($student['program_count'] == 0): ?>
                    <button class="btn btn-success" onclick="window.location.href='admitStudent.php?sid=<?php echo $student['SID']; ?>'">
                        <i class="fas fa-check me-2"></i>Admit
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <?php if (!empty($student['profile_image'])): ?>
            <div class="card">
                <div class="card-header bg-light">
                    <h6 class="mb-0">Profile Picture</h6>
                </div>
                <div class="card-body text-center">
                    <img src="../uploads/profile/<?php echo htmlspecialchars($student['profile_image']); ?>" 
                         class="img-fluid rounded" style="max-width: 250px; max-height: 250px;">
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
