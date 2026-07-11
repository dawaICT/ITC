<?php
define('IS_SCRIPT', true);
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/student_progression_report.php';

$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$filters = ['flag' => 'all', 'q' => '', 'academic_year' => '', 'semester' => '', 'course_code' => '', 'program_code' => ''];

// ── Part 1: current real data — dropped courses gone, active not false-flagged ──
echo "=== Part 1: real data ===\n";
$report = student_progression_report($db, $filters, 'admin');
$codes = array_map(fn($r) => $r['course_code'], $report['rows']);
echo "Flagged rows now: " . count($report['rows']) . " (was 13 before fix)\n";

foreach (['COM101','EL900','EL901','EL902','EL903','EL904','EL905','EL906','EL907','EL908'] as $dropped) {
    check("dropped course {$dropped} is excluded", !in_array($dropped, $codes, true));
}
foreach (['GEN101','GEN102','GEN103'] as $active) {
    check("actively-registered {$active} is NOT falsely flagged", !in_array($active, $codes, true));
}

// ── Part 2: period label logic (pure) ──
echo "\n=== Part 2: period label logic ===\n";
function label_for($semester, $periodMode) {
    $d = $semester ?: '-';
    if ($d === 'short_course') return 'Short Course';
    if (preg_match('/^[123]$/', (string)$d)) {
        return (($periodMode ?? '') === 'term' ? 'Term ' : 'Sem ') . $d;
    }
    return $d;
}
check("term program sem=1 => 'Term 1'", label_for('1','term') === 'Term 1');
check("semester program sem=1 => 'Sem 1'", label_for('1','semester') === 'Sem 1');
check("unknown mode sem=2 => 'Sem 2'", label_for('2','') === 'Sem 2');
check("short_course => 'Short Course'", label_for('short_course','term') === 'Short Course');

echo "getProgramPeriodMode: CSE=" . getProgramPeriodMode($db,'CSE')
   . " DTL=" . getProgramPeriodMode($db,'DTL') . "\n";
check("CSE resolves to term", getProgramPeriodMode($db,'CSE') === 'term');

// ── Part 3: NON-DESTRUCTIVE transactional test — a failed ACTIVE course shows ──
// COM101 is currently dropped (is_active=0) and passing. Inside a transaction we
// (a) activate its registration and (b) zero its canonical assessment so it fails,
// then assert it surfaces correctly. Everything is rolled back — no data changes.
echo "\n=== Part 3: failed active course in a term program (rolled back) ===\n";
mysqli_report(MYSQLI_REPORT_OFF); // handle errors locally; don't trip global handler
$db->autocommit(false);
$db->begin_transaction();
try {
    $sid = 'CSE26456789';            // program CSE = term-based
    $course = 'COM101';

    $db->query("UPDATE course_registration SET is_active=1 WHERE UPPER(TRIM(Sid))='CSE26456789' AND UPPER(TRIM(course_code))='COM101'");
    echo "  activated registrations: {$db->affected_rows}\n";
    $db->query("UPDATE semester_assessment SET A1=0,A2=0,A3=0,T1=0,T2=0,Exam=0,Total_CA=0 WHERE Course_Code='COM101' AND Sid='CSE26456789'");
    echo "  zeroed assessment rows: {$db->affected_rows}\n";

    $report2 = student_progression_report($db, $filters, 'admin');
    $match = null;
    foreach ($report2['rows'] as $r) {
        if ($r['student_id'] === $sid && $r['course_code'] === $course) { $match = $r; break; }
    }
    check("failed active course {$course} now appears as an alert", $match !== null);
    if ($match) {
        check("  it is flagged failed", !empty($match['failed']));
        check("  period_mode stamped as 'term'", ($match['period_mode'] ?? '') === 'term');
        echo "  -> total_score=" . var_export($match['total_score'], true) . ", semester=" . var_export($match['semester'], true) . "\n";
        echo "  -> rendered period label: '" . label_for($match['semester'], $match['period_mode']) . "'\n";
        check("  label renders as 'Term 1' (NOT 'Sem 1')", label_for($match['semester'], $match['period_mode']) === 'Term 1');
    }
} catch (Throwable $e) {
    echo "  EXCEPTION: " . $e->getMessage() . "\n";
} finally {
    $db->rollback();
    $db->autocommit(true);
}

// Re-confirm rollback left real data untouched
$after = student_progression_report($db, $filters, 'admin');
check("rollback restored data (flagged count unchanged)", count($after['rows']) === count($report['rows']));

echo "\n========================================\n";
echo "PASS: {$pass}   FAIL: {$fail}\n";
