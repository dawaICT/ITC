<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) { echo "CONNERR\n"; exit(1); }
$res = $db->query("SELECT e.*, c.code AS course_code FROM elearning_enrollments e LEFT JOIN elearning_courses c ON e.course_id = c.id ORDER BY e.enrolled_at DESC");
if ($res) {
    while ($r = $res->fetch_object()) {
        echo ($r->id ?? '') . ' | ' . ($r->student_id ?? '') . ' | ' . ($r->course_id ?? '') . ' | ' . ($r->course_code ?? '') . "\n";
    }
}
$db->close();
