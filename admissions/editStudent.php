<?php
// ===== CRITICAL FIX 1: SESSION START + AUTHENTICATION =====
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/db/connect.php';
require_once __DIR__ . '/includes/session_handler.php';
require_once dirname(__DIR__) . '/includes/student_identity.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' data: https:;");

// Auth check BEFORE any processing
if (!checkSessionTimeout(30) || !isAdminAuthenticated()) {
    $_SESSION['errorMessage'] = "Session expired or unauthorized access";
    header('Location: /wucportal/staff_login.php');
    exit;
}

// Environment setup
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// ===== CRITICAL FIX 2: CSRF TOKEN =====
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ===== CRITICAL FIX 3: VALIDATE STUDENT ID SAFELY =====
$student_id = trim($_GET['sid'] ?? $_GET['edit'] ?? '');
$errors = [];
$form_data = []; // For repopulating form on error

// Validate ID format
if (empty($student_id)) {
    $_SESSION['errorMessage'] = "No student ID provided";
    header('Location: regOldStud.php');
    exit;
}

if (!preg_match('/^[A-Za-z0-9\-_]+$/', $student_id)) {
    $_SESSION['errorMessage'] = "Invalid student ID format";
    header('Location: regOldStud.php');
    exit;
}

// Fetch student securely
$student = null;
$stmt = $db->prepare("SELECT * FROM students WHERE SID = ? AND status != 'Deleted'");
if ($stmt) {
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $student = $result->fetch_object();
        // Initialize form data with student values
        $form_data = (array) $student;
    }
    $stmt->close();
}

if (!$student) {
    $_SESSION['errorMessage'] = "Student not found or deleted";
    header('Location: regOldStud.php');
    exit;
}

// ===== IDENTITY PROTECTION =====
// Only an authorized role (Systems Admin / Registrar / Administrator) may correct
// protected identity fields; everyone else edits non-sensitive details only.
$currentStaffId = $_SESSION['staff_id'] ?? '';
$canCorrectIdentity = wuc_staff_can_correct_identity($db, $currentStaffId);

