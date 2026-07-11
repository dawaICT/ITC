<?php
putenv('APP_ENV=development');
require_once __DIR__ . '/../db/connect.php';
$r = $db->query("SHOW COLUMNS FROM program_courses LIKE 'is_full_year'");
echo ($r && $r->num_rows > 0) ? "migration_applied\n" : "migration_missing\n";
