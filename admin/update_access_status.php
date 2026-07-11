<?php
require_once "includes/admin.php"; 
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $staffId = $_POST['id'];
    $status = $_POST['status'] ?? 'inactive';

    // Map frontend 'inactive'/'active' to DB 'Deleted'/'Active'
    // Based on schema: status enum('Active','Deleted')
    // We probably want 'Deleted' for deactivation, or maybe just toggling?
    // User prompt said "Changes the user status to inactive". 
    // And schema shows 'Active' and 'Deleted'. 
    // It's possible 'inactive' in prompt maps to 'Deleted' or they want to Add 'Inactive' to ENUM.
    // Given the ENUM, let's treat 'inactive' as 'Deleted' for now, or essentially "Not Active".
    // Or, better, we should ALTER the table to allow 'Inactive' but I shouldn't change schema if not asked.
    // Wait, if I set 'Inactive', it might fail if strict SQL mode.
    // Let's assume for now we use 'Deleted' if status is inactive, 'Active' if active.
    
    $dbStatus = ($status === 'inactive') ? 'Deleted' : 'Active';

    // However, the user provided code: $stmt->bind_param("ss", $status, $staffId);
    // which implies they think column allows 'inactive'.
    // If I use their code exactly and schema allows only Active/Deleted, it will fail or truncate.
    // I will try to use 'Deleted' for 'inactive'.
    
    // Actually, looking at the schema: enum('Active','Deleted'). 
    // 'inactive' from JS will fail. I should use 'Deleted'.
    
    $stmt = $db->prepare("UPDATE staff SET status = ? WHERE staff_id = ?");
    $stmt->bind_param("ss", $dbStatus, $staffId);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
