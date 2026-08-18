<?php
require_once 'C:/xampp/htdocs/wucportal/db/connect.php';
require_once 'C:/xampp/htdocs/wucportal/includes/portal_access.php';
require_once 'C:/xampp/htdocs/wucportal/includes/alumni_access_helpers.php';
$sid='CSE26456789';
echo "=== clearance ===\n";
$r=$db->query("SELECT * FROM student_clearance WHERE student_id='$sid'");
if($r){ while($row=$r->fetch_assoc()) echo json_encode($row)."\n"; } else echo "no table/query fail\n";
echo "\n=== sync dry check ===\n";
// show what grad status would do
$r=$db->query("SELECT graduation_status FROM student_clearance WHERE student_id='$sid' LIMIT 1");
$row=$r?$r->fetch_assoc():null;
echo 'grad_status: '.($row['graduation_status']??'MISSING')."\n";

