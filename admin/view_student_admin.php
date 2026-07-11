<?php
/**
 * Modernized Student Information Page
 * Consistent with Admin Dashboard Styling
 */
$page_title = "Student Profile";
require_once "includes/admin.php";
require_once "includes/header.php";
// Initialize variables
$student = null;
$program_info = null;
$fee_info = null;

if (isset($_GET['view'])) {
    $sid = $db->real_escape_string($_GET['view']);
    
    // 1. Fetch main student record
    $sql = "SELECT * FROM students WHERE SID = '$sid'";
    $result = $db->query($sql);
    if ($result && $result->num_rows > 0) {
        $student = $result->fetch_object();
    }
    
    // 2. Fetch academic program info
    if ($student) {
        $sql = "SELECT sp.*, p.program_name 
                FROM student_program sp 
                LEFT JOIN programs p ON sp.program_code = p.program_code 
                WHERE sp.Sid = '$sid' LIMIT 1";
        $prog_result = $db->query($sql);
        if ($prog_result && $prog_result->num_rows > 0) {
            $program_info = $prog_result->fetch_object();
        }
        
        // 3. Fetch fee structure
        if ($program_info) {
            $sql = "SELECT * FROM fee_structure WHERE program_code = '" . $db->real_escape_string($program_info->program_code) . "' LIMIT 1";
            $fee_result = $db->query($sql);
            if ($fee_result && $fee_result->num_rows > 0) {
                $fee_info = $fee_result->fetch_object();
            }
        }
    }
}

if (!$student):
?>
<div class="container-fluid px-4 portal-dashboard py-5">
    <div class="alert alert-danger shadow-sm border-0 rounded-4 p-4 d-flex align-items-center">
        <i class="fas fa-exclamation-circle fa-2x me-3"></i>
        <div>
            <h5 class="alert-heading mb-1">Student Not Found</h5>
            <p class="mb-0">The student record you are looking for does not exist or has been removed.</p>
            <a href="students_by_admin.php" class="btn btn-outline-danger mt-3 btn-sm">Return to Student List</a>
        </div>
    </div>
