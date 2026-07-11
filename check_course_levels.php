<?php
require 'db/connect.php';
$result = $db->query('SHOW TABLES LIKE "course_levels"');
if ($result->num_rows > 0) {
    echo 'course_levels table exists';
    $result = $db->query('SELECT COUNT(*) as count FROM course_levels');
    $row = $result->fetch_assoc();
    echo ', records: ' . $row['count'];
} else {
    echo 'course_levels table does not exist';
}
?>