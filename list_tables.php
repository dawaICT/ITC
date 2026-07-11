<?php
require 'c:/xampp/htdocs/wucportal/db/connect.php';

echo "=== short_courses ===\n";
$r = $db->query("DESCRIBE short_courses");
if ($r) { while ($row = $r->fetch_assoc()) { echo $row['Field'].' | '.$row['Type'].' | '.$row['Key']."\n"; } }

echo "\n=== students ===\n";
$r2 = $db->query("DESCRIBE students");
if ($r2) { while ($row = $r2->fetch_assoc()) { echo $row['Field'].' | '.$row['Type'].' | '.$row['Key']."\n"; } }

echo "\n=== student_login ===\n";
$r3 = $db->query("DESCRIBE student_login");
if ($r3) { while ($row = $r3->fetch_assoc()) { echo $row['Field'].' | '.$row['Type'].' | '.$row['Key']."\n"; } }
else { echo "Does not exist\n"; }

echo "\n=== Check short_course_enrollments ===\n";
$r4 = $db->query("DESCRIBE short_course_enrollments");
if ($r4) { while ($row = $r4->fetch_assoc()) { echo $row['Field'].' | '.$row['Type'].' | '.$row['Key']."\n"; } }
else { echo "Does not exist yet\n"; }
?>
