<?php
require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/helpers/academic_structure_helpers.php';
require_once dirname(__DIR__) . '/includes/cse_progression.php';

// Enable mysqli error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$page_title = 'Update Student';
$isRegistrarLevel = hasRole(ROLE_SYSTEMS_ADMIN);

// NOTE: CSRF token should already exist from editStudent.php
// Do NOT regenerate it here or validation will fail

// Check if student ID is provided
if (!isset($_GET['sid']) && !isset($_POST['student_id'])) {
    $_SESSION['error_msg'] = "Student ID is required.";
    header("Location: students_by_admin.php");
    exit();
}

$student_id = $_GET['sid'] ?? $_POST['student_id'];

// Fetch student details
$student = null;
$student_program = null;

try {
    $stmt = $db->prepare("SELECT * FROM students WHERE SID = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Student not found.");
    }
    
    $student = $result->fetch_object();
    $stmt->close();
    
    // Get student program info (using Sid column)
    $stmt = $db->prepare("SELECT * FROM student_program WHERE Sid = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $student_program = $result->fetch_object();
    }
    $stmt->close();
    
} catch (Exception $e) {
    $_SESSION['error_msg'] = "Error loading student: " . $e->getMessage();
    header("Location: students_by_admin.php");
    exit();
}

// Get all programs for dropdown without assuming optional period columns exist.
$program_cols = [];
if ($program_meta = $db->query("SHOW COLUMNS FROM programs")) {
    while ($col = $program_meta->fetch_assoc()) {
        $program_cols[strtolower((string)$col['Field'])] = (string)$col['Field'];
    }
    $program_meta->free();
}
$period_select = isset($program_cols['period_mode'])
    ? "period_mode"
    : (isset($program_cols['period_type']) ? "period_type AS period_mode" : "NULL AS period_mode");
$study_select = isset($program_cols['study_mode']) ? "study_mode" : "NULL AS study_mode";
$programs_query = "SELECT program_code, program_name, {$period_select}, {$study_select}
                     FROM programs
                    WHERE COALESCE(is_active, 1) = 1
                      AND program_code NOT IN ('CSE', 'ICT-002')
                    ORDER BY program_name ASC";
$programs_result = $db->query($programs_query);
$programs = [];
while($row = $programs_result->fetch_object()) {
    $programs[] = $row;
}

