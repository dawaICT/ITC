<?php
require 'db/connect.php';
$db->query("UPDATE programs SET term_based = 1 WHERE program_code = 'CS101'");
echo 'Updated CS101 to term-based';
?>