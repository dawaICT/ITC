<?php
/**
 * Delete Staff Member (Soft Delete)
 * 
 * Implements soft delete to preserve historical data.
 * Checks for active dependencies before deletion.
 */

require "includes/admin.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/action_confirmation.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['del'])) {
    wuc_render_action_confirmation('Remove staff member?', 'The account will be deactivated after dependency checks.', 'deleteStaff.php', ['staff_id' => trim((string)$_GET['del'])]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request.');
}

// Ensure an ID is provided
if (!empty($_POST['staff_id'])) {
    $staff_id = trim((string)$_POST['staff_id']);

    // 1. Check for Active Role Assignments (Dependency Check)
    // We check 'access_right' table to see if they hold critical system roles
    $check_stmt = $db->prepare("SELECT COUNT(*) as active_roles FROM access_right WHERE staff_id = ?");
    if ($check_stmt) {
        $check_stmt->bind_param("s", $staff_id);
        $check_stmt->execute();
        $dependency = $check_stmt->get_result()->fetch_object();
        $check_stmt->close();

        if ($dependency && $dependency->active_roles > 0) {
            $_SESSION['errorMssg'] = "Cannot delete staff. This member has active system access/roles assigned. Please remove their access rights first.";
            header("Location: staff.php");
            exit();
        }
    }

    // 2. Perform Soft Delete
    // Change 'status' to 'Deleted' instead of removing the row
    $stmt = $db->prepare("UPDATE staff SET status = 'Deleted' WHERE staff_id = ?");
    
    if ($stmt) {
        $stmt->bind_param("s", $staff_id);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                // Also optionally soft-delete or deactivate their login if applicable, 
                // but access_right check above ensures they don't have active roles.
                $_SESSION['successMssg'] = "Staff member removed successfully (Soft Deleted).";
            } else {
                $_SESSION['errorMssg'] = "Staff member not found or already deleted.";
            }
        } else {
            $_SESSION['errorMssg'] = "The staff member could not be removed.";
        }
        $stmt->close();
    } else {
        $_SESSION['errorMssg'] = "Database preparation error.";
    }
} else {
    $_SESSION['errorMssg'] = "No staff ID specified for deletion.";
}

// Redirect back to the main list
header("Location: staff.php");
exit();
?>
