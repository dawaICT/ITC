<?php
/**
 * Access Granting Utility for WUC Portal
 * This tool helps administrators manage user access and roles
 */

http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo "This legacy access-granting utility has been disabled for security reasons.\n";
echo "Use the authenticated admin user/role management pages instead.\n";
exit;

session_start();

// Security check - allow access from admin areas, with staff session, or with bypass for testing
$allowed_referrers = ['admin', 'vc', 'hod', 'dean', 'registrar', 'grant_access'];
$referrer_allowed = false;

if (isset($_SERVER['HTTP_REFERER'])) {
    foreach ($allowed_referrers as $allowed) {
        if (strpos($_SERVER['HTTP_REFERER'], $allowed) !== false) {
            $referrer_allowed = true;
            break;
        }
    }
}

// Allow access if:
// 1. Coming from admin area (referrer check)
// 2. Has active staff session
// 3. Has bypass parameter (for testing/development)
$has_staff_session = isset($_SESSION['staff_id']) && !empty($_SESSION['staff_id']);
$has_bypass = isset($_GET['bypass']) && $_GET['bypass'] === 'admin_access_2024';

if (!$referrer_allowed && !$has_staff_session && !$has_bypass) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div style="text-align: center; margin: 50px; font-family: Arial, sans-serif;">';
    echo '<h1 style="color: #dc3545;">Access Denied</h1>';
    echo '<p>This tool can only be accessed by authorized administrators.</p>';
    echo '<hr style="margin: 30px 0;">';
    echo '<p style="font-size: 14px; color: #666;">To access the admin tool:</p>';
    echo '<ol style="text-align: left; display: inline-block; font-size: 14px; color: #666;">';
    echo '<li>Log in as an administrator first</li>';
    echo '<li>Navigate from an admin dashboard</li>';
    echo '<li>Or use the testing bypass: <code>?bypass=admin_access_2024</code></li>';
    echo '</ol>';
    echo '</div>';
    exit;
}

// If using bypass, show warning
if ($has_bypass) {
    echo '<div style="background: #fff3cd; color: #856404; padding: 12px; margin: 10px; border: 1px solid #ffeaa7; border-radius: 4px; text-align: center;">';
    echo '<strong>⚠️ TESTING MODE:</strong> You are accessing this tool in testing mode. Please ensure you have proper authorization.';
    echo '</div>';
}

require_once 'db/connect.php';
require_once 'includes/id_helpers.php';
require_once 'includes/role_helpers.php'; // getAvailableRoles() — canonical role values

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

function debug_log($message) {
    error_log('[Access Grant Tool] ' . $message);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleAccessGrant();
}

function handleAccessGrant() {
    global $db;

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'grant_student_access':
            grantStudentAccess();
            break;
        case 'grant_staff_access':
            grantStaffAccess();
            break;
        case 'create_user_credentials':
            createUserCredentials();
            break;
        case 'update_role':
            updateUserRole();
            break;
        case 'bulk_import':
            bulkImportUsers();
            break;
        default:
            $_SESSION['error'] = 'Unknown action requested.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
    }
}

