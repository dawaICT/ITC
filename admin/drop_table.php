<?php
require_once __DIR__ . '/../includes/production_guards.php';
wuc_require_cli_only();
require_once(__DIR__ . '/../db/connect.php');

// Drop table
$db->query('DROP TABLE IF EXISTS semester_registration');

echo "Table dropped successfully";
?> 