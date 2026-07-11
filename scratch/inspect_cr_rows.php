<?php
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
$r = $db->query("SELECT course_code, Year, academic_year, is_active, semester FROM course_registration WHERE Sid='CSE26456789' ORDER BY course_code");
while ($x = $r->fetch_assoc()) {
    echo json_encode($x) . "\n";
}
