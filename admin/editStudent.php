<?php
require_once "includes/admin.php"; // Load admin.php first (starts session)

$student = null;
$error_message = null;

if(isset($_GET['update'])){
    $lookupValue = $_GET['update'];

    // LEFT JOIN so student data still shows when no program is linked yet.
    $sql = "SELECT
                s.SID, s.Fname, s.Lname, s.sex, s.dob, s.email, s.mobile as phone,
                s.nrc_pass, s.profile_image,
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
            WHERE s.SID = ?
            LIMIT 1";

    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param("s", $lookupValue);
        $stmt->execute();
        $result = $stmt->get_result();
        $student = $result->fetch_object();
        $stmt->close();
    } else {
        $error_message = "Database error: " . $db->error;
    }
}

// Redirect if student not found
if (!$student) {
    $_SESSION['error_msg'] = $error_message ?? "Student record not found.";
    header("Location: students_by_admin.php");
    exit;
}

$page_title = "Edit Student";
require_once 'includes/header.php';

$isRegistrarLevel = hasAnyRole(['systems_admin', 'registrar']);

// Generate CSRF token for security (session must be active)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>

<style>
    :root {
        --primary-hsl: 238, 82%, 59%;
        --primary-color: hsl(var(--primary-hsl));
        --primary-hover: hsl(238, 82%, 53%);
        --slate-50: #f8fafc;
        --slate-100: #f1f5f9;
        --slate-200: #e2e8f0;
        --slate-300: #cbd5e1;
        --slate-500: #64748b;
        --slate-700: #334155;
        --slate-900: #0f172a;
    }
    
    /* Interactive Card Enhancements */
    .portal-dashboard .data-table-card {
        background: #ffffff;
        border: 1px solid var(--slate-200);
        border-radius: 1rem;
        box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
    }
    .portal-dashboard .data-table-card:hover {
        box-shadow: 0 12px 30px -4px rgba(15, 23, 42, 0.08);
        border-color: var(--slate-300);
    }
    
    /* Profile Photo Styling */
    .profile-edit-container {
        text-align: center;
        padding: 1.5rem;
        background: linear-gradient(135deg, var(--slate-50) 0%, #ffffff 100%);
        border-radius: 0.75rem;
        border: 1px dashed var(--slate-200);
        transition: border-color 0.25s ease;
    }
    .profile-edit-container:hover {
        border-color: var(--primary-color);
    }
    .profile-preview-wrapper {
        position: relative;
        width: 140px;
        height: 140px;
        margin: 0 auto 1rem;
    }
    .profile-preview-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
        border: 4px solid #ffffff;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
        transition: transform 0.3s ease;
    }
    .profile-preview-wrapper:hover .profile-preview-img {
        transform: scale(1.02);
    }
    .upload-label {
        position: absolute;
        bottom: 4px;
        right: 4px;
        width: 38px;
        height: 38px;
        background: var(--primary-color);
        color: #ffffff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        border: 3px solid #ffffff;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        transition: all 0.25s ease;
    }
    .upload-label:hover {
        background: var(--primary-hover);
        transform: scale(1.15) rotate(15deg);
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.25);
    }
    
    /* Section & Forms styling */
    .section-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--slate-900);
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        letter-spacing: -0.01em;
    }
    .section-title i {
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--slate-100);
        border-radius: 0.5rem;
        margin-right: 0.75rem;
        color: var(--slate-500);
        font-size: 0.9rem;
        transition: all 0.25s ease;
    }
    .data-table-card:hover .section-title i {
        background: rgba(var(--primary-hsl), 0.08);
        color: var(--primary-color);
    }
    
    .form-label {
        font-weight: 600;
        font-size: 0.8rem;
        color: var(--slate-500);
        margin-bottom: 0.375rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .form-control, .form-select {
        padding: 0.65rem 0.875rem;
        border: 1px solid var(--slate-200);
        border-radius: 0.5rem;
        font-size: 0.9rem;
        color: var(--slate-700);
        background-color: #ffffff;
        transition: all 0.2s ease-in-out;
    }
    .form-control:hover, .form-select:hover {
        border-color: var(--slate-300);
    }
    .form-control:focus, .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 4px rgba(var(--primary-hsl), 0.12);
        outline: none;
        color: var(--slate-900);
    }
    
    /* Elegant Read-only indicator inputs */
    .readonly-value {
        padding: 0.65rem 0.875rem;
        background: var(--slate-50);
        border: 1px solid var(--slate-200);
        border-radius: 0.5rem;
        font-size: 0.9rem;
        color: var(--slate-500);
        min-height: 2.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.2s ease;
    }
    .readonly-value:hover {
        background: var(--slate-100);
    }
    .readonly-value i {
        color: var(--slate-300);
        font-size: 0.8rem;
        transition: color 0.25s ease;
    }
    .readonly-value:hover i {
        color: var(--slate-500);
    }
    
    .form-control:disabled, .form-select:disabled {
        background-color: var(--slate-50);
        color: var(--slate-500);
        cursor: not-allowed;
        border-color: var(--slate-200);
    }
    
    /* Action Buttons */
    .btn {
        padding: 0.65rem 1.5rem;
        font-weight: 600;
        font-size: 0.9rem;
        border-radius: 0.5rem;
        transition: all 0.2s ease;
    }
    .btn-primary {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }
    .btn-primary:hover {
        background-color: var(--primary-hover);
        border-color: var(--primary-hover);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(var(--primary-hsl), 0.25);
    }
    .btn-outline-secondary {
        border-color: var(--slate-200);
        color: var(--slate-700);
    }
    .btn-outline-secondary:hover {
        background-color: var(--slate-50);
        border-color: var(--slate-300);
        color: var(--slate-900);
    }
