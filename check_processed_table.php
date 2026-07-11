<?php
require_once 'db/connect.php';

$result = $db->query('DESCRIBE processed_applicants');
if ($result) {
    echo "processed_applicants table structure:\n";
    while($row = $result->fetch_assoc()) {
        echo $row['Field'] . ' - ' . $row['Type'] . "\n";
    }
    
    // Check indexes
    $index_result = $db->query('SHOW INDEX FROM processed_applicants');
    echo "\nIndexes:\n";
    while($row = $index_result->fetch_assoc()) {
        echo $row['Key_name'] . ' on ' . $row['Column_name'] . "\n";
    }
    
    // Check distinct status
    $status_result = $db->query('SELECT DISTINCT status FROM processed_applicants LIMIT 10');
    echo "\nDistinct status values:\n";
    while($row = $status_result->fetch_assoc()) {
        echo $row['status'] . "\n";
    }
    
    // Count records
    $count_result = $db->query('SELECT COUNT(*) as count FROM processed_applicants');
    $count = $count_result->fetch_assoc()['count'];
    echo "\nTotal records: $count\n";
} else {
    echo 'Table processed_applicants does not exist\n';
}
$db->close();
?>