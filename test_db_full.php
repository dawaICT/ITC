<?php
// Simple database connection test for CLI
$db_host = "127.0.0.1";
$db_user = "root";
$db_password = "";
$db_name = "wucportal";

echo "=== Database Connection Test ===\n";
$db = new mysqli($db_host, $db_user, $db_password, $db_name);

if ($db->connect_error) {
    echo "ERROR: Connection failed: " . $db->connect_error . "\n";
    exit(1);
}

echo "SUCCESS: Database connected\n";
echo "Server: " . $db->server_info . "\n";
echo "Host: " . $db->host_info . "\n";
echo "Database: " . $db_name . "\n";
echo "Ping: " . ($db->ping() ? 'OK' : 'FAIL') . "\n";

echo "\n=== Table Existence Check ===\n";
$tables = ['semester_registration', 'course_registration', 'course_levels', 'courses', 'students', 'programs'];

foreach ($tables as $t) {
    $r = $db->query("SHOW TABLES LIKE '{$t}'");
    $exists = ($r && $r->num_rows > 0);
    echo $t . ': ' . ($exists ? 'EXISTS' : 'MISSING') . "\n";
    if ($r) $r->free();
}

echo "\n=== Record Counts ===\n";
foreach ($tables as $t) {
    $r = $db->query("SELECT COUNT(*) as cnt FROM {$t}");
    if ($r) {
        $row = $r->fetch_assoc();
        echo $t . ': ' . $row['cnt'] . ' records' . "\n";
        $r->free();
    } else {
        echo $t . ': ERROR - ' . $db->error . "\n";
    }
}

echo "\n=== Table Structures ===\n";
$key_tables = ['semester_registration', 'course_registration', 'course_levels', 'courses'];

foreach ($key_tables as $table) {
    echo "\n--- {$table} ---\n";
    $r = $db->query("DESCRIBE {$table}");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $key = $row['Key'] ? ' [' . $row['Key'] . ']' : '';
            $null = $row['Null'] == 'YES' ? ' NULL' : ' NOT NULL';
            echo "  " . $row['Field'] . ' (' . $row['Type'] . ')' . $null . $key . "\n";
        }
        $r->free();
    } else {
        echo "  ERROR: " . $db->error . "\n";
    }
}

echo "\n=== Foreign Key Relationships ===\n";
$r = $db->query("
    SELECT 
        TABLE_NAME, 
        COLUMN_NAME, 
        REFERENCED_TABLE_NAME, 
        REFERENCED_COLUMN_NAME 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = '{$db_name}' 
    AND REFERENCED_TABLE_NAME IS NOT NULL 
    AND TABLE_NAME IN ('semester_registration', 'course_registration')
");
if ($r && $r->num_rows > 0) {
    while ($row = $r->fetch_assoc()) {
        echo "  " . $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'] . ' -> ' . 
             $row['REFERENCED_TABLE_NAME'] . '.' . $row['REFERENCED_COLUMN_NAME'] . "\n";
    }
    $r->free();
} else {
    echo "  No foreign keys found\n";
}

$db->close();
echo "\n=== Test Complete ===\n";
