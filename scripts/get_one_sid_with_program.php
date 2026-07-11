<?php
$db = new mysqli('localhost', 'root', '', 'wucportal');
if ($db->connect_error) { echo "CONNERR\n"; exit(1); }
$r = $db->query("SELECT sp.Sid FROM student_program sp LIMIT 1");
if ($r && $row = $r->fetch_object()) { echo $row->Sid; } else { echo "NOSID"; }
$db->close();
