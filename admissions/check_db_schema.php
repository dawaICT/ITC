<?php
// Simple DB check script
// Try to connect using common credentials or include a config
$paths = [
    '../includes/config.php',
    '../../includes/config.php',
    'includes/config.php',
    '../config.php'
];

$db = null;
foreach ($paths as $path) {
    if (file_exists($path)) {
        require $path; // Try to include config to get $db or credentials
        if (isset($db) && $db instanceof mysqli) {
            echo "Connected via $path\n";
            break;
        }
    }
}

if (!$db) {
    // Fallback: Try localhost/root/empty
    $db = new mysqli('localhost', 'root', '', 'wuc_portal'); // Guessing DB name from path 'wucportal', might be 'wuc', 'wuc_portal', 'portal'
    if ($db->connect_error) {
        $db = new mysqli('localhost', 'root', '', 'wuc'); 
    }
}

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error . "\n");
}

echo "Database connected.\n";

// Check students table columns
$result = $db->query("SHOW COLUMNS FROM students");
$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

echo "Columns in students table:\n";
print_r($columns);

$required = ['is_transfer', 'transfer_from', 'transfer_credits'];
$missing = array_diff($required, $columns);

if (!empty($missing)) {
    echo "MISSING columns: " . implode(', ', $missing) . "\n";
    // Attempt to add them
    foreach ($missing as $col) {
        $sql = "";
        if ($col == 'is_transfer') $sql = "ALTER TABLE students ADD COLUMN is_transfer TINYINT(1) DEFAULT 0";
        if ($col == 'transfer_from') $sql = "ALTER TABLE students ADD COLUMN transfer_from VARCHAR(255) NULL";
        if ($col == 'transfer_credits') $sql = "ALTER TABLE students ADD COLUMN transfer_credits INT DEFAULT 0";
        
        if ($sql) {
            if ($db->query($sql)) {
                echo "Added column $col\n";
            } else {
                echo "Failed to add column $col: " . $db->error . "\n";
            }
        }
    }
} else {
    echo "All required columns exist.\n";
}
?>
