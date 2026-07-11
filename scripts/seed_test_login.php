<?php
// Seeds/updates a test login for an existing student to enable local testing.
// Sets password to 'password123' (MD5).

require_once __DIR__ . '/../db/connect.php';

try {
    $res = $db->query("SELECT SID FROM students ORDER BY SID LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        echo "No students found. Cannot seed login.\n";
        exit(0);
    }
    $row = $res->fetch_assoc();
    $sid = $row['SID'];
    $res->free();

    $hashed = md5('password123');

    // Ensure student_login table exists
    $db->query("CREATE TABLE IF NOT EXISTS student_login (
        Sid VARCHAR(50) PRIMARY KEY,
        Password VARCHAR(255) NOT NULL,
        CONSTRAINT fk_student_login_student FOREIGN KEY (Sid) REFERENCES students(SID)
            ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Upsert login
    $stmt = $db->prepare('INSERT INTO student_login (Sid, Password) VALUES (?, ?) ON DUPLICATE KEY UPDATE Password = VALUES(Password)');
    $stmt->bind_param('ss', $sid, $hashed);
    $stmt->execute();
    $stmt->close();

    echo "Seeded test login for SID: {$sid} with password: password123\n";
} catch (Throwable $e) {
    echo 'Error seeding test login: ' . $e->getMessage() . "\n";
}