// ===== CRITICAL FIX 4: HANDLE FORM SUBMISSION SECURELY =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Security token mismatch. Please refresh the page and try again.";
    } else {
        // Non-sensitive fields anyone may edit. Identity fields are NEVER taken
        // from this branch — they keep the stored values and are only changed
        // through the authorized-correction path below.
        $form_data = (array) $student;
        $form_data['country']         = trim($_POST["country"] ?? '');
        $form_data['mobile']          = trim($_POST["mobile"] ?? '');
        $form_data['email']           = trim($_POST["email"] ?? '');
        $form_data['h_addre']         = trim($_POST["h_addre"] ?? '');
        $form_data['p_addre']         = trim($_POST["p_addre"] ?? '');
        $form_data['status']          = trim($_POST["status"] ?? 'Active');
        $form_data['sponsor']         = trim($_POST["sponsor"] ?? '');
        $form_data['next_kin']        = trim($_POST["next_kin"] ?? '');
        $form_data['next_kin_mobile'] = trim($_POST["next_kin_mobile"] ?? '');
        $form_data['relat']           = trim($_POST["relat"] ?? '');
        $form_data['dte_adm']         = trim($_POST["dte_adm"] ?? '');

        // Validation of the non-sensitive fields
        if (!empty($form_data['email']) && !filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address";
        }
        if (!empty($form_data['dte_adm'])) {
            $adm_dt = DateTime::createFromFormat('Y-m-d', $form_data['dte_adm']);
            if (!$adm_dt || $adm_dt->format('Y-m-d') !== $form_data['dte_adm']) {
                $errors[] = "Please enter a valid admission date (YYYY-MM-DD)";
            }
        }
        if (!empty($form_data['mobile']) && !preg_match('/^\+?[0-9\s\-()]{7,15}$/', $form_data['mobile'])) {
            $errors[] = "Please enter a valid mobile number";
        }

        // ===== Identity correction (authorized roles only) =====
        // Any identity values posted by an unauthorized user are ignored here;
        // the DB trigger blocks them too, so dev-tools tampering cannot win.
        $identity_changes = [];
        $identity_reason  = trim($_POST['identity_reason'] ?? '');
        if ($canCorrectIdentity) {
            $proposed = [
                'SID'           => trim($_POST['SID'] ?? ''),
                'Fname'         => trim($_POST['Fname'] ?? ''),
                'Lname'         => trim($_POST['Lname'] ?? ''),
                'sex'           => trim($_POST['sex'] ?? ''),
                'dob'           => trim($_POST['dob'] ?? ''),
                'nrc_pass'      => trim($_POST['nrc_pass'] ?? ''),
                'program'       => trim($_POST['program'] ?? ''),
                'academic_year' => trim($_POST['academic_year'] ?? ''),
                'intake'        => trim($_POST['intake'] ?? ''),
            ];
            foreach ($proposed as $col => $val) {
                if ((string)$val !== (string)($student->$col ?? '')) {
                    $identity_changes[$col] = $val;
                    $form_data[$col] = $val; // reflect attempted value on re-render
                }
            }
            if ($identity_changes && $identity_reason === '') {
                $errors[] = "A reason is required to correct protected identity fields.";
            }
            // Pre-validate each identity value so the user sees friendly errors
            // (the correction function validates again as the hard guard).
            foreach ($identity_changes as $col => $val) {
                try {
                    wuc_validate_identity_value($col, $val);
                } catch (InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            if (isset($identity_changes['SID'])) {
                $check_stmt = $db->prepare("SELECT SID FROM students WHERE SID = ? AND SID != ?");
                $check_stmt->bind_param("ss", $identity_changes['SID'], $student_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                if ($check_stmt->num_rows > 0) {
                    $errors[] = "That student number is already in use.";
                }
                $check_stmt->close();
            }
        }

        // Process if no errors
        if (empty($errors)) {
            try {
                // 1) Apply the non-sensitive update. No identity columns are
                //    touched, so the protection trigger allows it.
                $sql = "UPDATE students SET
                    country=?, mobile=?, email=?, h_addre=?, p_addre=?, status=?,
                    sponsor=?, next_kin=?, next_kin_mobile=?, relat=?, dte_adm=?
                    WHERE SID=?";
                $stmt = $db->prepare($sql);
                $stmt->bind_param(
                    "ssssssssssss",
                    $form_data['country'],
                    $form_data['mobile'],
                    $form_data['email'],
                    $form_data['h_addre'],
                    $form_data['p_addre'],
                    $form_data['status'],
                    $form_data['sponsor'],
                    $form_data['next_kin'],
                    $form_data['next_kin_mobile'],
                    $form_data['relat'],
                    $form_data['dte_adm'],
                    $student_id
                );
                $stmt->execute();
                $stmt->close();

                // 2) Apply any identity correction through the audited path.
                $redirect_sid = $student_id;
                if ($canCorrectIdentity && $identity_changes) {
                    $diff = applyStudentIdentityCorrection(
                        $db, $student_id, $identity_changes, $currentStaffId, $identity_reason
                    );
                    if (isset($diff['SID'])) {
                        $redirect_sid = $diff['SID']['new'];
                    }
                    logActivity($currentStaffId, 'identity_correction',
                        $student_id . ': ' . implode(', ', array_keys($diff)));
                }

                $_SESSION['successMessage'] = "Student information successfully updated";
                header("Location: view_student.php?view=" . urlencode($redirect_sid));
                exit;
            } catch (Throwable $e) {
                error_log("Edit Student Error: " . $e->getMessage());
                $errors[] = "Error: " . $e->getMessage();
            }
        }
    }
}

$page_title = 'Edit Student';

// Render attributes for protected identity fields: editable only for an
// authorized correction role, otherwise locked read-only.
$idRO  = $canCorrectIdentity ? '' : 'readonly';
$idDIS = $canCorrectIdentity ? '' : 'disabled';
$lockBadge = '<span class="badge bg-secondary ms-1" title="Protected identity field"><i class="fas fa-lock"></i></span>';

require "includes/nav.php";
?>

<div class="container-fluid px-4 py-4 portal-dashboard">
    <!-- Dashboard Header -->
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Edit Student</h1>
                <p class="text-muted">Update student information for <strong><?= htmlspecialchars($student->Fname . ' ' . $student->Lname) ?></strong></p>
            </div>
            <div class="col-auto">
                <a href="regOldStud.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Students
                </a>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="data-table-card mb-4">
        <div class="card-body d-flex flex-wrap gap-2">
            <a href="regOldStud.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Students
            </a>
            <a href="view_student.php?view=<?= urlencode($student_id) ?>" class="btn btn-info">
                <i class="fas fa-eye me-2"></i>View Student Profile
            </a>
            <a href="students.php" class="btn btn-outline-primary">
                <i class="fas fa-list me-2"></i>All Students
            </a>
            <div class="ms-auto">
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
            </div>
        </div>
    </div>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <h5 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Validation Errors</h5>
        <ul class="mb-0 mt-2 ps-3">
            <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Success Message -->
    <?php if (isset($_SESSION['successMessage'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['successMessage']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['successMessage']); ?>
    <?php endif; ?>

    <!-- Identity protection notice -->
    <?php if ($canCorrectIdentity): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fas fa-user-shield me-2"></i>
        <strong>Authorized correction mode.</strong> You may correct protected
        identity fields (student number, NRC, name, DOB, gender, programme,
        enrolment year/period). Every change is recorded in the audit trail and
        <strong>requires a reason</strong>.
    </div>
    <?php else: ?>
    <div class="alert alert-info" role="alert">
        <i class="fas fa-lock me-2"></i>
        Protected identity fields are <strong>read-only</strong>. You can update
        contact, address and guardian details only. Identity corrections must be
        made by a Systems Admin, Registrar or Administrator.
    </div>
    <?php endif; ?>

    <!-- Edit Form -->
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="data-table-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Student Information</h5>
                </div>
                <div class="card-body">
                    <form action="editStudent.php?sid=<?= urlencode($student_id) ?>" method="post" class="needs-validation" novalidate>
                        <!-- CSRF Token -->
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        
                        <!-- Personal Information -->
                        <h6 class="text-primary mb-3 mt-2"><i class="fas fa-user me-2"></i>Personal Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="SID" class="form-label">Student ID <span class="text-danger">*</span><?= $lockBadge ?></label>
                                <input type="text" class="form-control"
                                       id="SID" name="SID" value="<?= htmlspecialchars($form_data['SID'] ?? $student->SID) ?>" <?= $idRO ?> required>
                                <?php if ($canCorrectIdentity): ?>
                                <div class="form-text text-warning">
                                    <i class="fas fa-exclamation-triangle me-1"></i>Changing the student number cascades to all related records and is audited.
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="Fname" class="form-label">First Name <span class="text-danger">*</span><?= $lockBadge ?></label>
                                <input type="text" class="form-control"
                                       id="Fname" name="Fname" value="<?= htmlspecialchars($form_data['Fname'] ?? $student->Fname) ?>" <?= $idRO ?> required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="Lname" class="form-label">Last Name <span class="text-danger">*</span><?= $lockBadge ?></label>
                                <input type="text" class="form-control"
                                       id="Lname" name="Lname" value="<?= htmlspecialchars($form_data['Lname'] ?? $student->Lname) ?>" <?= $idRO ?> required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="sex" class="form-label">Gender <span class="text-danger">*</span><?= $lockBadge ?></label>
                                <select class="form-select" id="sex" name="sex" <?= $idDIS ?> required>
                                    <option value="">Select Gender</option>
                                    <option value="M" <?= ($form_data['sex'] ?? $student->sex) === 'M' ? 'selected' : '' ?>>Male</option>
                                    <option value="F" <?= ($form_data['sex'] ?? $student->sex) === 'F' ? 'selected' : '' ?>>Female</option>
                                </select>
                                <?php if (!$canCorrectIdentity): ?><input type="hidden" name="sex" value="<?= htmlspecialchars($student->sex) ?>"><?php endif; ?>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="dob" class="form-label">Date of Birth<?= $lockBadge ?></label>
                                <input type="date" class="form-control"
                                       id="dob" name="dob" value="<?= htmlspecialchars($form_data['dob'] ?? $student->dob) ?>" <?= $idRO ?>>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="country" class="form-label">Country</label>
                                <input type="text" class="form-control" id="country" name="country" 
                                       value="<?= htmlspecialchars($form_data['country'] ?? $student->country) ?>">
                            </div>
                        </div>

                        <!-- Identification & Contact -->
                        <h6 class="text-primary mb-3 mt-4"><i class="fas fa-id-card me-2"></i>Identification & Contact</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="nrc_pass" class="form-label">NRC/Passport<?= $lockBadge ?></label>
                                <input type="text" class="form-control" id="nrc_pass" name="nrc_pass"
                                       value="<?= htmlspecialchars($form_data['nrc_pass'] ?? $student->nrc_pass) ?>" <?= $idRO ?>>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="mobile" class="form-label">Mobile</label>
                                <input type="tel" class="form-control <?= in_array("Please enter a valid mobile number", $errors) ? 'is-invalid' : '' ?>" 
                                       id="mobile" name="mobile" value="<?= htmlspecialchars($form_data['mobile'] ?? $student->mobile) ?>">
                                <div class="invalid-feedback">Please enter a valid mobile number</div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control <?= in_array("Please enter a valid email address", $errors) ? 'is-invalid' : '' ?>" 
                                       id="email" name="email" value="<?= htmlspecialchars($form_data['email'] ?? $student->email) ?>">
                                <div class="invalid-feedback">Please enter a valid email address</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="Active" <?= ($form_data['status'] ?? $student->status) === 'Active' ? 'selected' : '' ?>>Active Student</option>
                                    <option value="Registered" <?= ($form_data['status'] ?? $student->status) === 'Registered' ? 'selected' : '' ?>>Registered</option>
                                    <option value="Industrial Attachment" <?= ($form_data['status'] ?? $student->status) === 'Industrial Attachment' ? 'selected' : '' ?>>Industrial Attachment</option>
                                    <option value="Completed" <?= ($form_data['status'] ?? $student->status) === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="Graduated" <?= ($form_data['status'] ?? $student->status) === 'Graduated' ? 'selected' : '' ?>>Graduated</option>
                                    <option value="Alumni" <?= ($form_data['status'] ?? $student->status) === 'Alumni' ? 'selected' : '' ?>>Alumni</option>
                                    <option value="Suspended" <?= ($form_data['status'] ?? $student->status) === 'Suspended' ? 'selected' : '' ?>>Suspended</option>
                                    <option value="Withdrawn" <?= ($form_data['status'] ?? $student->status) === 'Withdrawn' ? 'selected' : '' ?>>Withdrawn</option>
                                    <option value="Inactive" <?= ($form_data['status'] ?? $student->status) === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>

                        <!-- Enrolment (protected identity) -->
                        <h6 class="text-primary mb-3 mt-4"><i class="fas fa-graduation-cap me-2"></i>Enrolment <small class="text-muted">(protected)</small></h6>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="program" class="form-label">Programme<?= $lockBadge ?></label>
                                <input type="text" class="form-control" id="program" name="program"
                                       value="<?= htmlspecialchars($form_data['program'] ?? $student->program) ?>" <?= $idRO ?>>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="academic_year" class="form-label">Year of Enrolment<?= $lockBadge ?></label>
                                <input type="text" class="form-control" id="academic_year" name="academic_year"
                                       value="<?= htmlspecialchars($form_data['academic_year'] ?? $student->academic_year) ?>" <?= $idRO ?>>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="intake" class="form-label">Academic Period / Intake<?= $lockBadge ?></label>
                                <input type="text" class="form-control" id="intake" name="intake"
                                       value="<?= htmlspecialchars($form_data['intake'] ?? $student->intake) ?>" <?= $idRO ?>>
                            </div>
                        </div>

                        <?php if ($canCorrectIdentity): ?>
                        <div class="mb-3">
                            <label for="identity_reason" class="form-label">
                                Reason for identity correction
                                <small class="text-muted">(required only when you change a protected field above)</small>
                            </label>
                            <textarea class="form-control" id="identity_reason" name="identity_reason" rows="2"
                                      placeholder="e.g. Corrected NRC after verifying original document"><?= htmlspecialchars($_POST['identity_reason'] ?? '') ?></textarea>
                        </div>
                        <?php endif; ?>

                        <!-- Address Information -->
                        <h6 class="text-primary mb-3 mt-4"><i class="fas fa-map-marker-alt me-2"></i>Address Information</h6>
                        <div class="mb-3">
                            <label for="h_addre" class="form-label">Home Address</label>
                            <textarea class="form-control" id="h_addre" name="h_addre" rows="3"><?= htmlspecialchars($form_data['h_addre'] ?? $student->h_addre) ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="p_addre" class="form-label">Postal Address</label>
                            <textarea class="form-control" id="p_addre" name="p_addre" rows="3"><?= htmlspecialchars($form_data['p_addre'] ?? $student->p_addre) ?></textarea>
                        </div>

                        <!-- Family/Guardian Information -->
                        <h6 class="text-primary mb-3 mt-4"><i class="fas fa-users me-2"></i>Family/Guardian Information</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="sponsor" class="form-label">Sponsor</label>
                                <input type="text" class="form-control" id="sponsor" name="sponsor" 
                                       value="<?= htmlspecialchars($form_data['sponsor'] ?? $student->sponsor) ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="next_kin" class="form-label">Next of Kin</label>
                                <input type="text" class="form-control" id="next_kin" name="next_kin" 
                                       value="<?= htmlspecialchars($form_data['next_kin'] ?? $student->next_kin) ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="next_kin_mobile" class="form-label">Next of Kin Contact</label>
                                <input type="tel" class="form-control" id="next_kin_mobile" name="next_kin_mobile" 
                                       value="<?= htmlspecialchars($form_data['next_kin_mobile'] ?? $student->next_kin_mobile) ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="relat" class="form-label">Relationship</label>
                                <input type="text" class="form-control" id="relat" name="relat" 
                                       value="<?= htmlspecialchars($form_data['relat'] ?? $student->relat) ?>">
                            </div>
                        </div>

                        <!-- Administrative Information -->
                        <h6 class="text-primary mb-3 mt-4"><i class="fas fa-cog me-2"></i>Administrative Information</h6>
                        <div class="mb-3">
                            <label for="dte_adm" class="form-label">Date of Admission</label>
                            <input type="date" class="form-control <?= in_array("Please enter a valid admission date (YYYY-MM-DD)", $errors) ? 'is-invalid' : '' ?>" 
                                   id="dte_adm" name="dte_adm" value="<?= htmlspecialchars($form_data['dte_adm'] ?? $student->dte_adm) ?>">
                            <div class="invalid-feedback">Please enter a valid date (YYYY-MM-DD)</div>
                        </div>

                        <?php if ($canCorrectIdentity): ?>
                        <div class="alert alert-info p-2 mt-3">
                            <i class="fas fa-info-circle me-1"></i> <strong>Note:</strong> Correcting the student number cascades to all related records and is written to the identity audit trail.
                        </div>
                        <?php endif; ?>

                        <div class="d-flex gap-2 justify-content-end pt-3 border-top">
                            <a href="regOldStud.php" class="btn btn-secondary"><i class="fas fa-times me-1"></i>Cancel</a>
                            <button type="submit" name="update" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update Student
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Form Validation Script -->
<script>
(function () {
    'use strict'
    
    // Custom validation UI
    const forms = document.querySelectorAll('.needs-validation')
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
                
                // Scroll to first invalid field
                const firstInvalid = form.querySelector(':invalid')
                if (firstInvalid) {
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' })
                    firstInvalid.focus()
                }
            }
            form.classList.add('was-validated')
        }, false)
    })
    
    // Warn before leaving page with unsaved changes
    let formChanged = false
    const form = document.querySelector('form.needs-validation')
    if (form) {
        form.addEventListener('input', () => { formChanged = true })
        form.addEventListener('submit', () => { formChanged = false })
        
        window.addEventListener('beforeunload', (e) => {
            if (formChanged) {
                e.preventDefault()
                e.returnValue = ''
            }
        })
    }
})()
</script>

<style>
/* Enhanced form validation styles */
.was-validated .form-control:invalid, 
.was-validated .form-select:invalid {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='none' stroke='%23dc3545' viewBox='0 0 12 12'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
}
.form-text.text-warning {
    font-size: 0.875em;
    margin-top: 0.25rem;
}
</style>

<?php require "includes/footer.php"; ?>

