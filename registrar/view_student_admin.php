<?php
include "includes/admin.php";
error_reporting(0);

// Fetch the student profile with prepared statements. The old version joined
// the nonexistent program_levels table (fatal) and interpolated $_GET['view']
// straight into SQL.
$student = null;
$program_info = null;

$sid = trim((string)($_GET['view'] ?? ''));
if ($sid !== '') {
    if ($stmt = $db->prepare("SELECT * FROM students WHERE SID = ? LIMIT 1")) {
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $student = $res->fetch_object();
        }
        $stmt->close();
    }

    if ($student) {
        $stmt = $db->prepare("
            SELECT sp.*, p.program_name, p.program_duration
            FROM student_program sp
            LEFT JOIN programs p ON sp.program_code = p.program_code
            WHERE sp.Sid = ?
            ORDER BY sp.id DESC LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $program_info = $res->fetch_object();
            }
            $stmt->close();
        }
    }
}

function reg_vs(string $v = null): string {
    $v = trim((string)$v);
    return $v !== '' ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Student Profile — Registrar</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="UTF-8">
</head>
<body class="bg-light">
<div class="container-fluid px-3 px-lg-4 py-4">
<?php if (!$student): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-4 d-flex align-items-center gap-3 p-4">
        <i class="fas fa-circle-exclamation fa-2x"></i>
        <div>
            <h5 class="alert-heading fw-bold mb-1">Student Not Found</h5>
            <p class="mb-2">The student record you are looking for does not exist or has been removed.</p>
            <a href="students_by_admin.php" class="btn btn-outline-danger btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Return to Student List
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="fas fa-user-graduate me-2 text-primary"></i>Student Details</h1>
            <p class="text-muted mb-0">Academic and personal profile for <?php echo reg_vs($student->Fname . ' ' . $student->Lname); ?></p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-secondary">
                <i class="fas fa-print me-2"></i>Print
            </button>
            <a href="students_by_admin.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back
            </a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body text-center py-4">
                    <?php
                        $img_path = "../uploads/profile/" . (string)$student->profile_image;
                        $abs_img_path = __DIR__ . "/../uploads/profile/" . (string)$student->profile_image;
                        if (empty($student->profile_image) || !file_exists($abs_img_path)) {
                            $img_path = "../images/avatar.png";
                        }
                    ?>
                    <img src="<?php echo htmlspecialchars($img_path, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile"
                         class="mb-3"
                         style="width:130px;height:130px;object-fit:cover;border-radius:50%;border:5px solid #f8f9fa;box-shadow:0 5px 15px rgba(0,0,0,0.08);">
                    <h4 class="fw-bold mb-1"><?php echo reg_vs($student->title . ' ' . $student->Fname . ' ' . $student->Lname); ?></h4>
                    <p class="text-primary fw-bold small mb-3"><?php echo reg_vs($student->SID); ?></p>
                    <div class="d-flex justify-content-center gap-2">
                        <span class="badge bg-primary rounded-pill px-3"><?php echo reg_vs($student->status); ?></span>
                        <?php if (!empty($program_info->mode)): ?>
                            <span class="badge bg-info text-dark rounded-pill px-3"><?php echo reg_vs($program_info->mode); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold mb-0"><i class="fas fa-user me-2 text-primary"></i>Personal Information</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Gender</label>
                            <span class="fw-medium"><?php echo $student->sex === 'M' ? 'Male' : ($student->sex === 'F' ? 'Female' : reg_vs($student->sex)); ?></span>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Date of Birth</label>
                            <span class="fw-medium"><?php echo reg_vs($student->dob); ?></span>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">NRC / Passport</label>
                            <span class="fw-medium"><?php echo reg_vs($student->nrc_pass); ?></span>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Country</label>
                            <span class="fw-medium"><?php echo reg_vs($student->country); ?></span>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Mobile</label>
                            <span class="fw-medium"><?php echo reg_vs($student->mobile); ?></span>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Email</label>
                            <span class="fw-medium text-primary"><?php echo reg_vs($student->email); ?></span>
                        </div>
                        <div class="col-md-8">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Home Address</label>
                            <span class="fw-medium"><?php echo reg_vs($student->h_addre); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                            <h5 class="fw-bold mb-0"><i class="fas fa-id-card-alt me-2 text-primary"></i>Next of Kin</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="mb-3">
                                <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Name</label>
                                <span class="fw-medium"><?php echo reg_vs($student->next_kin); ?></span>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Relationship</label>
                                <span class="fw-medium"><?php echo reg_vs($student->relat); ?></span>
                            </div>
                            <div>
                                <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Contact</label>
                                <span class="fw-medium"><?php echo reg_vs($student->next_kin_mobile); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                            <h5 class="fw-bold mb-0"><i class="fas fa-graduation-cap me-2 text-primary"></i>Programme</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="mb-3">
                                <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Programme of Study</label>
                                <span class="fw-bold text-success"><?php echo reg_vs($program_info->program_name ?? 'Unassigned'); ?></span>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Intake</label>
                                <span class="fw-medium"><?php echo reg_vs($program_info->intake ?? ($student->intake ?? '')); ?></span>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Commencement</label>
                                <span class="fw-medium"><?php echo reg_vs($program_info->startYear ?? ''); ?></span>
                            </div>
                            <div>
                                <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Duration</label>
                                <span class="fw-medium"><?php echo reg_vs($program_info->program_duration ?? ''); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
</body>
</html>
<?php require_once "includes/footer.php"; ?>
