<?php
$page_title = 'Edit Profile';
require "includes/nav.php";
require_once "../includes/schema_helpers.php";
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Ensure records array is initialized
$Records = array();

// Fetch staff information
$staff_id = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$staffColumns = wuc_table_columns($db, 'staff');
$staffDeptCol = wuc_detect_column($db, 'staff', ['deptId', 'DeptID', 'department_id']);
$deptIdCol = wuc_detect_column($db, 'departments', ['department_id', 'DeptID', 'id', 'deptId']);
$deptNameCol = wuc_detect_column($db, 'departments', ['department_name', 'DeptName', 'deptName', 'name']);
$deptJoin = '';
$deptSelect = 'NULL AS deptName';
if ($staffDeptCol && $deptIdCol && $deptNameCol) {
    $deptJoin = "LEFT JOIN departments d ON CAST(s.`{$staffDeptCol}` AS CHAR) = CAST(d.`{$deptIdCol}` AS CHAR)";
    $deptSelect = "d.`{$deptNameCol}` AS deptName";
}

$query = "SELECT s.*, {$deptSelect} FROM staff s {$deptJoin} WHERE s.staff_id = ? LIMIT 1";
$stmt = $db->prepare($query);
if ($staff_id !== '' && $stmt) {
    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_object()) {
        $Records[] = $row;
    }
    $stmt->close();
} else {
    error_log("Failed to prepare statement: " . $db->error);
}

// Handle password change
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    $errors = [];
    
    if (empty($current_password)) {
        $errors[] = "Current password is required";
    }
    if (empty($new_password)) {
        $errors[] = "New password is required";
    } elseif (strlen($new_password) < 6) {
        $errors[] = "New password must be at least 6 characters long";
    }
    if ($new_password !== $confirm_password) {
        $errors[] = "New passwords do not match";
    }
    
    if (empty($errors)) {
        // Verify current password
        $stmt = $db->prepare("SELECT pass FROM user_credentials WHERE staff_id = ?");
        $stmt->bind_param("s", $staff_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (password_verify($current_password, $row['pass'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $db->prepare("UPDATE user_credentials SET pass = ? WHERE staff_id = ?");
                $update_stmt->bind_param("ss", $hashed_password, $staff_id);
                
                if ($update_stmt->execute()) {
                    $_SESSION['success_message'] = 'Password changed successfully!';
                    header('Location: index.php');
                    exit;
                } else {
                    $error_message = 'Error updating password: ' . $update_stmt->error;
                }
                $update_stmt->close();
            } else {
                $error_message = 'Current password is incorrect';
            }
        } else {
            $error_message = 'User credentials not found';
        }
        $stmt->close();
    } else {
        $error_message = implode('<br>', $errors);
    }
}

// Handle profile update
if (isset($_POST['update_profile'])) {
    // Validate required fields
    $required_fields = ['title', 'fname', 'lname', 'sex', 'mobile', 'email'];
    if (isset($staffColumns['nrc_pass'])) {
        $required_fields[] = 'nrc_pass';
    }
    $errors = [];
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst($field) . " is required";
        }
    }
    
    // Validate email
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Validate mobile (basic check)
    if (!empty($_POST['mobile']) && !preg_match('/^[0-9+\-\s()]+$/', $_POST['mobile'])) {
        $errors[] = "Invalid mobile number format";
    }
    
    // Validate date of birth
    if (!empty($_POST['date_of_birth']) && !strtotime($_POST['date_of_birth'])) {
        $errors[] = "Invalid date of birth format";
    }
    
    // Validate employment date
    if (!empty($_POST['employment_date']) && !strtotime($_POST['employment_date'])) {
        $errors[] = "Invalid employment date format";
    }
    
    if (empty($errors)) {
        $title = trim($_POST['title']);
        $fname = trim($_POST['fname']);
        $lname = trim($_POST['lname']);
        $sex = $_POST['sex'];
        $nrc_pass = trim($_POST['nrc_pass']);
        $mobile = trim($_POST['mobile']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $qualification = trim($_POST['qualification'] ?? '');
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $secondary_phone = trim($_POST['secondary_phone'] ?? '');
        $emergency_contact = trim($_POST['emergency_contact'] ?? '');
        $emergency_phone = trim($_POST['emergency_phone'] ?? '');
        $employment_date = !empty($_POST['employment_date']) ? $_POST['employment_date'] : null;
        $position = trim($_POST['position'] ?? '');

        // Handle profile image upload
        $profile_image = '';
        if (isset($staffColumns['profile_image']) && isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($_FILES['profile_image']['type'], $allowed_types)) {
                $errors[] = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
            } elseif ($_FILES['profile_image']['size'] > $max_size) {
                $errors[] = "File size too large. Maximum 5MB allowed.";
            } else {
                $target_dir = "../uploads/profile/";
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0755, true);
                }
                $file_extension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                $new_filename = $staff_id . '_' . time() . '.' . $file_extension;
                $target_file = $target_dir . $new_filename;

                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                    $profile_image = $new_filename;
                } else {
                    $errors[] = "Failed to upload image.";
                }
            }
        }

        if (empty($errors)) {
            // Update query
            $fieldValues = [
                'title' => $title,
                'Fname' => $fname,
                'Lname' => $lname,
                'sex' => $sex,
                'nrc_pass' => $nrc_pass,
                'mobile' => $mobile,
                'email' => $email,
                'address' => $address,
                'country' => $country,
                'qualification' => $qualification,
                'date_of_birth' => $date_of_birth,
                'secondary_phone' => $secondary_phone,
                'emergency_contact' => $emergency_contact,
                'emergency_phone' => $emergency_phone,
                'employment_date' => $employment_date,
                'position' => $position,
            ];
            if (!empty($profile_image)) {
                $fieldValues['profile_image'] = $profile_image;
            }

            $sets = [];
            $types = '';
            $params = [];
            foreach ($fieldValues as $column => $value) {
                $columnKey = strtolower($column);
                if (isset($staffColumns[$columnKey])) {
                    $sets[] = "`{$staffColumns[$columnKey]}` = ?";
                    $types .= 's';
                    $params[] = $value;
                }
            }

            if (empty($sets)) {
                $error_message = 'No editable staff columns were found in the current schema.';
                $stmt = false;
            } else {
                $params[] = $staff_id;
                $types .= 's';
                $stmt = $db->prepare("UPDATE staff SET " . implode(', ', $sets) . " WHERE staff_id = ?");
            }

            if ($stmt && wuc_bind_param_array($stmt, $types, $params) && $stmt->execute()) {
                $_SESSION['success_message'] = 'Profile updated successfully!';
                header('Location: index.php');
                exit;
            } else {
                $error_message = 'Error updating profile: ' . ($stmt ? $stmt->error : $db->error);
            }
            if ($stmt) {
                $stmt->close();
            }
        } else {
            $error_message = implode('<br>', $errors);
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}

