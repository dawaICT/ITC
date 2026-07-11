<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../includes/admin.php'; // DB connection $db
require_once __DIR__ . '/../../includes/permissions.php';

// 1. Validate CSRF Token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error_message'] = "Security validation failed. Please refresh and try again.";
    header('Location: ../user_role_mgmt.php');
    exit;
}

// 2. Authorization Check
if (!hasPermission($_SESSION['staff_id'], 'manage_roles')) {
    $_SESSION['error_message'] = "Unauthorized: Access denied.";
    header('Location: ../user_role_mgmt.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_id'])) {
    $target_staff_id = trim($_POST['staff_id']);
    $current_admin_id = $_SESSION['staff_id'];

    // 3. Safety Check: Prevent self-deletion
    if ($target_staff_id === $current_admin_id) {
        $_SESSION['error_message'] = "Safety Alert: You cannot remove your own roles. Please ask another administrator for assistance.";
        header('Location: ../user_role_mgmt.php');
        exit;
    }

    try {
        // 4. Perform Removal
        $stmt = $db->prepare("DELETE FROM staff_positions WHERE staff_id = ?");
        $stmt->bind_param("s", $target_staff_id);
        
        if ($stmt->execute()) {
            if ($db->affected_rows > 0) {
                $_SESSION['success_message'] = "All roles successfully removed for Staff ID: $target_staff_id.";
            } else {
                $_SESSION['error_message'] = "No roles were found for this user.";
            }
        } else {
            throw new mysqli_sql_exception($db->error);
        }

    } catch (Exception $e) {
        error_log("Error removing roles: " . $e->getMessage());
        $_SESSION['error_message'] = "System error: Could not complete role removal.";
    }
}

header('Location: ../user_role_mgmt.php');
exit;
