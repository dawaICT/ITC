<?php
/**
 * CLI baseline for scalability audit. Not web-reachable (.htaccess blocks scripts/).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/db/connect.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

echo "=== WUC Performance Baseline ===\n";
echo 'Time: ' . gmdate(DATE_ATOM) . "\n";
echo 'APCu: ' . (function_exists('apcu_enabled') && @apcu_enabled() ? 'yes' : 'no') . "\n";
echo 'PHP: ' . PHP_VERSION . "\n\n";

$counts = [
    'students', 'payments', 'course_registration', 'semester_registration',
    'student_program', 'staff', 'programs', 'portal_alerts',
];
foreach ($counts as $t) {
    try {
        $r = $db->query("SELECT COUNT(*) c FROM `{$t}`");
        echo "{$t}: " . $r->fetch_assoc()['c'] . "\n";
    } catch (Throwable $e) {
        echo "{$t}: ERROR " . $e->getMessage() . "\n";
    }
}

echo "\n=== Index check (key tables) ===\n";
$need = [
    'payments' => ['student_id', 'status', 'payment_date'],
    'student_program' => ['Sid', 'program_code', 'status'],
    'semester_registration' => ['student_id', 'academic_year'],
    'course_registration' => ['student_id'],
    'fee_structure' => ['program_code'],
    'portal_alerts' => ['user_id'],
];
foreach ($need as $table => $cols) {
    $indexed = [];
    try {
        $r = $db->query("SHOW INDEX FROM `{$table}`");
        while ($row = $r->fetch_assoc()) {
            $indexed[$row['Column_name']] = $row['Key_name'];
        }
    } catch (Throwable $e) {
        echo "{$table}: missing or error\n";
        continue;
    }
    foreach ($cols as $col) {
        $ok = isset($indexed[$col]) ? $indexed[$col] : 'MISSING';
        echo "{$table}.{$col}: {$ok}\n";
    }
}

$riskExists = false;
$r = $db->query("SHOW TABLES LIKE 'student_risk_summary'");
$riskExists = $r && $r->num_rows > 0;
echo "\nstudent_risk_summary: " . ($riskExists ? 'EXISTS' : 'MISSING') . "\n";
if ($riskExists) {
    $r = $db->query('DESCRIBE student_risk_summary');
    while ($row = $r->fetch_assoc()) {
        echo '  ' . $row['Field'] . ' ' . $row['Type'] . "\n";
    }
}

// Pick a student for query timing
$sid = '';
$r = $db->query("SELECT SID FROM students LIMIT 1");
if ($row = $r->fetch_assoc()) {
    $sid = (string)$row['SID'];
}
echo "\nSample SID: {$sid}\n";

if ($sid !== '') {
    $queries = [
        'student_join' => [
            'sql' => "SELECT s.SID, s.Fname, s.Lname, sp.program_code, p.program_name
                      FROM students s
                      INNER JOIN student_program sp ON s.SID = sp.Sid
                      INNER JOIN programs p ON sp.program_code = p.program_code
                      WHERE s.SID = ? LIMIT 1",
            'types' => 's',
            'params' => [$sid],
        ],
        'payments_sum' => [
            'sql' => "SELECT COALESCE(SUM(amount),0) t FROM payments WHERE student_id = ? AND status = 'posted'",
            'types' => 's',
            'params' => [$sid],
        ],
        'admin_students_unbounded' => [
            'sql' => "SELECT s.SID, s.Fname, s.Lname, s.sex, s.email,
                             COALESCE(sp.intake, s.intake) as intake,
                             COALESCE(sp.program_code, s.program) as program_code
                      FROM students s
                      LEFT JOIN student_program sp ON s.SID = sp.Sid
                      ORDER BY s.SID ASC",
            'types' => '',
            'params' => [],
        ],
        'admin_students_page25' => [
            'sql' => "SELECT s.SID, s.Fname, s.Lname, s.sex, s.email,
                             COALESCE(sp.intake, s.intake) as intake,
                             COALESCE(sp.program_code, s.program) as program_code
                      FROM students s
                      LEFT JOIN student_program sp ON s.SID = sp.Sid
                      ORDER BY s.SID ASC LIMIT 25",
            'types' => '',
            'params' => [],
        ],
        'payments_posted_sum' => [
            'sql' => "SELECT COALESCE(SUM(amount),0) t FROM payments WHERE status = 'posted'",
            'types' => '',
            'params' => [],
        ],
    ];

    echo "\n=== Query timings (ms) ===\n";
    foreach ($queries as $name => $q) {
        $t0 = hrtime(true);
        if ($q['types'] !== '') {
            $stmt = $db->prepare($q['sql']);
            $stmt->bind_param($q['types'], ...$q['params']);
            $stmt->execute();
            $res = $stmt->get_result();
            $n = $res ? $res->num_rows : 0;
            $stmt->close();
        } else {
            $res = $db->query($q['sql']);
            $n = $res ? $res->num_rows : 0;
            if ($res) {
                $res->free();
            }
        }
        $ms = (hrtime(true) - $t0) / 1e6;
        echo sprintf("%s: %.2f ms (%d rows)\n", $name, $ms, $n);
    }

    // Risk engine cost
    require_once $root . '/includes/academic_risk_engine.php';
    $t0 = hrtime(true);
    try {
        $risk = wuc_academic_risk_analyze_student($db, $sid, false);
        $ms = (hrtime(true) - $t0) / 1e6;
        echo sprintf("risk_analyze(persist=false): %.2f ms level=%s score=%s\n", $ms, $risk['risk_level'] ?? '?', $risk['risk_score'] ?? '?');
    } catch (Throwable $e) {
        echo 'risk_analyze FAILED: ' . $e->getMessage() . "\n";
    }
}

// EXPLAIN sample hot queries
echo "\n=== EXPLAIN payments by student ===\n";
if ($sid !== '') {
    $r = $db->query("EXPLAIN SELECT COALESCE(SUM(amount),0) t FROM payments WHERE student_id = '" . $db->real_escape_string($sid) . "' AND status = 'posted'");
    while ($row = $r->fetch_assoc()) {
        echo ($row['type'] ?? '') . ' | key=' . ($row['key'] ?? '') . ' | rows=' . ($row['rows'] ?? '') . ' | Extra=' . ($row['Extra'] ?? '') . "\n";
    }
}

echo "\nDONE\n";
