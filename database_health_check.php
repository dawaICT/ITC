<?php
declare(strict_types=1);

// Kept at its historical path for operator compatibility, but deliberately
// CLI-only and blocked by Apache. It never mutates the database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/db/connect.php';

$errors = [];
$warnings = [];
$required = [
    'students' => ['SID'],
    'student_login' => ['Sid', 'Password'],
    'student_program' => ['Sid', 'program_code'],
    'programs' => ['program_code', 'program_name'],
    'courses' => ['course_code', 'course_name'],
    'payments' => ['student_id', 'amount', 'status'],
    'fee_structure' => ['program_code', 'amount'],
    'semester_registration' => ['student_id', 'academic_year'],
];

echo "ITC Portal database health check\n";
echo 'Server: ' . $db->server_info . '; charset: ' . $db->character_set_name() . "\n\n";

$tableExists = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
$columnExists = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
foreach ($required as $table => $columns) {
    $tableExists->bind_param('s', $table);
    $tableExists->execute();
    if ((int)$tableExists->get_result()->fetch_assoc()['c'] !== 1) {
        $errors[] = "Missing required table: {$table}";
        continue;
    }
    $quoted = '`' . str_replace('`', '``', $table) . '`';
    $count = (int)$db->query("SELECT COUNT(*) AS c FROM {$quoted}")->fetch_assoc()['c'];
    echo "OK {$table} ({$count} rows)\n";
    foreach ($columns as $column) {
        $columnExists->bind_param('ss', $table, $column);
        $columnExists->execute();
        if ((int)$columnExists->get_result()->fetch_assoc()['c'] !== 1) {
            $errors[] = "Missing required column: {$table}.{$column}";
        }
    }
}
$tableExists->close();
$columnExists->close();

foreach (['exams', 'semester_assessment'] as $legacyTable) {
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->bind_param('s', $legacyTable);
    $stmt->execute();
    if ((int)$stmt->get_result()->fetch_assoc()['c'] !== 1) {
        $warnings[] = "Legacy results table is absent: {$legacyTable}; features must use the current results schema or degrade safely.";
    }
    $stmt->close();
}

$orphan = $db->query('SELECT COUNT(*) AS c FROM student_program sp LEFT JOIN students s ON s.SID = sp.Sid WHERE s.SID IS NULL');
$orphanCount = (int)$orphan->fetch_assoc()['c'];
if ($orphanCount > 0) {
    $errors[] = "student_program contains {$orphanCount} orphaned row(s).";
}

foreach ($warnings as $warning) {
    echo "WARN {$warning}\n";
}
foreach ($errors as $error) {
    echo "FAIL {$error}\n";
}
echo "\nRESULT: " . ($errors ? 'UNHEALTHY' : 'HEALTHY') . '; errors=' . count($errors) . '; warnings=' . count($warnings) . "\n";
exit($errors ? 1 : 0);
