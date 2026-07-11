<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/role_helpers.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method.';
    header('Location: ../user_role_mgmt.php');
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['error_message'] = 'Invalid security token. Please try again.';
    header('Location: ../user_role_mgmt.php');
    exit;
}

// Check Permissions
require_once __DIR__ . '/../../includes/permissions.php';
if (!function_exists('hasPermission') || (!hasPermission($_SESSION['staff_id'], 'admin_all') && !hasPermission($_SESSION['staff_id'], 'manage_roles'))) {
    $_SESSION['error_message'] = 'Access denied. You do not have permission to assign roles.';
    header('Location: ../user_role_mgmt.php');
    exit;
}

// Validate inputs
$staff_id = isset($_POST['staff_id']) ? trim($_POST['staff_id']) : '';
$PosID = isset($_POST['PosID']) ? trim($_POST['PosID']) : '';

if ($staff_id === '' || $PosID === '') {
    $_SESSION['error_message'] = 'Both Staff ID and Role are required fields.';
    header('Location: ../user_role_mgmt.php');
    exit;
}

// Validate staff_id format
if (!preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $staff_id)) {
    $_SESSION['error_message'] = 'Invalid Staff ID format.';
    header('Location: ../user_role_mgmt.php');
    exit;
}

// Validate PosID format (assuming numeric or alphanumeric)
if (!preg_match('/^[a-zA-Z0-9_-]{1,20}$/', $PosID)) {
    $_SESSION['error_message'] = 'Invalid Role ID format.';
    header('Location: ../user_role_mgmt.php');
    exit;
}

try {
    // Check if staff exists
    $check_staff = $db->prepare('SELECT staff_id FROM staff WHERE staff_id = ?');
    $check_staff->bind_param('s', $staff_id);
    $check_staff->execute();
    if ($check_staff->get_result()->num_rows === 0) {
        $_SESSION['error_message'] = "Staff ID '{$staff_id}' does not exist in the system.";
        header('Location: ../user_role_mgmt.php');
        exit;
    }

    // Check if role exists and get role name
    $check_role = $db->prepare('SELECT PosID, PosName FROM positions WHERE PosID = ?');
    $check_role->bind_param('s', $PosID);
    $check_role->execute();
    $role_result = $check_role->get_result();
    if ($role_result->num_rows === 0) {
        $_SESSION['error_message'] = 'The selected role does not exist.';
        header('Location: ../user_role_mgmt.php');
        exit;
    }
    $role_data = $role_result->fetch_assoc();
    $role_name = $role_data['PosName'];
    $roleCanonical = normalizeRole((string)$role_name);
    if ($roleCanonical === ROLE_SYSTEMS_ADMIN && ($_SESSION['role'] ?? '') !== ROLE_SYSTEMS_ADMIN) {
        $_SESSION['error_message'] = 'Only a Systems Administrator can assign Systems Administrator access.';
        header('Location: ../user_role_mgmt.php');
        exit;
    }

    // Check if role is already assigned
    $check_existing = $db->prepare('SELECT staff_id FROM staff_positions WHERE staff_id = ? AND PosID = ?');
    $check_existing->bind_param('ss', $staff_id, $PosID);
    $check_existing->execute();
    if ($check_existing->get_result()->num_rows > 0) {
        $_SESSION['error_message'] = "Role '{$role_name}' is already assigned to Staff ID '{$staff_id}'.";
        header('Location: ../user_role_mgmt.php');
        exit;
    }

    // Assign the role
    $stmt = $db->prepare('INSERT INTO staff_positions (staff_id, PosID) VALUES (?, ?)');
    $stmt->bind_param('ss', $staff_id, $PosID);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Successfully assigned role '{$role_name}' to Staff ID '{$staff_id}'.";
        error_log("Role assigned: {$role_name} to {$staff_id} by " . ($_SESSION['staff_id'] ?? 'unknown'));
    } else {
        throw new Exception('Failed to execute insert query: ' . $stmt->error);
    }
    
} catch (Exception $e) {
    error_log("Error in assign_role.php: " . $e->getMessage());
    $_SESSION['error_message'] = 'An error occurred while assigning the role. Please try again.';
}

header('Location: ../user_role_mgmt.php');
exit;
?>

