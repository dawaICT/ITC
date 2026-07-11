<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) { echo "CONNERR\n"; exit(1); }
$sid = 'BSCS-TEST-001';
$q = "SELECT c.* FROM elearning_courses c JOIN elearning_enrollments e ON c.id = e.course_id WHERE e.student_id = '".$db->real_escape_string($sid)."'";
echo "Query: $q\n";
$r = $db->query($q);
if ($r) {
    while ($c = $r->fetch_object()) {
        echo "Found course: " . ($c->title ?? '') . " (" . ($c->code ?? '') . ")\n";
    }
} else {
    echo "Query failed: " . $db->error . "\n";
}
$db->close();
