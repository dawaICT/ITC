<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/db/connect.php';

header('Content-Type: text/plain; charset=utf-8');

function tableExists(mysqli $db, string $table): bool {
    $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

echo "=== APPLICANT PORTAL DEBUG ===\n\n";

foreach (['online_applicants', 'processed_applicants', 'processed_applicants_added', 'programs'] as $t) {
    echo "Table {$t}: " . (tableExists($db, $t) ? 'EXISTS' : 'MISSING') . "\n";
}

if (tableExists($db, 'online_applicants')) {
    echo "\n--- online_applicants columns ---\n";
    $r = $db->query('DESCRIBE online_applicants');
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . ' | ' . $row['Type'] . "\n";
    }

    echo "\n--- online_applicants status counts ---\n";
    $r = $db->query('SELECT COALESCE(status, "(NULL)") AS st, COUNT(*) c FROM online_applicants GROUP BY status ORDER BY c DESC');
    while ($row = $r->fetch_assoc()) {
        echo $row['st'] . ' => ' . $row['c'] . "\n";
    }

    $pendingLower = (int)$db->query("SELECT COUNT(*) FROM online_applicants WHERE status = 'pending'")->fetch_row()[0];
    $pendingUpper = (int)$db->query("SELECT COUNT(*) FROM online_applicants WHERE status = 'Pending'")->fetch_row()[0];
    $pendingNull = (int)$db->query("SELECT COUNT(*) FROM online_applicants WHERE status IS NULL")->fetch_row()[0];
    $total = (int)$db->query("SELECT COUNT(*) FROM online_applicants")->fetch_row()[0];

    echo "\n--- query mismatch (dashboard vs handlers) ---\n";
    echo "Total online_applicants: {$total}\n";
    echo "status='pending' (handlers): {$pendingLower}\n";
    echo "status='Pending' (index.php): {$pendingUpper}\n";
    echo "status IS NULL: {$pendingNull}\n";
    echo "index.php query (Pending OR NULL): " . (int)$db->query("SELECT COUNT(*) FROM online_applicants WHERE status = 'Pending' OR status IS NULL")->fetch_row()[0] . "\n";
    echo "dashboard_admin query (pending OR NULL): " . (int)$db->query("SELECT COUNT(*) FROM online_applicants WHERE status = 'pending' OR status IS NULL")->fetch_row()[0] . "\n";
}

if (tableExists($db, 'processed_applicants')) {
    echo "\n--- processed_applicants status counts ---\n";
    $r = $db->query('SELECT COALESCE(status, "(NULL)") AS st, COUNT(*) c FROM processed_applicants GROUP BY status ORDER BY c DESC');
    while ($row = $r->fetch_assoc()) {
        echo $row['st'] . ' => ' . $row['c'] . "\n";
    }

    echo "\n--- processed_applicants columns (program-related) ---\n";
    $r = $db->query('DESCRIBE processed_applicants');
    while ($row = $r->fetch_assoc()) {
        if (stripos($row['Field'], 'program') !== false || $row['Field'] === 'status') {
            echo $row['Field'] . ' | ' . $row['Type'] . "\n";
        }
    }
}

if (tableExists($db, 'online_applicants') && tableExists($db, 'programs')) {
    echo "\n--- program field mismatch sample ---\n";
    $r = $db->query("SELECT oa.id, oa.program, p.program_code, p.program_name
        FROM online_applicants oa
        LEFT JOIN programs p ON p.program_code = oa.program OR p.program_name = oa.program
        LIMIT 5");
    while ($row = $r->fetch_assoc()) {
        echo "id={$row['id']} program='{$row['program']}' matched_code=" . ($row['program_code'] ?? 'NULL') . "\n";
    }
}

echo "\n--- online_applicants rows ---\n";
$r = $db->query('SELECT id, Fname, Lname, program, status, dte_adm FROM online_applicants ORDER BY id');
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n--- processed_applicants rows ---\n";
$r = $db->query('SELECT id, applicant_id, Fname, Lname, program, program_code, status FROM processed_applicants ORDER BY id');
while ($row = $r->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n--- programs sample (code vs name) ---\n";
$r = $db->query('SELECT program_code, program_name FROM programs LIMIT 5');
while ($row = $r->fetch_assoc()) {
    echo $row['program_code'] . ' | ' . $row['program_name'] . "\n";
}

echo "\n--- online form stores program_name not code? ---\n";
$r = $db->query("SELECT COUNT(*) FROM online_applicants oa INNER JOIN programs p ON p.program_name = oa.program");
echo 'match on program_name: ' . $r->fetch_row()[0] . "\n";
$r = $db->query("SELECT COUNT(*) FROM online_applicants oa INNER JOIN programs p ON p.program_code = oa.program");
echo 'match on program_code: ' . $r->fetch_row()[0] . "\n";

echo "\n--- ICT-013 in programs? ---\n";
$r = $db->query("SELECT program_code, program_name FROM programs WHERE program_code = 'ICT-013' OR program_name LIKE '%ICT%' LIMIT 10");
if ($r->num_rows === 0) {
    echo "ICT-013 NOT FOUND in programs catalogue\n";
} else {
    while ($row = $r->fetch_assoc()) {
        echo $row['program_code'] . ' | ' . $row['program_name'] . "\n";
    }
}

echo "\n--- index.php total vs pending inflation ---\n";
$totalAll = (int)$db->query('SELECT COUNT(*) FROM online_applicants')->fetch_row()[0];
$totalPending = (int)$db->query("SELECT COUNT(*) FROM online_applicants WHERE status = 'pending'")->fetch_row()[0];
$totalProcessed = (int)$db->query("SELECT COUNT(*) FROM processed_applicants")->fetch_row()[0];
echo "index.php 'Total Applications' counts ALL online_applicants: {$totalAll}\n";
echo "Actual pending in online_applicants: {$totalPending}\n";
echo "Rows in processed_applicants (moved out of queue): {$totalProcessed}\n";
echo "Inflation: total includes {$totalAll} rows but only {$totalPending} are still pending\n";

echo "\nDone.\n";
