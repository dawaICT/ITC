<?php
// simulate_fee_check.php
// Small utility to simulate and display fee threshold checks using FeeGuard functions
// Usage (web):  /students/simulate_fee_check.php?Sid=test123&year=1&semester=1
// Usage (cli): php students/simulate_fee_check.php test123 1 1

declare(strict_types=1);

// Locate DB connect and FeeGuard
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/role_helpers.php';
require_once __DIR__ . '/includes/FeeGuard.php';

if (PHP_SAPI !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['staff_id']) || (!hasRole(ROLE_SYSTEMS_ADMIN) && !hasRole(ROLE_ACCOUNTANT))) {
        http_response_code(403);
        die("Access Denied: Admin or Accountant only.");
    }
}

// Accept inputs from CLI or GET
$sid = null;
$year = 1;
$semester = 1;

if (PHP_SAPI === 'cli') {
    global $argv;
    if (isset($argv[1])) $sid = (string)$argv[1];
    if (isset($argv[2])) $year = (int)$argv[2];
    if (isset($argv[3])) $semester = (int)$argv[3];
} else {
    if (isset($_GET['Sid'])) $sid = (string)$_GET['Sid'];
    if (isset($_GET['year'])) $year = (int)$_GET['year'];
    if (isset($_GET['semester'])) $semester = (int)$_GET['semester'];
}

if (empty($sid)) {
    // Show a small selection of sample SIDs
    $sample = [];
    if ($r = $db->query("SELECT SID, Fname, Lname FROM students LIMIT 8")) {
        while ($row = $r->fetch_assoc()) { $sample[] = $row; }
        $r->free();
    }

    if (PHP_SAPI === 'cli') {
        echo "No Sid provided. Example usage:\n";
        echo "  php students/simulate_fee_check.php <Sid> <year> <semester>\n\n";
        echo "Available students:\n";
        foreach ($sample as $s) { echo " - {$s['SID']} ({$s['Fname']} {$s['Lname']})\n"; }
        exit(0);
    }

    // Web UI: show simple form
    require_once __DIR__ . '/../includes/page_meta.php';
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Fee Check Simulator</title><link rel="stylesheet" href="/wucportal/css/admin-style.css">';
    wuc_portal_favicon_links();
    echo '</head><body style="font-family:Arial,Helvetica,sans-serif;padding:20px">';
    echo '<h3>Fee Check Simulator</h3>';
    echo '<form method="get"><label>Sid: <input name="Sid" value="" /></label> &nbsp; <label>Year: <input name="year" value="1" size="2" /></label> &nbsp; <label>Semester: <input name="semester" value="1" size="2" /></label> &nbsp; <button type="submit">Run</button></form>';
    echo '<h4>Sample Students</h4><ul>';
    foreach ($sample as $s) { echo '<li>' . htmlspecialchars($s['SID']) . ' — ' . htmlspecialchars($s['Fname'] . ' ' . $s['Lname']) . '</li>'; }
    echo '</ul></body></html>';
    exit(0);
}

// Run checks
$program = fg_student_program($db, $sid);
$required = $program ? fg_required_fee($db, $program, $year, $semester) : 0.0;
$latestBalance = fg_latest_term_balance($db, $sid, $year, $semester);
$check = fg_check_fee_threshold($db, $sid, $year, $semester, 50.0);

$paid = 0.0;
if ($latestBalance !== null) {
    $paid = max(0.0, $required - (float)$latestBalance);
}

$percent = $required > 0 ? round(($paid / $required) * 100, 2) : 0.0;

if (PHP_SAPI === 'cli') {
    echo "Fee Check Simulator\n";
    echo "Sid: $sid\n";
    echo "Program: " . ($program ?? 'N/A') . "\n";
    echo "Year: $year, Semester: $semester\n";
    echo "Required fee: ZMW " . number_format($required,2) . "\n";
    echo "Latest balance (owed on record): " . (($latestBalance === null) ? 'n/a' : 'ZMW ' . number_format($latestBalance,2)) . "\n";
    echo "Paid amount (computed): ZMW " . number_format($paid,2) . "\n";
    echo "Percent paid: $percent%\n";
    echo "Threshold OK: " . ($check['ok'] ? 'YES' : 'NO') . "\n";
    echo "Message: " . ($check['message'] ?? '') . "\n";
    exit(0);
}

// Web output
echo '<!doctype html><html><head><meta charset="utf-8"><title>Fee Check Result</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"></head><body class="p-4">';
echo '<div class="container"><h3>Fee Check Result</h3>';
echo '<table class="table table-hover align-middle"><tbody>';
echo '<tr><th>Sid</th><td>' . htmlspecialchars($sid) . '</td></tr>';
echo '<tr><th>Program</th><td>' . htmlspecialchars((string)($program ?? 'N/A')) . '</td></tr>';
echo '<tr><th>Year / Semester</th><td>' . htmlspecialchars((string)$year) . ' / ' . htmlspecialchars((string)$semester) . '</td></tr>';
echo '<tr><th>Required Fee</th><td>ZMW ' . number_format($required,2) . '</td></tr>';
echo '<tr><th>Latest Balance (owed)</th><td>' . (($latestBalance === null) ? 'n/a' : 'ZMW ' . number_format($latestBalance,2)) . '</td></tr>';
echo '<tr><th>Paid (computed)</th><td>ZMW ' . number_format($paid,2) . ' (' . $percent . '%)</td></tr>';
echo '<tr><th>Threshold OK</th><td>' . ($check['ok'] ? '<span class="text-success">YES</span>' : '<span class="text-danger">NO</span>') . '</td></tr>';
echo '<tr><th>Message</th><td>' . htmlspecialchars($check['message'] ?? '') . '</td></tr>';
echo '</tbody></table>';
echo '<p><a class="btn btn-sm btn-outline-primary" href="/students/courseReg.php?Sid=' . urlencode($sid) . '">Open Course Registration</a> <a class="btn btn-sm btn-secondary" href="/students/simulate_fee_check.php">Back</a></p>';
echo '</div></body></html>';

?>
