<?php
// Bypass admin.php to avoid session start issues in CLI
require '../db/connect.php';

echo "STAFF_COLUMNS: ";
$res = $db->query('DESCRIBE staff');
if ($res) {
    while($row = $res->fetch_assoc()) { 
        echo $row['Field'] . ','; 
    }
} else {
    echo "ERROR checking staff: " . $db->error;
}
echo "\n";

echo "LIBRARIAN_TABLE: ";
$res2 = $db->query('DESCRIBE librarian'); 
if($res2) { 
    echo "EXISTS";
} else { 
    echo "NOT_FOUND";
}
echo "\n";
?>
