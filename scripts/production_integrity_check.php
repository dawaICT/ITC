<?php
declare(strict_types=1);

/**
 * Read-only production integrity probe (CLI).
 * Does not mutate data.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/db/connect.php';

$findings = [];
$warn = static function (string $msg) use (&$findings): void {
    $findings[] = ['level' => 'WARN', 'msg' => $msg];
};
$ok = static function (string $msg) use (&$findings): void {
    $findings[] = ['level' => 'OK', 'msg' => $msg];
};

$required = [
    'students', 'student_login', 'student_program', 'programs', 'courses',
    'payments', 'fee_structure', 'semester_registration', 'staff', 'staff_positions',
    'positions', 'sections',
];
foreach ($required as $table) {
    $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    if (!$r || $r->num_rows === 0) {
        $warn("Missing table: {$table}");
    } else {
        $ok("Table present: {$table}");
    }
}

$checks = [
    'orphan_student_login' =>
        "SELECT COUNT(*) c FROM student_login sl LEFT JOIN students s ON s.SID = sl.Sid WHERE s.SID IS NULL",
    'orphan_student_program' =>
        "SELECT COUNT(*) c FROM student_program sp LEFT JOIN students s ON s.SID = sp.Sid WHERE s.SID IS NULL",
    'student_program_bad_program' =>
        "SELECT COUNT(*) c FROM student_program sp LEFT JOIN programs p ON p.program_code = sp.program_code WHERE p.program_code IS NULL",
    'duplicate_active_student_program' =>
        "SELECT COUNT(*) c FROM (
            SELECT Sid FROM student_program WHERE LOWER(COALESCE(status,'active')) IN ('active','registered','current','')
            GROUP BY Sid HAVING COUNT(*) > 1
         ) t",
];

foreach ($checks as $name => $sql) {
    try {
        $res = $db->query($sql);
        $c = $res ? (int)$res->fetch_assoc()['c'] : -1;
        if ($c > 0) {
            $warn("{$name}: {$c}");
        } else {
            $ok("{$name}: 0");
        }
    } catch (Throwable $e) {
        $warn("{$name}: query failed — " . $e->getMessage());
    }
}

$optional = ['staff_section_assignments', 'schema_migrations', 'login_attempts', 'audit_logs', 'wuc_audit_logs', 'payment_gateway_transactions'];
foreach ($optional as $table) {
    $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    if ($r && $r->num_rows > 0) {
        $ok("Optional table present: {$table}");
    } else {
        $warn("Optional table missing: {$table}");
    }
}

$idxRes = $db->query("SHOW INDEX FROM payments");
$idx = [];
if ($idxRes) {
    while ($row = $idxRes->fetch_assoc()) {
        $idx[] = $row['Key_name'] . ':' . $row['Column_name'];
    }
}
$ok('payments indexes: ' . (empty($idx) ? 'none' : implode(', ', array_unique($idx))));

foreach ($findings as $f) {
    echo '[' . $f['level'] . '] ' . $f['msg'] . PHP_EOL;
}

$warnCount = count(array_filter($findings, static fn($f) => $f['level'] === 'WARN'));
echo PHP_EOL . "WARN_COUNT={$warnCount}" . PHP_EOL;
exit($warnCount > 0 ? 2 : 0);
