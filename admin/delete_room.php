<?php
require_once "includes/admin.php";
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

try {
    // Validate input
    if (!isset($_POST['room_id']) || empty($_POST['room_id'])) {
        throw new Exception("Room ID is required");
    }

    $room_id = (int)$_POST['room_id'];

    // Check if room exists and is not occupied
    $check_sql = "SELECT status FROM rooms WHERE room_id = ?";
    $check_stmt = $db->prepare($check_sql);
    $check_stmt->bind_param("i", $room_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("Room not found");
    }

    $room = $result->fetch_object();
    if ($room->status === 'occupied') {
        throw new Exception("Cannot delete an occupied room");
    }

    // Delete the room
    $delete_sql = "DELETE FROM rooms WHERE room_id = ?";
    $delete_stmt = $db->prepare($delete_sql);
    $delete_stmt->bind_param("i", $room_id);

    if ($delete_stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Room deleted successfully'
        ]);
    } else {
        throw new Exception("Error deleting room: " . $delete_stmt->error);
    }

    $delete_stmt->close();
    $check_stmt->close();

} catch (Exception $e) {
    error_log("Room deletion error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'The room could not be deleted.'
    ]);
}

$db->close();
?> 
