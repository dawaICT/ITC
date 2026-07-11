<?php
define('IS_SCRIPT', true);
require __DIR__ . '/../db/connect.php';
require __DIR__ . '/../includes/finance_helpers.php'; // log_audit
require __DIR__ . '/../includes/sponsorship_helpers.php';
mysqli_report(MYSQLI_REPORT_OFF);

$pass = 0; $fail = 0;
function check($l, $c) { global $pass,$fail; echo ($c?"[PASS] ":"[FAIL] ").$l."\n"; $c?$pass++:$fail++; }
function approx($a, $b) { return abs($a - $b) < 0.01; }

echo "=== schema ready ===\n";
check("sponsorship_schema_ready", sponsorship_schema_ready($db));

echo "\n=== sponsor types ===\n";
$types = get_sponsor_types($db, true);
check("get_sponsor_types returns >=10", count($types) >= 10);
$self = get_sponsor_type_by_code($db, 'self');
$cdf  = get_sponsor_type_by_code($db, 'cdf');
$teveta = get_sponsor_type_by_code($db, 'teveta');
check("self type is_self_funded=1", $self && (int)$self['is_self_funded'] === 1);
check("cdf type requires_approval=1", $cdf && (int)$cdf['requires_approval'] === 1);

echo "\n=== compute_fee_split (prompt examples) ===\n";
$f = compute_fee_split(1000.0, 100.0);   check("100% CDF -> student 0", approx($f['student_contribution'],0) && approx($f['sponsor_contribution'],1000));
$f = compute_fee_split(1000.0, 75.0);    check("75% -> student 250", approx($f['student_contribution'],250) && approx($f['sponsor_contribution'],750));
$f = compute_fee_split(1000.0, 50.0);    check("50% -> student 500", approx($f['student_contribution'],500));
$f = compute_fee_split(1000.0, 0.0);     check("self/0% -> student 1000", approx($f['student_contribution'],1000));
$f = compute_fee_split(1000.0, null, 600.0); check("fixed amount 600 -> sponsor 600, coverage 60%", approx($f['sponsor_contribution'],600) && approx($f['coverage_percent'],60));
$f = compute_fee_split(1000.0, null, 5000.0); check("over-fixed amount capped at fee", approx($f['sponsor_contribution'],1000) && approx($f['student_contribution'],0));
check("sponsorship_outstanding(1000,300)=700", approx(sponsorship_outstanding(1000,300),700));

