<?php
require 'db/connect.php';

$db->query("INSERT INTO student_program (Sid, program_code, intake, mode, startYear, endYear) VALUES ('test123', 'CS101', '2023', 'semester', 2023, 2026)");
echo 'Program assigned.';
?>