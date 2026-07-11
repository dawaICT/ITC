<?php
require 'db/connect.php';

$db->query("INSERT INTO students (SID, Fname, Lname, sex) VALUES ('test123', 'Test', 'Student', 'M')");
echo 'Test student added.';
?>