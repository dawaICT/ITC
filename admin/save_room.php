<?php
require_once "includes/admin.php";
header('Content-Type: application/json');

try {
    // Validate input
    $required_fields = ['room_number', 'block', 'floor', 'capacity', 'status'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Sanitize input
    $room_number = sanitize_input($_POST['room_number']);
    $block = sanitize_input($_POST['block']);
    $floor = (int)$_POST['floor'];
    $capacity = (int)$_POST['capacity'];
    $status = sanitize_input($_POST['status']);

    // Validate values
    if ($floor < 1) {
        throw new Exception("Floor must be greater than 0");
    }
    if ($capacity < 1) {
        throw new Exception("Capacity must be greater than 0");
    }
    if (!in_array($status, ['available', 'occupied', 'maintenance'])) {
        throw new Exception("Invalid status value");
    }

    // Check if room already exists
    $check_sql = "SELECT room_id FROM rooms WHERE room_number = ? AND block = ?";
    $check_stmt = $db->prepare($check_sql);
    $check_stmt->bind_param("ss", $room_number, $block);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result->num_rows > 0) {
        // Update existing room
        $room = $result->fetch_object();
        $update_sql = "UPDATE rooms SET 
            floor = ?,
            capacity = ?,
            status = ?,
            updated_at = CURRENT_TIMESTAMP
            WHERE room_id = ?";
        
        $update_stmt = $db->prepare($update_sql);
        $update_stmt->bind_param("iisi", $floor, $capacity, $status, $room->room_id);
        
        if ($update_stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Room updated successfully',
                'room_id' => $room->room_id
            ]);
        } else {
            throw new Exception("Error updating room: " . $update_stmt->error);
        }
        $update_stmt->close();
    } else {
        // Insert new room
        $insert_sql = "INSERT INTO rooms (room_number, block, floor, capacity, status) 
                      VALUES (?, ?, ?, ?, ?)";
        
        $insert_stmt = $db->prepare($insert_sql);
        $insert_stmt->bind_param("ssiis", $room_number, $block, $floor, $capacity, $status);
        
        if ($insert_stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Room created successfully',
                'room_id' => $insert_stmt->insert_id
            ]);
        } else {
            throw new Exception("Error creating room: " . $insert_stmt->error);
        }
        $insert_stmt->close();
    }
    $check_stmt->close();

} catch (Exception $e) {
    error_log("Room save error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$db->close();
?> 