<?php
/**
 * Edit Staff Member
 * 
 * Allows editing of staff personal and professional details.
 * Uses consistent admin styling and validation.
 */

require "includes/admin.php";
require_once "../includes/role_helpers.php";
require_once "../includes/schema_helpers.php";
error_reporting(E_ALL); 
// Header will be included after the processing logic to handle redirects properly

// Initialize variables
$staff = null;
$error = null;
$success = null;
$departments = [];
$staffColumns = wuc_table_columns($db, 'staff');
$staffDeptCol = wuc_detect_column($db, 'staff', ['deptId', 'DeptID', 'department_id']);
$deptIdCol = wuc_detect_column($db, 'departments', ['department_id', 'DeptID', 'id', 'deptId']);
$deptNameCol = wuc_detect_column($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);

// Fetch Departments regardless of mode (for the dropdown)
try {
    if ($deptIdCol && $deptNameCol && ($dept_query = $db->query("SELECT `{$deptIdCol}` AS deptId, `{$deptNameCol}` AS department_name FROM departments ORDER BY `{$deptNameCol}`"))) {
        while ($row = $dept_query->fetch_assoc()) {
            $departments[] = $row;
        }
        $dept_query->free();
    }
} catch (Exception $e) {
    error_log("Error fetching departments: " . $e->getMessage());
}

// HANDLE FORM SUBMISSION
if (isset($_POST['update_staff'])) {
    $staff_id = trim($_POST["staff_id"] ?? '');
    $deptId = trim($_POST["deptId"] ?? '');
    $title = trim($_POST["title"] ?? '');
    $Fname = trim($_POST["Fname"] ?? '');
    $Lname = trim($_POST["Lname"] ?? '');
    $sex = trim($_POST["sex"] ?? '');
    $country = trim($_POST["country"] ?? '');
    $nrc_pass = trim($_POST["nrc_pass"] ?? '');
    $mobile = trim($_POST["mobile"] ?? '');
    $email = trim($_POST["email"] ?? '');
    $address = trim($_POST["address"] ?? '');
    $qualification = trim($_POST["qualification"] ?? '');
    $role = is_array($_POST['role'] ?? null)
        ? 'Staff'
        : trim((string) ($_POST['role'] ?? 'Staff'));
    $roleCanonical = normalizeRole($role);

    // Basic Validation
    if (empty($staff_id) || empty($Fname) || empty($Lname)) {
        $_SESSION['errorMssg'] = "Please fill in all required fields.";
    } elseif (!isValidStaffRole($role)) {
        $_SESSION['errorMssg'] = "Invalid staff role selected.";
    } elseif ($roleCanonical === ROLE_SYSTEMS_ADMIN && ($_SESSION['role'] ?? '') !== ROLE_SYSTEMS_ADMIN) {
        $_SESSION['errorMssg'] = "Only a Systems Administrator can assign Systems Administrator access.";
    } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,50}$/', $staff_id)) {
        $_SESSION['errorMssg'] = "Invalid staff ID format.";
    } elseif (!preg_match('/^[A-Za-z .\'-]{1,100}$/', $Fname . ' ' . $Lname)) {
        $_SESSION['errorMssg'] = "Staff first and last name may only contain letters, spaces, apostrophes, periods, or hyphens.";
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['errorMssg'] = "Invalid email format.";
    } elseif ($mobile !== '' && !preg_match('/^[0-9+() .-]{6,40}$/', $mobile)) {
        $_SESSION['errorMssg'] = "Invalid mobile number format.";
    } else {
        $fieldValues = [
            $staffDeptCol => $deptId,
            'title' => $title,
            'Fname' => $Fname,
            'Lname' => $Lname,
            'sex' => $sex,
            'country' => $country,
            'nrc_pass' => $nrc_pass,
            'mobile' => $mobile,
            'email' => $email,
            'address' => $address,
            'qualification' => $qualification,
            'role' => $roleCanonical,
        ];

        $sets = [];
        $types = '';
        $params = [];
        foreach ($fieldValues as $column => $value) {
            if ($column !== null && isset($staffColumns[strtolower($column)])) {
                $sets[] = "`{$staffColumns[strtolower($column)]}` = ?";
                $types .= 's';
                $params[] = $value;
            }
        }

        if (empty($sets)) {
            $_SESSION['errorMssg'] = "No editable staff columns were found in the current schema.";
            $stmt = false;
        } else {
            $params[] = $staff_id;
            $types .= 's';
            $stmt = $db->prepare("UPDATE staff SET " . implode(', ', $sets) . " WHERE staff_id = ?");
        }
        
        if ($stmt) {
            wuc_bind_param_array($stmt, $types, $params);
            
            if ($stmt->execute()) {
                // Update role in access_right table
                if (!empty($roleCanonical)) {
                    setStaffRole($db, $staff_id, $roleCanonical);
                }
                
                $_SESSION['successMssg'] = "Staff member <strong>$Fname $Lname</strong> updated successfully.";
                header("Location: staff.php");
                exit();
            } else {
                $_SESSION['errorMssg'] = "Database update failed: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['errorMssg'] = "Database preparation failed: " . $db->error;
        }
    }
}