</style>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4 mt-2">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="page-title mb-0"><i class="fas fa-user-edit me-2 text-primary"></i>Modify Student Profile</h5>
                <p class="page-subtitle mb-0">Update core information for <?php echo htmlspecialchars($student->Fname . ' ' . $student->Lname); ?></p>
            </div>
            <div class="header-actions">
                <a href="view_student.php?view=<?php echo urlencode($student->SID); ?>" class="btn btn-outline-secondary shadow-sm">
                    <i class="fas fa-eye me-1"></i>View Profile
                </a>
            </div>
        </div>
    </div>

    <form action="update_student.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student->SID ?? '', ENT_QUOTES); ?>">

        <div class="row g-4">
            <!-- Sidebar / Photo / Academic / Contact -->
            <div class="col-xl-4 col-lg-5">
                <div class="data-table-card p-4">
                    <!-- Student Photo -->
                    <h5 class="section-title mb-4"><i class="fas fa-camera"></i> Student Photo</h5>
                    <div class="profile-edit-container mb-4">
                        <div class="profile-preview-wrapper">
                            <?php
                            $profileImgPath = 'images/avatar.png'; // default fallback
                            if (!empty($student->profile_image)) {
                                $imgPathOnDisk = dirname(__DIR__) . '/uploads/profile/' . $student->profile_image;
                                if (file_exists($imgPathOnDisk)) {
                                    $profileImgPath = '../uploads/profile/' . $student->profile_image;
                                }
                            }
                            ?>
                            <img src="<?php echo htmlspecialchars($profileImgPath); ?>" 
                                 alt="Profile" class="profile-preview-img" id="profilePreview">
                            <input type="file" name="profile_image" id="profileInput" hidden accept="image/*">
                            <label for="profileInput" class="upload-label">
                                <i class="fas fa-pencil-alt"></i>
                            </label>
                        </div>
                        <p class="small text-muted mb-0">JPG, PNG or GIF. Max size 2MB.</p>
                    </div>
                    
                    <hr class="my-4" style="border-color: var(--slate-200);">

                    <!-- Academic Information -->
                    <h5 class="section-title"><i class="fas fa-graduation-cap"></i> Academic Info</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label">Academic Program</label>
                            <select class="form-select" name="program" <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                                <option value="">Select Program/Course</option>
                                <?php
                                $programs = $db->query("SELECT program_code, program_name FROM programs ORDER BY program_name");
                                $short_courses = $db->query("SELECT course_code, course_name FROM short_courses ORDER BY course_name");
                                $current_prog = $student->student_program_code ?? '';

                                if ($programs && $programs->num_rows > 0) {
                                    echo '<optgroup label="Academic Programs">';
                                    while($program = $programs->fetch_object()) {
                                        $selected = ($program->program_code == $current_prog) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($program->program_code) . "' {$selected}>" . htmlspecialchars($program->program_name) . "</option>";
                                    }
                                    echo '</optgroup>';
                                }

                                if ($short_courses && $short_courses->num_rows > 0) {
                                    echo '<optgroup label="Short Courses">';
                                    while($sc = $short_courses->fetch_object()) {
                                        $selected = ($sc->course_code == $current_prog) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($sc->course_code) . "' {$selected}>" . htmlspecialchars($sc->course_name) . "</option>";
                                    }
                                    echo '</optgroup>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Semester/Term</label>
                            <select class="form-select" name="semester" <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                                <option value="">Select Term</option>
                                <option value="1" <?php if(($student->term ?? '') == '1') echo 'selected'; ?>>Semester/Term 1</option>
                                <option value="2" <?php if(($student->term ?? '') == '2') echo 'selected'; ?>>Semester/Term 2</option>
                                <option value="3" <?php if(($student->term ?? '') == '3') echo 'selected'; ?>>Term 3</option>
                            </select>
                        </div>
                    </div>

                    <hr class="my-4" style="border-color: var(--slate-200);">

                    <!-- Contact Information -->
                    <h5 class="section-title"><i class="fas fa-phone"></i> Contact Details</h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="email" required
                                   value="<?php echo htmlspecialchars($student->email ?? ''); ?>" <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone" required
                                   value="<?php echo htmlspecialchars($student->phone ?? ''); ?>" <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Form Details -->
            <div class="col-xl-8 col-lg-7">
                <!-- Personal Information -->
                <div class="data-table-card mb-4">
                    <div class="card-body p-4">
                        <h5 class="section-title"><i class="fas fa-user"></i> Personal Information</h5>
                        <?php if (!$isRegistrarLevel): ?>
                            <div class="alert alert-secondary py-2 small mb-3">
                                <i class="fas fa-lock me-1"></i> Identity details are managed via the registrar's
                                admissions flow and cannot be changed here.
                            </div>
                        <?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <?php if ($isRegistrarLevel): ?>
                                    <input type="text" class="form-control" name="fname" required
                                           value="<?php echo htmlspecialchars($student->Fname); ?>">
                                <?php else: ?>
                                    <div class="readonly-value">
                                        <span><?php echo htmlspecialchars($student->Fname); ?></span>
                                        <i class="fas fa-lock text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <?php if ($isRegistrarLevel): ?>
                                    <input type="text" class="form-control" name="lname" required
                                           value="<?php echo htmlspecialchars($student->Lname); ?>">
                                <?php else: ?>
                                    <div class="readonly-value">
                                        <span><?php echo htmlspecialchars($student->Lname); ?></span>
                                        <i class="fas fa-lock text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Gender</label>
                                <?php if ($isRegistrarLevel): ?>
                                    <select class="form-select" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="M" <?php if(($student->sex ?? '') == 'M') echo 'selected'; ?>>Male</option>
                                        <option value="F" <?php if(($student->sex ?? '') == 'F') echo 'selected'; ?>>Female</option>
                                    </select>
                                <?php else: ?>
                                    <div class="readonly-value">
                                        <span><?php
                                            $sex = $student->sex ?? '';
                                            echo $sex === 'M' ? 'Male' : ($sex === 'F' ? 'Female' : '&mdash;');
                                        ?></span>
                                        <i class="fas fa-lock text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date of Birth</label>
                                <?php if ($isRegistrarLevel): ?>
                                    <input type="date" class="form-control" name="dob"
                                           value="<?php echo htmlspecialchars($student->dob ?? ''); ?>">
                                <?php else: ?>
                                    <div class="readonly-value">
                                        <span><?php echo htmlspecialchars($student->dob ?: '—'); ?></span>
                                        <i class="fas fa-lock text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">NRC/Passport Number</label>
                                <?php if ($isRegistrarLevel): ?>
                                    <input type="text" class="form-control" name="nrc" required
                                           value="<?php echo htmlspecialchars($student->nrc_pass ?? ''); ?>">
                                <?php else: ?>
                                    <div class="readonly-value">
                                        <span><?php echo htmlspecialchars($student->nrc_pass ?: '—'); ?></span>
                                        <i class="fas fa-lock text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Intake Month</label>
                                <div class="readonly-value">
                                    <span><?php echo htmlspecialchars($student->intake ?: '—'); ?></span>
                                    <i class="fas fa-lock text-muted"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Controls -->
                <div class="d-flex justify-content-end gap-2 mb-5">
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="history.back()">
                        Cancel
                    </button>
                    <button type="submit" name="update_student" class="btn btn-primary px-5">
                        <i class="fas fa-save me-2"></i>Save Student Changes
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
// Preview profile image before upload
document.getElementById('profileInput').onchange = function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('profilePreview').src = e.target.result;
        }
        reader.readAsDataURL(file);
    }
}
</script>

<?php require 'includes/footer.php'; ?>

