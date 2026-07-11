<?php
require_once dirname(__DIR__) . '/db/connect.php';

echo "Checking and updating schema...\n";

// 1. student_program updates
$sp_columns = [];
$res = $db->query("DESCRIBE student_program");
while ($row = $res->fetch_assoc()) {
    $sp_columns[] = $row['Field'];
}

$alter_queries = [];

if (!in_array('academic_year', $sp_columns)) {
    $alter_queries[] = "ALTER TABLE student_program ADD COLUMN academic_year INT";
}

if (!in_array('enrolled_by', $sp_columns)) {
    $alter_queries[] = "ALTER TABLE student_program ADD COLUMN enrolled_by VARCHAR(100)";
}

if (!in_array('transfer_document', $sp_columns)) {
    $alter_queries[] = "ALTER TABLE student_program ADD COLUMN transfer_document VARCHAR(255)";
}

foreach ($alter_queries as $q) {
    if ($db->query($q)) {
        echo "Executed: $q\n";
    } else {
        echo "Error: " . $db->error . " (Query: $q)\n";
    }
}

// 2. Create admission_logs
$sql_logs = "CREATE TABLE IF NOT EXISTS admission_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(50) NOT NULL,
    program_code VARCHAR(50) NOT NULL,
    intake VARCHAR(50) NOT NULL,
    action VARCHAR(50) NOT NULL,
    performed_by VARCHAR(100),
    performed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student (student_id),
    INDEX idx_action (action)
)";

if ($db->query($sql_logs)) {
    echo "Table admission_logs checked/created.\n";
} else {
    echo "Error creating admission_logs: " . $db->error . "\n";
}

echo "Schema update complete.\n";
?>