// FETCH STAFF DATA IF ID PROVIDED
$edit_id = $_GET['update'] ?? ($_POST['staff_id'] ?? null);

if ($edit_id) {
    $stmt = $db->prepare("SELECT s.*, COALESCE(ar.assigned_access, 'Staff') as role 
                          FROM staff s 
                          LEFT JOIN access_right ar ON s.staff_id = ar.staff_id 
                          WHERE s.staff_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $edit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $staff = $result->fetch_object();
        } else {
            $_SESSION['errorMssg'] = "Staff member with ID $edit_id not found.";
            header("Location: staff.php");
            exit();
        }
        $stmt->close();
    }
} else {
    // If no ID provided and not a post back with ID, redirect
    header("Location: staff.php");
    exit();
}

// Include Header (starts output)
require 'includes/header.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <!-- Page Header -->
    <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="page-title mb-0">Edit Staff Profile</h5>
                <p class="page-subtitle mb-0 text-muted">Update details for <?php echo htmlspecialchars(trim(wuc_object_value($staff, 'Fname') . ' ' . wuc_object_value($staff, 'Lname'))); ?></p>
            </div>
            <a href="staff.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Staff List
            </a>
        </div>
    </div>

    <?php if(isset($_SESSION['errorMssg'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['errorMssg']; unset($_SESSION['errorMssg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <div class="avatar-circle me-3 bg-primary text-white d-flex align-items-center justify-content-center rounded-circle" style="width: 48px; height: 48px; font-size: 1.2rem; font-weight: bold;">
                            <?php echo strtoupper(substr(wuc_object_value($staff, 'Fname'), 0, 1) . substr(wuc_object_value($staff, 'Lname'), 0, 1)); ?>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold text-primary">Personal & Professional Information</h6>
                            <small class="text-muted">Staff ID: <?php echo htmlspecialchars(wuc_object_value($staff, 'staff_id')); ?></small>
                        </div>
                    </div>
                </div>
                
                <div class="card-body p-4">
                    <form action="editStaff.php" method="post" class="needs-validation" novalidate>
                        <!-- Hidden Staff ID (PK) -->
                        <input type="hidden" name="staff_id" value="<?php echo htmlspecialchars(wuc_object_value($staff, 'staff_id')); ?>">

                        <h6 class="text-uppercase text-muted fw-bold small mb-3">Academic & Department Details</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <label for="staff_id_display" class="form-label">Staff ID</label>
                                <input type="text" class="form-control bg-light" id="staff_id_display" value="<?php echo htmlspecialchars(wuc_object_value($staff, 'staff_id')); ?>" disabled readonly>
                                <div class="form-text">Staff ID cannot be changed once created.</div>
                            </div>
                            <?php if ($staffDeptCol): ?>
                                <div class="col-md-3">
                                    <label for="deptId" class="form-label">Department <span class="text-danger">*</span></label>
                                    <select class="form-select" id="deptId" name="deptId" required>
                                        <option value="" disabled>Select Department</option>
                                        <?php foreach($departments as $dept): ?>
                                            <option value="<?php echo htmlspecialchars((string)$dept['deptId']); ?>" <?php echo (wuc_object_value($staff, $staffDeptCol) === (string)$dept['deptId']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['department_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="deptId" value="">
                            <?php endif; ?>
                            <div class="col-md-3">
                                <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-select" id="role" name="role" required>
                                    <?php $currentRoleCanonical = normalizeRole(wuc_object_value($staff, 'role')); ?>
                                    <?php foreach (getAvailableRoles() as $displayName => $roleValue): ?>
                                    <option value="<?php echo htmlspecialchars($roleValue); ?>" <?php echo ($currentRoleCanonical === $roleValue) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($displayName); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                                <select class="form-select" id="title" name="title" required>
                                    <?php
                                    $titles = ['Mr.', 'Mrs.', 'Ms.', 'Mss.', 'Dr.', 'Prof.', 'Sir.', 'Eng.'];
                                    foreach($titles as $t) {
                                        $selected = (wuc_object_value($staff, 'title') === $t) ? 'selected' : '';
                                        echo "<option value=\"$t\" $selected>$t</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <hr class="border-light my-4">

                        <h6 class="text-uppercase text-muted fw-bold small mb-3">Personal Information</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="Fname" class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="Fname" name="Fname" value="<?php echo htmlspecialchars(wuc_object_value($staff, 'Fname')); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="Lname" class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="Lname" name="Lname" value="<?php echo htmlspecialchars(wuc_object_value($staff, 'Lname')); ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="sex" class="form-label">Gender <span class="text-danger">*</span></label>
                                <select class="form-select" id="sex" name="sex" required>
                                    <option value="Male" <?php echo (wuc_object_value($staff, 'sex') === 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo (wuc_object_value($staff, 'sex') === 'Female') ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="nrc_pass" class="form-label">NRC / Passport ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nrc_pass" name="nrc_pass" value="<?php echo htmlspecialchars(wuc_object_value($staff, 'nrc_pass')); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="country" class="form-label">Country</label>
                                <select class="form-select" id="country" name="country">
                                    <option value="<?php echo htmlspecialchars(wuc_object_value($staff, 'country')); ?>" selected><?php echo htmlspecialchars(wuc_object_value($staff, 'country', 'Not set')); ?></option>
                                    <!-- Full list would be loaded here or kept simple if just editing -->
                                    <?php 
                                    // Common countries short list
                                    $common_countries = ['Zambia', 'Zimbabwe', 'Malawi', 'South Africa', 'Botswana', 'Kenya'];
                                    foreach($common_countries as $c) {
                                         if($c !== wuc_object_value($staff, 'country')) echo "<option value=\"$c\">$c</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <hr class="border-light my-4">

                        <h6 class="text-uppercase text-muted fw-bold small mb-3">Contact Details</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars(wuc_object_value($staff, 'email')); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="mobile" class="form-label">Mobile Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fas fa-phone text-muted"></i></span>
                                    <input type="text" class="form-control" id="mobile" name="mobile" value="<?php echo htmlspecialchars(wuc_object_value($staff, 'mobile')); ?>" required>
                                </div>
                            </div>
                            <div class="col-12">
                                <label for="address" class="form-label">Physical Address</label>
                                <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars(wuc_object_value($staff, 'address')); ?></textarea>
                            </div>
                        </div>

                        <hr class="border-light my-4">

                        <h6 class="text-uppercase text-muted fw-bold small mb-3">Qualifications</h6>
                        <div class="row g-3">
                            <div class="col-12">
                                <label for="qualification" class="form-label">Professional Qualifications</label>
                                <input type="text" class="form-control" id="qualification" name="qualification" value="<?php echo htmlspecialchars(wuc_object_value($staff, 'qualification')); ?>" placeholder="e.g. PhD Computer Science, MSc Data Analysis">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-5">
                            <a href="staff.php" class="btn btn-light border">Cancel</a>
                            <button type="submit" name="update_staff" class="btn btn-primary px-4">
                                <i class="fas fa-save me-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require "includes/footer.php"; ?>

