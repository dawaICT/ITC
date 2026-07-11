<?php
require_once 'db/connect.php';

$result = $db->query('DESCRIBE online_applicants');
echo "Current online_applicants table structure:\n";
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}
$db->close();
?>