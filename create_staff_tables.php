<?php
require_once 'db/connect.php';

$sqls = [
    "CREATE TABLE IF NOT EXISTS staff (
        staff_id VARCHAR(64) PRIMARY KEY,
        Fname VARCHAR(50),
        Lname VARCHAR(50),
        title VARCHAR(10),
        email VARCHAR(100)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS user_credentials (
        staff_id VARCHAR(64) PRIMARY KEY,
        pass VARCHAR(255) NOT NULL,
        FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS positions (
        PosID VARCHAR(20) PRIMARY KEY,
        PosName VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS staff_positions (
        staff_id VARCHAR(64) NOT NULL,
        PosID VARCHAR(20) NOT NULL,
        UNIQUE KEY uniq_staff_pos (staff_id, PosID),
        FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE,
        FOREIGN KEY (PosID) REFERENCES positions(PosID) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ($sqls as $sql) {
    if ($db->query($sql)) {
        echo "Created table successfully.\n";
    } else {
        echo "Error: " . $db->error . "\n";
    }
}

$db->close();
?>