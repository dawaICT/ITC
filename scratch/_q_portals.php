<?php
require_once 'C:/xampp/htdocs/wucportal/db/connect.php';
echo "=== portals table ===\n";
$r=$db->query('SELECT id, portal_code, portal_name, description, status FROM portals ORDER BY id');
while($row=$r->fetch_assoc()) echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
echo "\n=== user 25 portal grants ===\n";
$r=$db->query("SELECT p.portal_code, upa.access_status, upa.assigned_by, upa.updated_at FROM user_portal_access upa JOIN portals p ON p.id=upa.portal_id WHERE upa.user_id=25");
while($row=$r->fetch_assoc()) echo json_encode($row)."\n";
echo "\n=== students with active alumni ===\n";
$r=$db->query("SELECT u.user_id, u.student_id, u.primary_role, s.status AS student_status
 FROM users u
 JOIN user_portal_access upa ON upa.user_id=u.user_id AND upa.access_status='active'
 JOIN portals p ON p.id=upa.portal_id AND p.portal_code='alumni'
 LEFT JOIN students s ON s.SID=u.student_id
 WHERE u.student_id IS NOT NULL AND u.student_id<>''
 LIMIT 20");
while($row=$r->fetch_assoc()) echo json_encode($row)."\n";
echo "\n=== CSE26456789 student row ===\n";
$r=$db->query("SELECT SID, Fname, Lname, status, program FROM students WHERE SID='CSE26456789' LIMIT 1");
while($row=$r->fetch_assoc()) echo json_encode($row)."\n";