// Everything below mutates — wrap in a rolled-back transaction.
$db->autocommit(false);
$db->begin_transaction();
try {
    $actor = 'TEST_ADMIN';
    $studentId = 'CSE26456789';   // exists; program CSE (term)
    $program = 'CSE';

    echo "\n=== create sponsor type (configurable, no code change) ===\n";
    $r = create_sponsor_type($db, ['code' => 'rotary_club', 'name' => 'Rotary Club Bursary', 'requires_approval' => 1], $actor);
    check("create_sponsor_type ok", $r['success'] === true);
    check("duplicate code rejected", create_sponsor_type($db, ['code' => 'rotary_club', 'name' => 'X'], $actor)['success'] === false);

    echo "\n=== programme eligibility ===\n";
    check("CSE NOT yet eligible for CDF", !is_programme_sponsorship_eligible($db, $program, (int)$cdf['id']));
    $r = set_programme_eligibility($db, ['program_code' => $program, 'sponsor_type_id' => (int)$cdf['id'], 'max_sponsored_students' => 2], $actor);
    check("set_programme_eligibility ok", $r['success'] === true);
    check("CSE now eligible for CDF", is_programme_sponsorship_eligible($db, $program, (int)$cdf['id']));
    $opts = get_programme_sponsor_options($db, $program);
    check("programme options include CDF", (bool)array_filter($opts, fn($o) => $o['sponsor_type_code'] === 'cdf'));

    echo "\n=== validation ===\n";
    // Not eligible: TEVETA not configured for CSE
    $errs = validate_sponsorship($db, ['student_id' => $studentId, 'sponsor_type_id' => (int)$teveta['id'], 'program_code' => $program, 'coverage_percent' => 50]);
    check("TEVETA on CSE rejected (not eligible)", (bool)array_filter($errs, fn($e) => stripos($e, 'not eligible') !== false));
    // Amount exceeds fee (override fee=1000, amount=2000)
    $errs = validate_sponsorship($db, ['student_id' => $studentId, 'sponsor_type_id' => (int)$cdf['id'], 'program_code' => $program, 'amount_approved' => 2000, 'programme_fee' => 1000]);
    check("amount > fee rejected", (bool)array_filter($errs, fn($e) => stripos($e, 'exceeds the programme fee') !== false));
    // Expired end date
    $errs = validate_sponsorship($db, ['student_id' => $studentId, 'sponsor_type_id' => (int)$cdf['id'], 'program_code' => $program, 'coverage_percent' => 50, 'end_date' => '2020-01-01']);
    check("expired end_date rejected", (bool)array_filter($errs, fn($e) => stripos($e, 'expired') !== false));
    // Coverage out of range
    $errs = validate_sponsorship($db, ['student_id' => $studentId, 'sponsor_type_id' => (int)$cdf['id'], 'program_code' => $program, 'coverage_percent' => 150]);
    check("coverage>100 rejected", (bool)array_filter($errs, fn($e) => stripos($e, 'between 0 and 100') !== false));
    // Valid
    $errs = validate_sponsorship($db, ['student_id' => $studentId, 'sponsor_type_id' => (int)$cdf['id'], 'program_code' => $program, 'coverage_percent' => 75, 'academic_year' => '2026']);
    check("valid CDF 75% passes", empty($errs));

    echo "\n=== create student sponsorship ===\n";
    $r = create_student_sponsorship($db, ['student_id' => $studentId, 'sponsor_type_id' => (int)$cdf['id'], 'program_code' => $program, 'academic_year' => '2026', 'coverage_percent' => 75, 'reference_number' => 'CDF-2026-001'], $actor);
    check("create CDF sponsorship ok", $r['success'] === true);
    check("CDF starts pending (requires approval)", ($r['approval_status'] ?? '') === 'pending');
    $cdfId = $r['id'] ?? 0;

    // Self-funded auto-approves
    $r2 = create_student_sponsorship($db, ['student_id' => 'ICT26307691', 'sponsor_type_id' => (int)$self['id'], 'program_code' => 'ICT-013', 'academic_year' => '2026', 'coverage_percent' => 0], $actor);
    check("self-funded auto-approved", ($r2['approval_status'] ?? '') === 'approved');

    echo "\n=== duplicate prevention ===\n";
    $dup = create_student_sponsorship($db, ['student_id' => $studentId, 'sponsor_type_id' => (int)$cdf['id'], 'program_code' => $program, 'academic_year' => '2026', 'coverage_percent' => 50], $actor);
    check("duplicate CDF for same student/year rejected", $dup['success'] === false);

    echo "\n=== capacity cap (max 2) ===\n";
    create_student_sponsorship($db, ['student_id' => 'CAPTEST2', 'sponsor_type_id' => (int)$cdf['id'], 'program_code' => $program, 'academic_year' => '2026', 'coverage_percent' => 50], $actor);
    $usage = programme_sponsor_usage($db, $program, (int)$cdf['id'], '2026');
    echo "  usage: max={$usage['max']} used={$usage['used']} available=".var_export($usage['available'],true)."\n";
    $cap = create_student_sponsorship($db, ['student_id' => 'CAPTEST3', 'sponsor_type_id' => (int)$cdf['id'], 'program_code' => $program, 'academic_year' => '2026', 'coverage_percent' => 50], $actor);
    check("3rd CDF student blocked by cap=2", $cap['success'] === false);

    echo "\n=== approval workflow ===\n";
    check("approve ok", approve_student_sponsorship($db, $cdfId, $actor)['success'] === true);
    $got = get_student_sponsorship($db, $studentId, true);
    check("now returns approved active sponsorship", $got && $got['approval_status'] === 'approved');

    echo "\n=== release tracking (cap at approved) ===\n";
    // set approved amount so release cap applies
    $db->query("UPDATE finance_student_sponsors SET amount_approved=1000 WHERE id={$cdfId}");
    check("release 400 ok", record_sponsor_release($db, $cdfId, 400, $actor)['success'] === true);
    $rel = record_sponsor_release($db, $cdfId, 9999, $actor);
    check("over-release blocked", $rel['success'] === false);

    echo "\n=== student summary (fee 1000, CDF 75% percentage path) ===\n";
    // Clear the fixed approved amount set during the release test so the summary
    // exercises the percentage (coverage_percent=75) path deterministically.
    $db->query("UPDATE finance_student_sponsors SET amount_approved=NULL WHERE id={$cdfId}");
    $sum = student_sponsorship_summary($db, $studentId, 1000.0);
    echo "  is_sponsored={$sum['is_sponsored']} sponsor={$sum['sponsor_contribution']} student={$sum['student_contribution']}\n";
    check("summary: sponsored", $sum['is_sponsored'] === true);
    check("summary: sponsor 750 / student 250", approx($sum['sponsor_contribution'],750) && approx($sum['student_contribution'],250));

    echo "\n=== reject + cancel ===\n";
    check("reject ok", reject_student_sponsorship($db, $cdfId, $actor, 'docs missing')['success'] === true);
    check("cancel ok", cancel_student_sponsorship($db, $cdfId, $actor, 'withdrawn')['success'] === true);

} catch (Throwable $e) {
    echo "  EXCEPTION: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
    $fail++;
} finally {
    $db->rollback();
    $db->autocommit(true);
}

echo "\n========================================\nPASS: {$pass}  FAIL: {$fail}\n";
