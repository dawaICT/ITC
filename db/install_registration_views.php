<?php
require_once __DIR__ . '/connect.php';

function table_exists(mysqli $db, string $table): bool {
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    if ($res) { $exists = $res->num_rows > 0; $res->free(); return $exists; }
    return false;
}

// Ensure minimal course_registration table exists (used across app)
if (!table_exists($db, 'course_registration')) {
    $db->query(
        "CREATE TABLE course_registration (
            id INT AUTO_INCREMENT PRIMARY KEY,
            Sid VARCHAR(32) NOT NULL,
            course_code VARCHAR(50) NOT NULL,
            semester INT NOT NULL,
            Year INT NOT NULL,
            registration_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sid_course (Sid, course_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Create or replace compatibility view for legacy queries
$db->query("CREATE OR REPLACE VIEW registered_courses AS SELECT Sid, course_code FROM course_registration");

echo "Installed/ensured course_registration and registered_courses view.\n";


