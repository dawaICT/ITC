<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$envFile = dirname(__DIR__, 3) . '/wucportal-var/config/environment.php';
if (!is_file($envFile)) {
    fwrite(STDERR, "Missing environment config: {$envFile}\n");
    exit(1);
}
/** @var array<string,string> $env */
$env = require $envFile;
$db = new mysqli(
    $env['WUC_DB_HOST'] ?? '127.0.0.1',
    $env['WUC_DB_USER'] ?? 'root',
    $env['WUC_DB_PASSWORD'] ?? '',
    $env['WUC_DB_NAME'] ?? 'wucportal',
    (int)($env['WUC_DB_PORT'] ?? 3306)
);
if ($db->connect_error) {
    fwrite(STDERR, 'DB connect failed: ' . $db->connect_error . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$ids = ['ITC907', 'ITC900'];

function section(string $title): void {
    echo "\n" . str_repeat('=', 60) . "\n$title\n" . str_repeat('=', 60) . "\n";
}

section('STAFF rows');
foreach ($ids as $sid) {
    $stmt = $db->prepare('SELECT id, staff_id, Fname, Lname, email, role, status FROM staff WHERE staff_id = ?');
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo "$sid: " . json_encode($row ?: ['NOT_FOUND' => true], JSON_PRETTY_PRINT) . "\n";
    $stmt->close();
}

section('user_credentials');
foreach ($ids as $sid) {
    $stmt = $db->prepare('SELECT * FROM user_credentials WHERE staff_id = ? LIMIT 1');
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo "$sid: " . json_encode($row ?: ['NOT_FOUND' => true]) . "\n";
    $stmt->close();
}

section('staff_positions');
$r = $db->query("SELECT sp.staff_id, p.PosName
    FROM staff_positions sp
    JOIN positions p ON p.PosID = sp.PosID
    WHERE sp.staff_id IN ('ITC907','ITC900')");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

section('course_lecturer assignments');
$r = $db->query("SELECT staff_id, course_code, status, semester, academic_year, program_code
    FROM course_lecturer
    WHERE staff_id IN ('ITC907','ITC900')
    ORDER BY staff_id, course_code, status");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

section('course_lecturer vs courses catalog (orphans)');
$r = $db->query("SELECT cl.staff_id, cl.course_code, cl.status,
    CASE WHEN c.course_code IS NULL THEN 'MISSING' ELSE 'OK' END AS catalog
    FROM course_lecturer cl
    LEFT JOIN courses c ON c.course_code = cl.course_code
    WHERE cl.staff_id IN ('ITC907','ITC900')
    ORDER BY cl.staff_id, cl.course_code");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

section('Dashboard stat: distinct courses (INNER JOIN courses, active status)');
foreach ($ids as $sid) {
    $stmt = $db->prepare("SELECT COUNT(DISTINCT cl.course_code) AS cnt
        FROM course_lecturer cl
        INNER JOIN courses c ON c.course_code = cl.course_code
        WHERE cl.staff_id = ? AND cl.status IN ('active','assigned','open')");
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $stmt2 = $db->prepare("SELECT COUNT(DISTINCT cl.course_code) AS cnt
        FROM course_lecturer cl
        WHERE cl.staff_id = ? AND cl.status <> 'inactive'");
    $stmt2->bind_param('s', $sid);
    $stmt2->execute();
    $cnt2 = (int)$stmt2->get_result()->fetch_assoc()['cnt'];
    $stmt2->close();

    echo "$sid: stat_card_count=$cnt, assigned_table_count=$cnt2\n";
}

section('Student counts via course_registration');
foreach ($ids as $sid) {
    $stmt = $db->prepare("SELECT COUNT(DISTINCT cr.Sid) AS total
        FROM course_registration cr
        INNER JOIN course_lecturer lc ON cr.course_code = lc.course_code
        WHERE lc.staff_id = ?");
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    echo "$sid: my_students_stat=$total\n";
}

section('course_schedule');
$r = $db->query("SELECT s.staff_id, cs.course_code, cs.day_of_week, cs.lecturer_id, cs.schedule_type
    FROM course_schedule cs
    LEFT JOIN staff s ON s.id = cs.lecturer_id
    WHERE s.staff_id IN ('ITC907','ITC900')
       OR cs.course_code IN (SELECT course_code FROM course_lecturer WHERE staff_id IN ('ITC907','ITC900'))
    ORDER BY s.staff_id, cs.day_of_week, cs.course_code");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

section('semester_assessment posted counts');
foreach ($ids as $sid) {
    $cols = [];
    $desc = $db->query("DESCRIBE semester_assessment");
    while ($c = $desc->fetch_assoc()) {
        $cols[] = $c['Field'];
    }
    $postedCol = null;
    foreach (['posted_by', 'lecturer_id', 'staff_id'] as $c) {
        if (in_array($c, $cols, true)) {
            $postedCol = $c;
            break;
        }
    }
    if ($postedCol) {
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM semester_assessment WHERE `$postedCol` = ?");
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        echo "$sid: posted_by_col=$postedCol count=" . $stmt->get_result()->fetch_assoc()['c'] . "\n";
        $stmt->close();
    } else {
        echo "$sid: no lecturer column on semester_assessment\n";
    }
}

section('Simulate wuc_lecturer_insights assigned courses');
require_once dirname(__DIR__) . '/includes/lecturer_insights_engine.php';
foreach ($ids as $sid) {
    $insights = wuc_lecturer_insights($db, $sid);
    echo "$sid: assigned=" . $insights['totals']['assigned_courses']
        . " registered=" . $insights['totals']['registered_students']
        . " missing_ca=" . $insights['totals']['missing_ca'] . "\n";
    foreach ($insights['courses'] as $c) {
        echo "  {$c['course_code']}: reg={$c['registered']} with_ca={$c['with_ca']} missing={$c['missing_ca']}\n";
    }
}

section('Academic risk summary');
require_once dirname(__DIR__) . '/includes/academic_risk_engine.php';
foreach ($ids as $sid) {
    try {
        $sum = wuc_academic_risk_lecturer_summary($db, $sid);
        echo "$sid: " . json_encode($sum) . "\n";
    } catch (Throwable $e) {
        echo "$sid ERROR: " . $e->getMessage() . "\n";
    }
}

section('portal_access');
require_once dirname(__DIR__) . '/includes/portal_access.php';
if (!function_exists('wuc_portal_access_for_staff')) {
    echo "wuc_portal_access_for_staff not available\n";
} else {
    foreach ($ids as $sid) {
        foreach (['lecturer', 'elearning', 'academic'] as $portal) {
            $ok = wuc_portal_access_for_staff($db, $sid, $portal);
            echo "$sid portal=$portal access=" . ($ok ? 'YES' : 'NO') . "\n";
        }
    }
}