function grantStudentAccess() {
    global $db;

    $student_id = trim($_POST['student_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($student_id) || empty($password)) {
        $_SESSION['error'] = 'Student ID and password are required.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Verify student exists
    $stmt = $db->prepare("SELECT SID FROM students WHERE SID = ?");
    $stmt->bind_param('s', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $_SESSION['error'] = 'Student not found in database.';
        $stmt->close();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $stmt->close();

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert or update student login
    $stmt = $db->prepare("INSERT INTO student_login (Sid, Password) VALUES (?, ?) ON DUPLICATE KEY UPDATE Password = ?");
    $stmt->bind_param('sss', $student_id, $hashed_password, $hashed_password);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Student access granted successfully for ID: $student_id";
        debug_log("Granted student access to: $student_id");
    } else {
        $_SESSION['error'] = 'Failed to grant student access: ' . $stmt->error;
        debug_log("Failed to grant student access to: $student_id - " . $stmt->error);
    }

    $stmt->close();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function createUserCredentials() {
    global $db;

    $staff_id = trim($_POST['staff_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($staff_id) || empty($password)) {
        $_SESSION['error'] = 'Staff ID and password are required.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Verify staff exists
    $stmt = $db->prepare("SELECT staff_id FROM staff WHERE staff_id = ?");
    $stmt->bind_param('s', $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $_SESSION['error'] = 'Staff member not found in database.';
        $stmt->close();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $stmt->close();

    // Hash the password with BCRYPT
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Insert or update user credentials
    $stmt = $db->prepare("INSERT INTO user_credentials (staff_id, pass) VALUES (?, ?) ON DUPLICATE KEY UPDATE pass = ?");
    $stmt->bind_param('sss', $staff_id, $hashed_password, $hashed_password);

    if ($stmt->execute()) {
        $_SESSION['success'] = "User credentials created successfully for Staff ID: $staff_id";
        debug_log("Created user credentials for staff: $staff_id");
    } else {
        $_SESSION['error'] = 'Failed to create user credentials: ' . $stmt->error;
        debug_log("Failed to create user credentials for: $staff_id - " . $stmt->error);
    }

    $stmt->close();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function updateUserRole() {
    global $db;

    $staff_id = trim($_POST['staff_id'] ?? '');
    $role = trim($_POST['role'] ?? '');

    if (empty($staff_id) || empty($role)) {
        $_SESSION['error'] = 'Staff ID and role are required.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Insert or update access_right entry
    $stmt = $db->prepare("INSERT INTO access_right (UserID, staff_id, assigned_access) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE assigned_access = ?");
    $stmt->bind_param('ssss', $staff_id, $staff_id, $role, $role);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Role updated successfully for Staff ID: $staff_id to '$role'";
        debug_log("Updated role for staff: $staff_id to '$role'");
    } else {
        $_SESSION['error'] = 'Failed to update role: ' . $stmt->error;
        debug_log("Failed to update role for: $staff_id - " . $stmt->error);
    }

    $stmt->close();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function bulkImportUsers() {
    global $db;

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = 'Please select a valid CSV file.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $file = $_FILES['csv_file']['tmp_name'];
    $import_type = $_POST['import_type'] ?? 'staff';

    $success_count = 0;
    $error_count = 0;
    $errors = [];

    if (($handle = fopen($file, "r")) !== FALSE) {
        $header = fgetcsv($handle, 1000, ","); // Skip header row

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            try {
                if ($import_type === 'staff') {
                    // Expected CSV format: staff_id, password, role
                    if (count($data) >= 3) {
                        $staff_id = trim($data[0]);
                        $password = $data[1];
                        $role = trim($data[2]);

                        // Create user credentials
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO user_credentials (staff_id, pass) VALUES (?, ?) ON DUPLICATE KEY UPDATE pass = ?");
                        $stmt->bind_param('sss', $staff_id, $hashed_password, $hashed_password);
                        $stmt->execute();
                        $stmt->close();

                        // Set role
                        $stmt = $db->prepare("INSERT INTO access_right (UserID, staff_id, assigned_access) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE assigned_access = ?");
                        $stmt->bind_param('ssss', $staff_id, $staff_id, $role, $role);
                        $stmt->execute();
                        $stmt->close();

                        $success_count++;
                    } else {
                        $error_count++;
                        $errors[] = "Invalid CSV format at line " . ($success_count + $error_count + 1);
                    }
                } elseif ($import_type === 'student') {
                    // Expected CSV format: student_id, password
                    if (count($data) >= 2) {
                        $student_id = trim($data[0]);
                        $password = $data[1];
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                        $stmt = $db->prepare("INSERT INTO student_login (Sid, Password) VALUES (?, ?) ON DUPLICATE KEY UPDATE Password = ?");
                        $stmt->bind_param('sss', $student_id, $hashed_password, $hashed_password);
                        $stmt->execute();
                        $stmt->close();

                        $success_count++;
                    } else {
                        $error_count++;
                        $errors[] = "Invalid CSV format at line " . ($success_count + $error_count + 1);
                    }
                }
            } catch (Exception $e) {
                $error_count++;
                $errors[] = "Error at line " . ($success_count + $error_count + 1) . ": " . $e->getMessage();
            }
        }
        fclose($handle);
    }

    $message = "Bulk import completed. Success: $success_count, Errors: $error_count";
    if (!empty($errors)) {
        $message .= "<br>Errors:<br>" . implode("<br>", $errors);
    }

    $_SESSION['success'] = $message;
    debug_log("Bulk import completed - Success: $success_count, Errors: $error_count");
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Get existing users for display
function getExistingUsers($type = 'staff') {
    global $db;

    if ($type === 'staff') {
        $query = "SELECT s.staff_id, s.Fname, s.Lname, s.title, ar.assigned_access, uc.pass IS NOT NULL as has_credentials
                  FROM staff s
                  LEFT JOIN access_right ar ON s.staff_id = ar.staff_id
                  LEFT JOIN user_credentials uc ON s.staff_id = uc.staff_id
                  ORDER BY s.staff_id";
    } else {
        $query = "SELECT st.SID, st.Fname, st.Lname, sl.Password IS NOT NULL as has_access
                  FROM students st
                  LEFT JOIN student_login sl ON st.SID = sl.Sid
                  ORDER BY st.SID";
    }

    $result = $db->query($query);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Management Tool</title>
    <link rel="stylesheet" href="w3/w3.css">
    <link rel="stylesheet" href="css_main/style.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .section { margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .btn-primary { background-color: #007bff; color: white; }
        .btn-success { background-color: #28a745; color: white; }
        .btn-warning { background-color: #ffc107; color: black; }
        .alert { padding: 15px; margin: 20px 0; border: 1px solid transparent; border-radius: 4px; }
        .alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
        .alert-error { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
        .user-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .user-table th, .user-table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .user-table th { background-color: #f8f9fa; font-weight: bold; }
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-active { background-color: #d4edda; color: #155724; }
        .status-inactive { background-color: #f8d7da; color: #721c24; }
        .tabs { display: flex; margin-bottom: 20px; }
        .tab { padding: 10px 20px; cursor: pointer; background-color: #f8f9fa; border: 1px solid #ddd; }
        .tab.active { background-color: #007bff; color: white; border-color: #007bff; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>
    <div class="container">
        <h1>ITC Portal - Access Management Tool</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div class="tabs">
            <div class="tab active" onclick="showTab('staff')">Staff Access</div>
            <div class="tab" onclick="showTab('student')">Student Access</div>
            <div class="tab" onclick="showTab('bulk')">Bulk Import</div>
        </div>

        <!-- Staff Access Tab -->
        <div id="staff" class="tab-content active">
            <div class="section">
                <h3>Create Staff User Credentials</h3>
                <form method="post" action="">
                    <input type="hidden" name="action" value="create_user_credentials">
                    <div class="form-group">
                        <label for="staff_id">Staff ID:</label>
                        <input type="text" id="staff_id" name="staff_id" placeholder="ITC001" required pattern="^ITC\d{3}$">
                    </div>
                    <div class="form-group">
                        <label for="staff_password">Password:</label>
                        <input type="password" id="staff_password" name="password" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary">Create Credentials</button>
                </form>
            </div>

            <div class="section">
                <h3>Update Staff Role</h3>
                <form method="post" action="">
                    <input type="hidden" name="action" value="update_role">
                    <div class="form-group">
                        <label for="update_staff_id">Staff ID:</label>
                        <input type="text" id="update_staff_id" name="staff_id" placeholder="ITC001" required pattern="^ITC\d{3}$">
                    </div>
                    <div class="form-group">
                        <label for="role">Role:</label>
                        <select id="role" name="role" required>
                            <option value="">Select Role</option>
                            <?php foreach (getAvailableRoles() as $displayName => $roleValue): ?>
                            <option value="<?php echo htmlspecialchars($roleValue); ?>"><?php echo htmlspecialchars($displayName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success">Update Role</button>
                </form>
            </div>

            <div class="section">
                <h3>Existing Staff Members</h3>
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>Staff ID</th>
                            <th>Name</th>
                            <th>Title</th>
                            <th>Assigned Role</th>
                            <th>Has Credentials</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (getExistingUsers('staff') as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['staff_id']); ?></td>
                                <td><?php echo htmlspecialchars($user['Fname'] . ' ' . $user['Lname']); ?></td>
                                <td><?php echo htmlspecialchars($user['title']); ?></td>
                                <td><?php echo htmlspecialchars($user['assigned_access'] ?? 'Not Set'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $user['has_credentials'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $user['has_credentials'] ? 'Yes' : 'No'; ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" action="" style="display: inline;">
                                        <input type="hidden" name="action" value="create_user_credentials">
                                        <input type="hidden" name="staff_id" value="<?php echo $user['staff_id']; ?>">
                                        <input type="password" name="password" placeholder="New Password" required minlength="8" style="width: auto;">
                                        <button type="submit" class="btn btn-warning">Reset Password</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Student Access Tab -->
        <div id="student" class="tab-content">
            <div class="section">
                <h3>Grant Student Access</h3>
                <form method="post" action="">
                    <input type="hidden" name="action" value="grant_student_access">
                    <div class="form-group">
                        <label for="student_id">Student ID:</label>
                        <input type="text" id="student_id" name="student_id" placeholder="LVTC001" required>
                    </div>
                    <div class="form-group">
                        <label for="student_password">Password:</label>
                        <input type="password" id="student_password" name="password" required minlength="4">
                    </div>
                    <button type="submit" class="btn btn-primary">Grant Access</button>
                </form>
            </div>

            <div class="section">
                <h3>Students with Access (First 50)</h3>
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Has Access</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice(getExistingUsers('student'), 0, 50) as $student): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($student['SID']); ?></td>
                                <td><?php echo htmlspecialchars($student['Fname'] . ' ' . $student['Lname']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $student['has_access'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $student['has_access'] ? 'Yes' : 'No'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Bulk Import Tab -->
        <div id="bulk" class="tab-content">
            <div class="section">
                <h3>Bulk Import Users</h3>
                <form method="post" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="bulk_import">
                    <div class="form-group">
                        <label for="import_type">Import Type:</label>
                        <select id="import_type" name="import_type" required>
                            <option value="staff">Staff (CSV: staff_id,password,role)</option>
                            <option value="student">Students (CSV: student_id,password)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="csv_file">CSV File:</label>
                        <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-success">Import Users</button>
                </form>
                <p><strong>CSV Format Examples:</strong></p>
                <p><strong>Staff:</strong> ITC001,password123,lecturer</p>
                <p><strong>Students:</strong> LVTC001,password123</p>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tab contents
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));

            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));

            // Show selected tab content
            document.getElementById(tabName).classList.add('active');

            // Add active class to clicked tab
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
