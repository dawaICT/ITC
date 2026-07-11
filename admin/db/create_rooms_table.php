<?php
require_once '../../includes/db_connect.php';

try {
    // Create rooms table
    $sql = "CREATE TABLE IF NOT EXISTS rooms (
        room_id INT AUTO_INCREMENT PRIMARY KEY,
        room_number VARCHAR(10) NOT NULL,
        block CHAR(1) NOT NULL,
        floor INT NOT NULL,
        capacity INT NOT NULL,
        current_occupants INT DEFAULT 0,
        status ENUM('available', 'occupied', 'maintenance') DEFAULT 'available',
        last_maintenance DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_room (room_number, block)
    )";

    if ($db->query($sql)) {
        echo "Rooms table created successfully\n";
    } else {
        throw new Exception("Error creating rooms table: " . $db->error);
    }

    // Insert sample data
    $sample_rooms = [
        ['A101', 'A', 1, 4, 0, 'available'],
        ['A102', 'A', 1, 4, 2, 'occupied'],
        ['B101', 'B', 1, 4, 0, 'available'],
        ['B102', 'B', 1, 4, 3, 'occupied'],
        ['C101', 'C', 1, 4, 0, 'maintenance']
    ];

    $insert_sql = "INSERT INTO rooms (room_number, block, floor, capacity, current_occupants, status) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($insert_sql);

    foreach ($sample_rooms as $room) {
        $stmt->bind_param("ssiiis", $room[0], $room[1], $room[2], $room[3], $room[4], $room[5]);
        if (!$stmt->execute()) {
            echo "Error inserting room {$room[0]}: " . $stmt->error . "\n";
        }
    }

    echo "Sample rooms inserted successfully\n";
    $stmt->close();

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

$db->close();
?> 