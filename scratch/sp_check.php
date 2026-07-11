<?php
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
$sid = 'CSE26456789';
$s = $db->prepare('SELECT * FROM student_program WHERE Sid = ?');
$s->bind_param('s', $sid);
$s->execute();
print_r($s->get_result()->fetch_all(MYSQLI_ASSOC));
