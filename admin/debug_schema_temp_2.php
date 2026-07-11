<?php
require_once '../db/connect.php'; // Assuming this sets up $db
echo "<h2>Staff Columns:</h2>";
$res = $db->query("SHOW COLUMNS FROM staff");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "<br>";
}

echo "<h2>Departments Columns:</h2>";
$res = $db->query("SHOW COLUMNS FROM departments");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "<br>";
}
?>
