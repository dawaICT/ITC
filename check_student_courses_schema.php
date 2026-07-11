<?php
require_once 'students/includes/Database.php';
$db = new Database();
$conn = $db->getConnection();
$result = $conn->query('DESCRIBE student_courses');
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
    echo PHP_EOL;
}
?>