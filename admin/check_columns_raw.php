<?php
$host = 'localhost'; $user = 'root'; $pass = ''; $dbname = 'wucportal';
$conn = new mysqli($host, $user, $pass, $dbname);

$tables = ['exams'];
foreach ($tables as $t) {
    echo "TABLE: $t\n";
    $res = $conn->query("DESCRIBE $t");
    while($row = $res->fetch_assoc()) {
        echo $row['Field'] . "\n";
    }
}
?>