</div>
<?php
else:
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-user-graduate me-2"></i>Student Details</h1>
                <p class="text-muted mb-0">Detailed academic and personal profile for <?php echo htmlspecialchars($student->Fname . ' ' . $student->Lname); ?></p>
            </div>
            <div class="col-auto">
                <div class="header-actions d-flex gap-2">
                    <a href="editStudent.php?update=<?php echo urlencode($student->SID); ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Edit Record
                    </a>
                    <button onclick="window.print()" class="btn btn-outline-secondary">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                    <a href="students_by_admin.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Profile Column -->
        <div class="col-xl-3">
            <div class="data-table-card mb-4">
                <div class="card-body text-center py-4">
                    <div class="position-relative d-inline-block mb-3">
                        <?php 
                            $img_path = "../uploads/profile/" . $student->profile_image;
                            $abs_img_path = dirname(__FILE__) . "/../uploads/profile/" . $student->profile_image;
                            if (empty($student->profile_image) || !file_exists($abs_img_path)) {
                                $img_path = "images/avatar.png"; 
                            }
                        ?>
                        <img src="<?php echo $img_path; ?>" alt="Profile" 
                             style="width: 140px; height: 140px; object-fit: cover; border-radius: 50%; border: 5px solid #f8f9fa; box-shadow: 0 5px 15px rgba(0,0,0,0.08);">
                        <span class="position-absolute bottom-0 end-0 bg-success border border-white rounded-circle p-2" title="Active"></span>
                    </div>
                    <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($student->Fname . ' ' . $student->Lname); ?></h4>
                    <p class="text-primary fw-bold small mb-3"><?php echo htmlspecialchars($student->SID); ?></p>
                    
                    <div class="d-flex justify-content-center gap-2 mb-0">
                        <span class="badge bg-primary rounded-pill px-3"><?php echo htmlspecialchars($student->status); ?></span>
                        <span class="badge bg-info text-dark rounded-pill px-3"><?php echo htmlspecialchars($program_info->mode ?? 'Semester'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="quick-actions-card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <a href="ca.php?view=<?php echo urlencode($student->SID); ?>" class="list-group-item list-group-item-action py-3">
                            <i class="fas fa-file-invoice text-info me-3"></i>Assessments Transcript
                        </a>
                        <a href="exam_registration_admin.php?sid=<?php echo urlencode($student->SID); ?>" class="list-group-item list-group-item-action py-3">
                            <i class="fas fa-user-edit text-warning me-3"></i>Exam Registration
                        </a>
                        <a href="studentPayments.php?sid=<?php echo urlencode($student->SID); ?>" class="list-group-item list-group-item-action py-3">
                            <i class="fas fa-credit-card text-success me-3"></i>Financial Record
                        </a>
                        <a href="resetPassword.php?sid=<?php echo urlencode($student->SID); ?>" class="list-group-item list-group-item-action py-3">
                            <i class="fas fa-key text-danger me-3"></i>Reset Password
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Details Column -->
        <div class="col-xl-9">
            <!-- Information Grid -->
            <div class="row g-3">
                <div class="col-lg-12">
                    <div class="data-table-card">
                        <div class="card-header">
                            <h5><i class="fas fa-user me-2"></i>Personal & Academic Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="text-muted small text-uppercase fw-bold mb-1">Full Name</label>
                                    <div class="fw-medium"><?php echo htmlspecialchars($student->title . ' ' . $student->Fname . ' ' . $student->Lname); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="text-muted small text-uppercase fw-bold mb-1">Gender</label>
                                    <div class="fw-medium"><?php echo htmlspecialchars($student->sex == 'M' ? 'Male' : 'Female'); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="text-muted small text-uppercase fw-bold mb-1">NRC / Passport</label>
                                    <div class="fw-medium"><?php echo htmlspecialchars($student->nrc_pass); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="text-muted small text-uppercase fw-bold mb-1">Email Address</label>
                                    <div class="fw-medium text-primary"><?php echo htmlspecialchars($student->email); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="text-muted small text-uppercase fw-bold mb-1">Phone Number</label>
                                    <div class="fw-medium"><?php echo htmlspecialchars($student->mobile); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="text-muted small text-uppercase fw-bold mb-1">Nationality</label>
                                    <div class="fw-medium"><?php echo htmlspecialchars($student->country ?? 'Not Specified'); ?></div>
                                </div>
                                <div class="col-md-8">
                                    <label class="text-muted small text-uppercase fw-bold mb-1">Home Address</label>
                                    <div class="fw-medium"><?php echo htmlspecialchars($student->h_addre ?? 'N/A'); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="text-muted small text-uppercase fw-bold mb-1">Program of Study</label>
                                    <div class="fw-bold text-success"><?php echo htmlspecialchars($program_info->program_name ?? 'Unassigned'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="data-table-card h-100">
                        <div class="card-header">
                            <h5><i class="fas fa-id-card-alt me-2"></i>Emergency Contact</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="text-muted small text-uppercase fw-bold mb-1">Next of Kin Name</label>
                                <div class="fw-medium"><?php echo htmlspecialchars($student->next_kin ?? 'N/A'); ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small text-uppercase fw-bold mb-1">Relationship</label>
                                <div class="fw-medium"><?php echo htmlspecialchars($student->relat ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <label class="text-muted small text-uppercase fw-bold mb-1">Contact Number</label>
                                <div class="fw-medium"><?php echo htmlspecialchars($student->next_kin_mobile ?? 'N/A'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="data-table-card h-100">
                        <div class="card-header">
                            <h5><i class="fas fa-money-check-alt me-2"></i>Financial Status</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="text-muted small text-uppercase fw-bold mb-1">Base Tuition Fee</label>
                                <div class="fw-bold h5 mb-0"><?php echo isset($fee_info->amount) ? "K " . number_format($fee_info->amount, 2) : 'Structure Not Defined'; ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="text-muted small text-uppercase fw-bold mb-1">Bursary / Scholarship</label>
                                <div><span class="badge bg-warning text-dark"><?php echo htmlspecialchars($student->bursary_percentage ?? '0'); ?>% Coverage</span></div>
                            </div>
                            <?php if ($student->sponsor): ?>
                            <div>
                                <label class="text-muted small text-uppercase fw-bold mb-1">Sponsor</label>
                                <div class="fw-medium"><?php echo htmlspecialchars($student->sponsor); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Academic History (Exams) -->
                <div class="col-12">
                    <div class="data-table-card">
                        <div class="card-header">
                            <h5><i class="fas fa-graduation-cap me-2"></i>Academic Track Record</h5>
                        </div>
                        <div class="card-body p-0">
                            <?php 
                                // Set variables needed by exams_series.php if not already set
                                $_GET['view'] = $student->SID;
                                include "exams_series.php"; 
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
endif;
require_once "includes/footer.php";
?>


