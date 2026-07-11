<?php
$page_title = "View Student";
require_once 'includes/header.php';
require_once "includes/admin.php";
error_reporting(0);

// Accept both ?id= (used by the shared registration panel) and the legacy ?view=.
$sid = $_GET['view'] ?? $_GET['id'] ?? null;
$student = null;
if($sid !== null && trim((string)$sid) !== ''){
    $sidEsc = $db->real_escape_string(trim((string)$sid));
    $query = "SELECT s.*, 
                     COALESCE(sp.intake, s.intake) as intake,
                     sp.term,
                     COALESCE(sp.program_code, s.program) as student_program_code,
                     COALESCE(p.program_name, sc.course_name) as program_name,
                     COALESCE(p.program_code, sc.course_code) as program_code,
                     p.study_mode
              FROM students s
              LEFT JOIN student_program sp ON s.SID = sp.Sid
              LEFT JOIN programs p ON COALESCE(sp.program_code, s.program) = p.program_code
              LEFT JOIN short_courses sc ON COALESCE(sp.program_code, s.program) = sc.course_code
              WHERE s.SID = '$sidEsc'";
    $result = $db->query($query);
    $student = $result ? $result->fetch_object() : null;
}

// Graceful handling when no ID is supplied, or the student/program isn't found.
// The INNER JOINs above yield no row for a student that has no program record,
// so without this guard the page renders blank with htmlspecialchars(null) calls.
if (empty($student)) {
    $requested = htmlspecialchars((string)($sid ?? ''), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="container-fluid px-4 portal-dashboard">
        <div class="alert alert-warning d-flex align-items-center gap-2 mt-4">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <?php if ($requested !== ''): ?>
                    No student record found for ID <strong><?php echo $requested; ?></strong>. The student may not exist, or may not yet have a program assigned.
                <?php else: ?>
                    No student ID was provided.
                <?php endif; ?>
                &nbsp;<a href="students_by_admin.php" class="alert-link">Back to students</a>
            </div>
        </div>
    </div>
    <?php
    require 'includes/footer.php';
    exit;
}

// Records store gender as M / F — present a friendly label.
$genderLabel = ['M' => 'Male', 'm' => 'Male', 'F' => 'Female', 'f' => 'Female'][$student->sex ?? ''] ?? ($student->sex ?: 'Not specified');
?>

<style>
    .profile-card {
        text-align: center;
        padding: 2rem;
    }
    .profile-image-wrapper {
        position: relative;
        width: 15rem;
        height: 15rem;
        margin: 0 auto 1.5rem;
    }
    .profile-image-main {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
        border: 5px solid #fff;
        box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.1);
    }
    .info-label {
        color: #64748b;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }
    .info-value {
        color: #1e293b;
        font-weight: 500;
        font-size: 1rem;
    }
    .info-item {
        padding: 1rem;
        border-radius: 0.5rem;
        background: #f8fafc;
        height: 100%;
        border: 1px solid #f1f5f9;
        transition: all 0.2s ease;
    }
    .info-item:hover {
        background: #fff;
        border-color: #e2e8f0;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    .section-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
    }
    .section-title i {
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f1f5f9;
        border-radius: 0.5rem;
        margin-right: 0.75rem;
        color: #64748b;
        font-size: 0.9rem;
    }
    .bg-blue-soft { background-color: #e0f2fe; color: #0ea5e9; }
    .bg-purple-soft { background-color: #f3e8ff; color: #a855f7; }
    .bg-green-soft { background-color: #dcfce7; color: #22c55e; }
    .bg-success-soft { background-color: #dcfce7; color: #166534; }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-user-circle me-2 text-primary"></i>Student Profile - <?php echo htmlspecialchars($student->SID); ?></h5>
                <p class="page-subtitle mb-0">Complete registry record for institutional management</p>
            </div>
            <div class="header-actions">
                <div class="btn-group shadow-sm">
                    <a href="students_by_admin.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </a>
                    <a href="editStudent.php?update=<?php echo urlencode($student->SID)?>" class="btn btn-primary">
                        <i class="fas fa-edit me-1"></i>Modify Profile
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Profile Sidebar Card -->
        <div class="col-xl-3 col-lg-4">
            <div class="data-table-card profile-card h-100">
                <div class="profile-image-wrapper">
                    <img src="<?php echo !empty($student->profile_image) ? '../uploads/profile/' . htmlspecialchars($student->profile_image) : '/wucportal/images/avatar.png'; ?>"
                         alt="Profile" class="profile-image-main" onerror="this.onerror=null;this.src='/wucportal/images/avatar.png'">
                </div>
                <h3 class="mb-1"><?php echo htmlspecialchars($student->Fname . ' ' . $student->Lname); ?></h3>
                <p class="text-primary fw-600 mb-3"><?php echo htmlspecialchars($student->SID); ?></p>
                <div class="d-flex justify-content-center gap-2 mb-4">
                    <span class="badge bg-light text-dark border px-3 py-2 rounded-pill">
                        <i class="fas fa-venus-mars me-1 text-muted"></i> <?php echo htmlspecialchars($genderLabel); ?>
                    </span>
                </div>
                
                <hr class="my-4 opacity-10">
                
                <div class="text-start">
                    <div class="mb-3">
                        <div class="info-label">Current Program</div>
                        <div class="info-value text-primary"><?php echo htmlspecialchars($student->program_name); ?></div>
                    </div>
                    <div class="mb-0">
                        <div class="info-label">Status</div>
                        <div><span class="badge bg-success-soft text-success px-3 py-2">Active</span></div>
                    </div>
                </div>
                
                <div class="mt-4 pt-4 border-top">
                    <button onclick="window.print()" class="btn btn-light w-100">
                        <i class="fas fa-print me-2"></i>Print Registry Card
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-xl-9 col-lg-8">
            <!-- Summary Stats -->
            <!-- Summary Stats Row -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-card p-3 border-0 bg-white">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-blue-soft text-blue me-3"><i class="fas fa-graduation-cap"></i></div>
                            <div>
                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($student->mode); ?></h6>
                                <p class="text-muted small mb-0">Study Mode</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card p-3 border-0 bg-white">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-purple-soft text-purple me-3"><i class="fas fa-calendar-alt"></i></div>
                            <div>
                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($student->intake); ?></h6>
                                <p class="text-muted small mb-0">Intake Year</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card p-3 border-0 bg-white">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-green-soft text-green me-3"><i class="fas fa-check-circle"></i></div>
                            <div>
                                <h6 class="mb-0 fw-bold">Active</h6>
                                <p class="text-muted small mb-0">Current Status</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Details Card -->
            <div class="data-table-card">
                <div class="card-header bg-white border-bottom py-3">
                    <ul class="nav nav-tabs card-header-tabs" id="profileTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="personal-tab" data-bs-toggle="tab" href="#personal" role="tab">Personal Information</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="contact-tab" data-bs-toggle="tab" href="#contact" role="tab">Contact Details</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="documents-tab" data-bs-toggle="tab" href="#documents" role="tab">Documents</a>
                        </li>
                    </ul>
                </div>
                <div class="card-body p-4">
                    <div class="tab-content" id="profileTabsContent">
                        <!-- Personal Info Tab -->
                        <div class="tab-pane fade show active" id="personal" role="tabpanel">
                            <h5 class="section-title"><i class="fas fa-user"></i> Identity Record</h5>
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">First Name</div>
                                        <div class="info-value"><?php echo htmlspecialchars($student->Fname); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">Last Name</div>
                                        <div class="info-value"><?php echo htmlspecialchars($student->Lname); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">Gender</div>
                                        <div class="info-value"><?php echo htmlspecialchars($genderLabel); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">Date of Birth</div>
                                        <div class="info-value"><?php echo htmlspecialchars($student->dob); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Details Tab -->
                        <div class="tab-pane fade" id="contact" role="tabpanel">
                            <h5 class="section-title"><i class="fas fa-address-book"></i> Communication</h5>
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">Email Address</div>
                                        <div class="info-value text-primary"><?php echo htmlspecialchars($student->email ?: '—'); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">Phone Number</div>
                                        <div class="info-value"><?php echo htmlspecialchars($student->mobile ?: '—'); ?></div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="info-item">
                                        <div class="info-label">Residential Address</div>
                                        <div class="info-value"><?php echo htmlspecialchars($student->h_addre ?: '—'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Documents Tab -->
                        <div class="tab-pane fade" id="documents" role="tabpanel">
                            <h5 class="section-title"><i class="fas fa-folder-open"></i> Uploaded Documents</h5>
                            <div class="row g-4">
                                <div class="col-md-4">
                                    <div class="info-item text-center">
                                        <i class="fas fa-id-card fa-3x text-primary mb-3"></i>
                                        <div class="info-label">NRC Copy</div>
                                        <?php if (!empty($student->nrc_file)): ?>
                                            <a href="view_document.php?type=nrc&file=<?php echo urlencode($student->nrc_file); ?>" 
                                               target="_blank" class="btn btn-sm btn-primary mt-2">
                                                <i class="fas fa-eye me-1"></i>View
                                            </a>
                                        <?php else: ?>
                                            <div class="text-muted small mt-2">Not uploaded</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-item text-center">
                                        <i class="fas fa-file-alt fa-3x text-success mb-3"></i>
                                        <div class="info-label">Grade 12 Results</div>
                                        <?php if (!empty($student->results)): ?>
                                            <a href="view_document.php?type=results&file=<?php echo urlencode($student->results); ?>" 
                                               target="_blank" class="btn btn-sm btn-success mt-2">
                                                <i class="fas fa-eye me-1"></i>View
                                            </a>
                                        <?php else: ?>
                                            <div class="text-muted small mt-2">Not uploaded</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-item text-center">
                                        <i class="fas fa-user-circle fa-3x text-info mb-3"></i>
                                        <div class="info-label">Profile Photo</div>
                                        <?php if (!empty($student->profile_image) && $student->profile_image !== 'default.jpg'): ?>
                                            <a href="view_document.php?type=profile&file=<?php echo urlencode($student->profile_image); ?>" 
                                               target="_blank" class="btn btn-sm btn-info mt-2">
                                                <i class="fas fa-eye me-1"></i>View
                                            </a>
                                        <?php else: ?>
                                            <div class="text-muted small mt-2">Using default</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>

