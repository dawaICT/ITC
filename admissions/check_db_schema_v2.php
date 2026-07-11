<?php
// Fix path to config
if (file_exists('../includes/config.php')) {
    require '../includes/config.php';
} elseif (file_exists('../../includes/config.php')) {
    require '../../includes/config.php';
} else {
    die("Cannot find config.php\n");
}

if (!isset($db) || $db->connect_error) {
    if (file_exists('../includes/db_connect.php')) {
        require '../includes/db_connect.php';
    }
}

if (!isset($db)) {
    // Try manual connection if config didn't give us $db
    // Inspect global variables or try to create one
     $db = new mysqli(DB_SERVER, DB_USER, DB_PASS, DB_NAME);
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