if (!empty($Records)) {
    $r = $Records[0];
?>
<div class="container-fluid px-4 py-4 portal-dashboard">
    <div class="row justify-content-center">
        <div class="col-xl-8">
            <div class="data-table-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-user-edit me-2"></i>Edit Profile
                        </h5>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $error_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="title" class="form-label">Title</label>
                                <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars(wuc_object_value($r, 'title')); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="fname" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="fname" name="fname" value="<?php echo htmlspecialchars(wuc_object_value($r, 'Fname')); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="lname" class="form-label">Surname</label>
                                <input type="text" class="form-control" id="lname" name="lname" value="<?php echo htmlspecialchars(wuc_object_value($r, 'Lname')); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="sex" class="form-label">Gender</label>
                                <select class="form-control" id="sex" name="sex" required>
                                    <option value="Male" <?php echo (wuc_object_value($r, 'sex') === 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo (wuc_object_value($r, 'sex') === 'Female') ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="nrc_pass" class="form-label">National ID</label>
                                <input type="text" class="form-control" id="nrc_pass" name="nrc_pass" value="<?php echo htmlspecialchars(wuc_object_value($r, 'nrc_pass')); ?>" <?php echo isset($staffColumns['nrc_pass']) ? 'required' : ''; ?>>
                            </div>
                            <div class="col-md-6">
                                <label for="mobile" class="form-label">Mobile</label>
                                <input type="text" class="form-control" id="mobile" name="mobile" value="<?php echo htmlspecialchars(wuc_object_value($r, 'mobile')); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars(wuc_object_value($r, 'email')); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="address" class="form-label">Address</label>
                                <input type="text" class="form-control" id="address" name="address" value="<?php echo htmlspecialchars(wuc_object_value($r, 'address')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="country" class="form-label">Country</label>
                                <input type="text" class="form-control" id="country" name="country" value="<?php echo htmlspecialchars(wuc_object_value($r, 'country')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="qualification" class="form-label">Qualification</label>
                                <input type="text" class="form-control" id="qualification" name="qualification" value="<?php echo htmlspecialchars(wuc_object_value($r, 'qualification')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="date_of_birth" class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars(wuc_object_value($r, 'date_of_birth')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="secondary_phone" class="form-label">Secondary Phone</label>
                                <input type="text" class="form-control" id="secondary_phone" name="secondary_phone" value="<?php echo htmlspecialchars(wuc_object_value($r, 'secondary_phone')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="emergency_contact" class="form-label">Emergency Contact</label>
                                <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" value="<?php echo htmlspecialchars(wuc_object_value($r, 'emergency_contact')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="emergency_phone" class="form-label">Emergency Phone</label>
                                <input type="text" class="form-control" id="emergency_phone" name="emergency_phone" value="<?php echo htmlspecialchars(wuc_object_value($r, 'emergency_phone')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="employment_date" class="form-label">Employment Date</label>
                                <input type="date" class="form-control" id="employment_date" name="employment_date" value="<?php echo htmlspecialchars(wuc_object_value($r, 'employment_date')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="position" class="form-label">Position</label>
                                <input type="text" class="form-control" id="position" name="position" value="<?php echo htmlspecialchars(wuc_object_value($r, 'position')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="profile_image" class="form-label">Profile Image</label>
                                <input type="file" class="form-control" id="profile_image" name="profile_image" accept="image/*">
                                <?php if (wuc_object_value($r, 'profile_image') !== '') { ?>
                                    <small class="text-muted">Current image: <img src="../uploads/profile/<?php echo htmlspecialchars(wuc_object_value($r, 'profile_image')); ?>" width="50" height="50" class="mt-1 rounded"></small>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Profile
                            </button>
                            <a href="index.php" class="btn btn-secondary ms-2">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                    
                    <hr class="my-4">
                    
                    <h5 class="mb-3">
                        <i class="fas fa-lock me-2"></i>Change Password
                    </h5>
                    <form method="POST">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>
                            <div class="col-md-6">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                                <small class="text-muted">Minimum 6 characters</small>
                            </div>
                            <div class="col-md-6">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" name="change_password" class="btn btn-warning">
                                <i class="fas fa-key me-2"></i>Change Password
                            </button>
                        </div>
                    </form>
<?php
} else {
    echo "<div class='alert alert-danger'>Staff record not found.</div>";
}
require "includes/footer.php";
?>
