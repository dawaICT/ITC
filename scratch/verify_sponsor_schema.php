<?php
define('IS_SCRIPT', true);
require __DIR__ . '/../db/connect.php';
mysqli_report(MYSQLI_REPORT_OFF);

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass,$fail; echo ($cond?"[PASS] ":"[FAIL] ").$label."\n"; $cond?$pass++:$fail++; }
function has_col(mysqli $db, $t, $c) { $r=@$db->query("SHOW COLUMNS FROM `$t` LIKE '$c'"); return $r && $r->num_rows>0; }
function has_tbl(mysqli $db, $t) { $r=@$db->query("SHOW TABLES LIKE '$t'"); return $r && $r->num_rows>0; }

check("table sponsor_types exists", has_tbl($db,'sponsor_types'));
check("table programme_sponsorship_eligibility exists", has_tbl($db,'programme_sponsorship_eligibility'));

$r = $db->query("SELECT COUNT(*) c FROM sponsor_types");
$n = $r ? (int)$r->fetch_assoc()['c'] : 0;
check("sponsor_types seeded (>=10)", $n >= 10);
echo "  seeded sponsor_types: {$n}\n";
$r = $db->query("SELECT code,name,is_self_funded,requires_approval FROM sponsor_types ORDER BY sort_order");
while ($x = $r->fetch_assoc()) echo "    - {$x['code']}: {$x['name']} (self_funded={$x['is_self_funded']}, approval={$x['requires_approval']})\n";

foreach (['code','sponsor_type_id','total_allocation','allocation_year'] as $c)
    check("finance_sponsors.{$c} added", has_col($db,'finance_sponsors',$c));

foreach (['sponsor_type_id','program_code','academic_year','reference_number','amount_approved','amount_released','conditions','approval_status','approved_by','approved_at','rejection_reason','created_by'] as $c)
    check("finance_student_sponsors.{$c} added", has_col($db,'finance_student_sponsors',$c));

check("programs.is_teveta_accredited added", has_col($db,'programs','is_teveta_accredited'));

// Record migration (idempotent)
$mig = '2026_sponsorship_management.sql';
$checksum = hash_file('sha256', __DIR__ . '/../migrations/' . $mig);
$applied_by = 'claude-cli';
$stmt = $db->prepare("INSERT INTO schema_migrations (migration, checksum, applied_by) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE checksum=VALUES(checksum), applied_at=CURRENT_TIMESTAMP");
if ($stmt) {
    $stmt->bind_param('sss', $mig, $checksum, $applied_by);
    $ok = $stmt->execute();
    check("migration recorded in schema_migrations", $ok);
    $stmt->close();
} else {
    // schema_migrations PK may not be on `migration`; fall back to plain insert if absent
    check("migration recorded in schema_migrations", false);
    echo "  prepare error: " . $db->error . "\n";
}

echo "\n========================================\nPASS: {$pass}  FAIL: {$fail}\n";