// Get all short courses for dropdown
$short_courses = [];
$short_courses_result = $db->query("SELECT course_code, course_name FROM short_courses ORDER BY course_name ASC");
if ($short_courses_result) {
    while($row = $short_courses_result->fetch_object()) {
        $short_courses[] = $row;
    }
    $short_courses_result->free();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    try {
        // CSRF Token Validation with detailed error message
        if (!isset($_POST['csrf_token'])) {
            throw new Exception("Security token missing from form. Please refresh the page and try again.");
        }
        
        if (!isset($_SESSION['csrf_token'])) {
            throw new Exception("Session expired. Please refresh the page and try again.");
        }
        
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }
        
        $db->begin_transaction();

        if ($isRegistrarLevel) {
            // Validate the fields for registrar-level edit
            $required = ['fname', 'lname', 'gender', 'nrc', 'email', 'phone'];
            foreach($required as $field) {
                if(empty($_POST[$field])) {
                    throw new Exception(ucfirst($field) . " is required.");
                }
            }
            if (!empty($_POST['program']) && ($stageError = wuc_cse_direct_assignment_error((string)$_POST['program']))) {
                throw new Exception($stageError);
            }

            // Check for duplicate phone (excluding current student)
            $stmt = $db->prepare("SELECT SID FROM students WHERE mobile = ? AND SID != ?");
            $stmt->bind_param("ss", $_POST['phone'], $student_id);
            $stmt->execute();
            if($stmt->get_result()->num_rows > 0) {
                throw new Exception("Phone number already registered to another student.");
            }
            $stmt->close();
        }

        // Handle file uploads
        $profile_upload_path = dirname(__DIR__) . '/uploads/profile/';
        if (!is_dir($profile_upload_path)) {
            mkdir($profile_upload_path, 0755, true);
        }

        $profile_image = $student->profile_image ?? 'default.jpg';
        $nrc_file = $student->nrc_file ?? null;
        $results_file = $student->results ?? null;

        // Update profile image if uploaded
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {
            $allowed_image_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!in_array($_FILES['profile_image']['type'], $allowed_image_types)) {
                throw new Exception("Invalid image file type. Only JPG, PNG, and GIF allowed.");
            }
            
            // Delete old file if not default/avatar
            if ($profile_image !== 'default.jpg' && $profile_image !== 'avatar.png' && !empty($profile_image) && file_exists($profile_upload_path . $profile_image)) {
                unlink($profile_upload_path . $profile_image);
            }
            
            $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
            $profile_image = "profile_" . $student_id . "_" . time() . "." . $ext;
            move_uploaded_file($_FILES['profile_image']['tmp_name'], $profile_upload_path . $profile_image);
        }

        if ($isRegistrarLevel) {
            $nrc_upload_path = dirname(__DIR__) . '/uploads/nrc/';
            $results_upload_path = dirname(__DIR__) . '/uploads/results/';
            if (!is_dir($nrc_upload_path)) mkdir($nrc_upload_path, 0755, true);
            if (!is_dir($results_upload_path)) mkdir($results_upload_path, 0755, true);

            // Update NRC file if uploaded
            if (isset($_FILES['nrc_file']) && $_FILES['nrc_file']['error'] === 0) {
                $allowed_doc_types = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
                if (!in_array($_FILES['nrc_file']['type'], $allowed_doc_types)) {
                    throw new Exception("Invalid NRC file type. Only PDF, JPG, and PNG allowed.");
                }
                
                // Delete old file
                if ($nrc_file && file_exists($nrc_upload_path . $nrc_file)) {
                    unlink($nrc_upload_path . $nrc_file);
                }
                
                $ext = pathinfo($_FILES['nrc_file']['name'], PATHINFO_EXTENSION);
                $nrc_file = "nrc_" . $student_id . "_" . time() . "." . $ext;
                move_uploaded_file($_FILES['nrc_file']['tmp_name'], $nrc_upload_path . $nrc_file);
            }

            // Update results file if uploaded
            if (isset($_FILES['results_file']) && $_FILES['results_file']['error'] === 0) {
                $allowed_doc_types = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
                if (!in_array($_FILES['results_file']['type'], $allowed_doc_types)) {
                    throw new Exception("Invalid results file type. Only PDF, JPG, and PNG allowed.");
                }
                
                // Delete old file
                if ($results_file && file_exists($results_upload_path . $results_file)) {
                    unlink($results_upload_path . $results_file);
                }
                
                $ext = pathinfo($_FILES['results_file']['name'], PATHINFO_EXTENSION);
                $results_file = "results_" . $student_id . "_" . time() . "." . $ext;
                move_uploaded_file($_FILES['results_file']['tmp_name'], $results_upload_path . $results_file);
            }

            // Update all student details
            $stmt = $db->prepare("UPDATE students SET
                Fname = ?,
                Lname = ?,
                sex = ?,
                dob = ?,
                nrc_pass = ?,
                email = ?,
                mobile = ?,
                profile_image = ?,
                nrc_file = ?,
                results = ?,
                program = ?
                WHERE SID = ?");

            $dob_val = !empty($_POST['dob']) ? $_POST['dob'] : null;
            $program_val = !empty($_POST['program']) ? $_POST['program'] : null;

            $stmt->bind_param("ssssssssssss",
                $_POST['fname'],
                $_POST['lname'],
                $_POST['gender'],
                $dob_val,
                $_POST['nrc'],
                $_POST['email'],
                $_POST['phone'],
                $profile_image,
                $nrc_file,
                $results_file,
                $program_val,
                $student_id
            );
            $stmt->execute();
            $stmt->close();

            // Update program/semester if program is provided
            if (!empty($_POST['program'])) {
                $periodPayload = wuc_student_program_period_payload($db, (string)$_POST['program'], $_POST['semester'] ?? null);
                if (!$periodPayload['ok']) {
                    throw new Exception($periodPayload['reason']);
                }
                $periodFields = $periodPayload['fields'];
                $term_val = $periodFields['term'];
                $semester_val = $periodFields['semester'];
                $current_term_number = $periodFields['current_term_number'];
                $current_semester_number = $periodFields['current_semester_number'];
                $current_level_number = $periodFields['current_level_number'];
                
                // Check if the student already has a mapping for the selected program
                $stmt = $db->prepare("SELECT * FROM student_program WHERE Sid = ? AND program_code = ? LIMIT 1");
                $stmt->bind_param("ss", $student_id, $_POST['program']);
                $stmt->execute();
                $prog_result = $stmt->get_result();
                $existing_prog = $prog_result->fetch_object();
                $stmt->close();

                if ($existing_prog) {
                    // Update that specific mapping's structure-aware period and make it active.
                    $stmt = $db->prepare("UPDATE student_program
                        SET term = ?,
                            semester = ?,
                            current_term_number = ?,
                            current_semester_number = ?,
                            current_level_number = ?,
                            status = 'active'
                        WHERE id = ?");
                    $stmt->bind_param("sssssi", $term_val, $semester_val, $current_term_number, $current_semester_number, $current_level_number, $existing_prog->id);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Set other program mappings for this student to inactive
                    $stmt = $db->prepare("UPDATE student_program SET status = 'inactive' WHERE Sid = ? AND id != ?");
                    $stmt->bind_param("si", $student_id, $existing_prog->id);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    // Get latest program mapping for this student
                    $stmt = $db->prepare("SELECT * FROM student_program WHERE Sid = ? ORDER BY id DESC LIMIT 1");
                    $stmt->bind_param("s", $student_id);
                    $stmt->execute();
                    $latest_prog = $stmt->get_result()->fetch_object();
                    $stmt->close();

                    if ($latest_prog) {
                        // Update the latest mapping to the new program with structure-aware period fields.
                        $stmt = $db->prepare("UPDATE student_program
                            SET program_code = ?,
                                term = ?,
                                semester = ?,
                                current_term_number = ?,
                                current_semester_number = ?,
                                current_level_number = ?,
                                status = 'active'
                            WHERE id = ?");
                        $stmt->bind_param("ssssssi", $_POST['program'], $term_val, $semester_val, $current_term_number, $current_semester_number, $current_level_number, $latest_prog->id);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        // Insert new mapping if none exists at all.
                        $stmt = $db->prepare("INSERT INTO student_program
                            (Sid, program_code, intake, term, semester, current_term_number, current_semester_number, current_level_number, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                        $stmt->bind_param("ssssssss",
                            $student_id,
                            $_POST['program'],
                            $student->intake,
                            $term_val,
                            $semester_val,
                            $current_term_number,
                            $current_semester_number,
                            $current_level_number
                        );
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        } else {
            // Non-registrar: ONLY update profile_image
            $stmt = $db->prepare("UPDATE students SET profile_image = ? WHERE SID = ?");
            $stmt->bind_param("ss", $profile_image, $student_id);
            $stmt->execute();
            $stmt->close();
        }

        $db->commit();
        $_SESSION['success_msg'] = "Student updated successfully! Student ID: " . htmlspecialchars($student_id);
        header("Location: students_by_admin.php");
        exit();

    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error_msg'] = "Update failed: " . $e->getMessage();
    }
}

require_once "includes/header.php";
?>

<!-- Main Content -->
<div class="container-fluid px-4">
    <div class="dashboard-header mb-4">
        <div class="row align-items-center">
            <div class="col">
                <h1 class="dashboard-title">Update Student</h1>
                <p class="text-muted">Update student information for: <strong><?= htmlspecialchars($student->Fname ?? '') ?> <?= htmlspecialchars($student->Lname ?? '') ?></strong></p>
            </div>
            <div class="col-auto">
                <a href="students_by_admin.php" class="btn btn-outline-secondary me-2">
                    <i class="fas fa-arrow-left me-2"></i>Back to Students
                </a>
                <a href="view_student.php?view=<?= urlencode($student_id) ?>" class="btn btn-outline-info">
                    <i class="fas fa-eye me-2"></i>View Profile
                </a>
            </div>
        </div>
    </div>

    <?php if(isset($_SESSION['success_msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
            echo htmlspecialchars($_SESSION['success_msg']);
            unset($_SESSION['success_msg']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if(isset($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
            echo htmlspecialchars($_SESSION['error_msg']);
            unset($_SESSION['error_msg']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Update Form -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Student Information</h5>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" id="updateStudentForm">
                <input type="hidden" name="student_id" value="<?= htmlspecialchars($student_id) ?>">
                
                <!-- Personal Information -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <h6 class="text-primary border-bottom pb-2 mb-3">
                            <i class="fas fa-user me-2"></i>Personal Information
                        </h6>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="fname" class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="fname" name="fname" 
                               value="<?= htmlspecialchars($student->Fname ?? '') ?>" required <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="lname" class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="lname" name="lname" 
                               value="<?= htmlspecialchars($student->Lname ?? '') ?>" required <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
                        <select class="form-select" id="gender" name="gender" required <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                            <option value="">Select Gender</option>
                            <option value="M" <?= ($student->sex ?? '') == 'M' ? 'selected' : '' ?>>Male</option>
                            <option value="F" <?= ($student->sex ?? '') == 'F' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="dob" class="form-label">Date of Birth <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="dob" name="dob" 
                               value="<?= htmlspecialchars($student->dob ?? '') ?>" required <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <h6 class="text-primary border-bottom pb-2 mb-3">
                            <i class="fas fa-address-book me-2"></i>Contact Information
                        </h6>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?= htmlspecialchars($student->email ?? '') ?>" required <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control" id="phone" name="phone" 
                               value="<?= htmlspecialchars($student->mobile ?? '') ?>" required <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="nrc" class="form-label">NRC/Passport <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="nrc" name="nrc" 
                               value="<?= htmlspecialchars($student->nrc_pass ?? '') ?>" required <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <h6 class="text-primary border-bottom pb-2 mb-3">
                            <i class="fas fa-graduation-cap me-2"></i>Academic Information
                        </h6>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="program" class="form-label">Program</label>
                        <select class="form-select" id="program" name="program" <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                            <option value="">Select Program/Course</option>
                            <?php $current_prog = $student_program->program_code ?? $student->program ?? ''; ?>
                            <optgroup label="Academic Programs">
                                <?php foreach($programs as $prog): ?>
                                    <option value="<?= htmlspecialchars($prog->program_code) ?>" 
                                        <?= $current_prog == $prog->program_code ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prog->program_name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Short Courses">
                                <?php foreach($short_courses as $sc): ?>
                                    <option value="<?= htmlspecialchars($sc->course_code) ?>" 
                                        <?= $current_prog == $sc->course_code ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sc->course_name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="semester" class="form-label">Academic Period</label>
                        <select class="form-select" id="semester" name="semester" <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                            <option value="">Select Term/Semester/Level</option>
                            <?php
                            $current_period = $student_program->term
                                ?? $student_program->semester
                                ?? $student_program->current_term_number
                                ?? $student_program->current_semester_number
                                ?? $student_program->current_level_number
                                ?? '';
                            ?>
                            <option value="1" <?= (string)$current_period === '1' ? 'selected' : '' ?>>1</option>
                            <option value="2" <?= (string)$current_period === '2' ? 'selected' : '' ?>>2</option>
                            <option value="3" <?= (string)$current_period === '3' ? 'selected' : '' ?>>3</option>
                        </select>
                    </div>
                </div>

                <!-- File Uploads -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <h6 class="text-primary border-bottom pb-2 mb-3">
                            <i class="fas fa-file-upload me-2"></i>Documents (Optional - Upload to Update)
                        </h6>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="profile_image" class="form-label">Profile Image</label>
                        <?php
                        $profileImgPath = 'images/avatar.png'; // default fallback
                        if (!empty($student->profile_image)) {
                            $imgPathOnDisk = dirname(__DIR__) . '/uploads/profile/' . $student->profile_image;
                            if (file_exists($imgPathOnDisk)) {
                                $profileImgPath = '../uploads/profile/' . $student->profile_image;
                            }
                        }
                        ?>
                        <div class="mb-2">
                            <img src="<?= htmlspecialchars($profileImgPath) ?>" 
                                 alt="Current Profile" class="img-thumbnail" style="max-height: 100px;">
                            <?php if (!empty($student->profile_image)): ?>
                                <small class="d-block text-muted">Current: <?= htmlspecialchars($student->profile_image) ?></small>
                            <?php endif; ?>
                        </div>
                        <input type="file" class="form-control" id="profile_image" name="profile_image" accept="image/*">
                        <small class="text-muted">JPG, PNG, GIF (Max 5MB)</small>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="nrc_file" class="form-label">NRC Document</label>
                        <?php if ($student->nrc_file ?? null): ?>
                            <div class="mb-2">
                                <a href="../uploads/nrc/<?= htmlspecialchars($student->nrc_file) ?>" 
                                   target="_blank" class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-file-pdf"></i> View Current
                                </a>
                                <small class="d-block text-muted"><?= htmlspecialchars($student->nrc_file) ?></small>
                            </div>
                        <?php endif; ?>
                        <input type="file" class="form-control" id="nrc_file" name="nrc_file" accept=".pdf,image/*" <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                        <small class="text-muted">PDF, JPG, PNG (Max 5MB)</small>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="results_file" class="form-label">Results Document</label>
                        <?php if ($student->results ?? null): ?>
                            <div class="mb-2">
                                <a href="../uploads/results/<?= htmlspecialchars($student->results) ?>" 
                                   target="_blank" class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-file-pdf"></i> View Current
                                </a>
                                <small class="d-block text-muted"><?= htmlspecialchars($student->results) ?></small>
                            </div>
                        <?php endif; ?>
                        <input type="file" class="form-control" id="results_file" name="results_file" accept=".pdf,image/*" <?php if (!$isRegistrarLevel) echo 'disabled'; ?>>
                        <small class="text-muted">PDF, JPG, PNG (Max 5MB)</small>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="d-flex justify-content-end gap-2">
                            <a href="students_by_admin.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" name="update_student" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Student
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Form validation
document.getElementById('updateStudentForm').addEventListener('submit', function(e) {
    const phone = document.getElementById('phone').value;
    const email = document.getElementById('email').value;
    
    // Basic phone validation (10-13 digits)
    const phoneRegex = /^[0-9]{10,13}$/;
    if (!phoneRegex.test(phone.replace(/[\s\-]/g, ''))) {
        e.preventDefault();
        alert('Please enter a valid phone number (10-13 digits)');
        return false;
    }
    
    // Email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        e.preventDefault();
        alert('Please enter a valid email address');
        return false;
    }
    
    return true;
});
</script>

<?php require 'includes/footer.php'; ?>
