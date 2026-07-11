<?php
require_once 'students/includes/Database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query('SELECT * FROM semester_registration LIMIT 5');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
    echo PHP_EOL;
}
?>