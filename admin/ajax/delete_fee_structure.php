<?php
// Enable error reporting but don't display to client (keep JSON clean)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start session
session_start();

// Define root path
$root_path = dirname(dirname(dirname(__FILE__)));

// Include database connection
require_once $root_path . '/db/connect.php';
require_once $root_path . '/includes/audit.php';

// CSRF protection for this state-changing endpoint
require_once $root_path . '/includes/csrf_guard.php';
wuc_ajax_require_csrf();

// Set JSON header
header('Content-Type: application/json');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Check if ID is provided
if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid fee structure ID']);
    exit;
}

// Sanitize input
$id = (int)$_POST['id'];

try {
    // Start transaction
    $db->begin_transaction();

    // Soft-delete fee structure by setting status to inactive
    $sql = "UPDATE fee_structure SET status = 'inactive' WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();

    // Check if any rows were affected
    if ($stmt->affected_rows > 0) {
        $user_id = $_SESSION['user_id'] ?? 0;
        audit_log($db, (string)$user_id, 'delete_fee_structure', ['record_id' => $id]);

        // Commit transaction
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'Fee structure marked inactive']);
    } else {
        // Rollback transaction
        $db->rollback();
        
        echo json_encode(['success' => false, 'message' => 'Fee structure not found']);
    }
} catch (Exception $e) {
    // Rollback transaction
    $db->rollback();
    
    // Log error
    error_log($e->getMessage() . "\n", 3, $root_path . "/logs/error.log");
    
    echo json_encode(['success' => false, 'message' => 'Error deleting fee structure']);
}
?> 
