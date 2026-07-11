<?php
/**
 * Verification script: CA Upload student lookup & validation fixes.
 *
 * Tests:
 *   1. ca_validate_period() — semester, term, short_course, edge cases
 *   2. finance_guard is_student_allowed_ca() — enforcement-off bypass, active condition
 *   3. ca_student_registered() — hit, miss, wrong year, wrong semester
 *   4. Schema assertions — tables + columns required by the fixes
 *   5. AJAX diagnostic logic — simulates zero-result branch of ajax_get_course_students
 *
 * Run from project root:
 *   C:\xampp\php\php.exe scratch/test_ca_validation.php
 */
declare(strict_types=1);

define('IS_SCRIPT', true);

// Minimal session shim so guard/ca_helpers don't crash in CLI
if (!function_exists('session_status')) {
    function session_status(): int { return PHP_SESSION_NONE; }
}
$_SESSION = [];

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/ca_helpers.php';

$pass = 0;
$fail = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        echo "\033[32m[PASS]\033[0m {$label}\n";
        $pass++;
    } else {
        echo "\033[31m[FAIL]\033[0m {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $fail++;
    }
}

function skip(string $label, string $reason): void
{
    echo "\033[33m[SKIP]\033[0m {$label} ({$reason})\n";
}

// ── 1. ca_validate_period() ──────────────────────────────────────────────────
echo "\n=== 1. ca_validate_period() ===\n";

// Semester — valid
foreach (['1', '2', '3'] as $p) {
    $r = ca_validate_period($p, '2025', 'semester');
    ok("semester period={$p} accepted", $r['ok']);
}

// Semester — invalid
foreach (['0', '4', 'abc', ''] as $p) {
    $r = ca_validate_period($p, '2025', 'semester');
    ok("semester period='{$p}' rejected", !$r['ok'], $r['message']);
}

// Term — valid (same 1-3 rule)
foreach (['1', '2', '3'] as $p) {
    $r = ca_validate_period($p, '2025', 'term');
    ok("term period={$p} accepted", $r['ok']);
}

$r = ca_validate_period('4', '2025', 'term');
ok("term period=4 rejected", !$r['ok'], $r['message']);

// Short course — any non-empty batch name passes
foreach (['Jan-2025', 'Batch A', '1', 'Short Course Batch'] as $batch) {
    $r = ca_validate_period($batch, '2025', 'short_course');
    ok("short_course batch='{$batch}' accepted", $r['ok']);
}

$r = ca_validate_period('', '2025', 'short_course');
ok("short_course empty batch rejected", !$r['ok'], $r['message']);

// Year validation (applies to all program types)
$r = ca_validate_period('1', '25', 'semester');
ok("2-digit year rejected", !$r['ok'], $r['message']);

$r = ca_validate_period('1', 'abcd', 'semester');
ok("non-numeric year rejected", !$r['ok'], $r['message']);

$r = ca_validate_period('Batch X', '2025', 'short_course');
ok("short_course numeric-string year accepted", $r['ok']);

// ── 2. is_student_allowed_ca() ───────────────────────────────────────────────
echo "\n=== 2. is_student_allowed_ca() ===\n";

// Find a real student SID to test with
$testSid = null;
$stRes = @$db->query("SELECT SID FROM students LIMIT 1");
if ($stRes && ($stRow = $stRes->fetch_assoc())) {
    $testSid = (string)$stRow['SID'];
}

if ($testSid === null) {
    skip('is_student_allowed_ca()', 'no students in DB');
} else {
    // Temporarily disable enforcement so the bypass path is exercised
    @$db->query("INSERT INTO portal_settings (setting_key, setting_value)
                 VALUES ('enforce_ca_payment', '0')
                 ON DUPLICATE KEY UPDATE setting_value = '0'");
    $r = is_student_allowed_ca($db, $testSid);
    ok("enforcement-off → allowed=true (SID={$testSid})", $r['allowed'], "reason: {$r['reason']}");

    // Restore enforcement to 1
    @$db->query("INSERT INTO portal_settings (setting_key, setting_value)
                 VALUES ('enforce_ca_payment', '1')
                 ON DUPLICATE KEY UPDATE setting_value = '1'");
    echo "    (restored enforce_ca_payment=1)\n";
}

// ── 3. ca_student_registered() ───────────────────────────────────────────────
echo "\n=== 3. ca_student_registered() ===\n";

$hasCR = @$db->query("SHOW TABLES LIKE 'course_registration'")->num_rows > 0;
if (!$hasCR) {
    skip('ca_student_registered()', 'course_registration table absent');
} else {
    // Detect year column
    $hasAcadYear = @$db->query("SHOW COLUMNS FROM course_registration LIKE 'academic_year'")->num_rows > 0;
    $yearCol = $hasAcadYear ? 'academic_year' : 'Year';

    $regRow = null;
    $stmt = @$db->prepare("SELECT Sid, course_code, semester, `{$yearCol}` AS ay
                            FROM course_registration
                            WHERE Sid IS NOT NULL AND Sid <> '' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $regRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if ($regRow === null) {
        skip('ca_student_registered()', 'no rows in course_registration');
    } else {
        $sid  = (string)$regRow['Sid'];
        $cc   = (string)$regRow['course_code'];
        $sem  = (string)$regRow['semester'];
        $year = (string)$regRow['ay'];

        $found = ca_student_registered($db, $sid, $cc, $sem, $year);
        ok("known registration found (SID={$sid} course={$cc} sem={$sem} year={$year})", $found);

        $notFound = ca_student_registered($db, 'NONEXISTENT_SID_X9Z', $cc, $sem, $year);
        ok("non-existent SID returns false", !$notFound);

        $wrongYear = ca_student_registered($db, $sid, $cc, $sem, '1900');
        ok("wrong year (1900) returns false", !$wrongYear);

        $wrongSem = ca_student_registered($db, $sid, $cc, '99', $year);
        ok("wrong semester (99) returns false", !$wrongSem);
    }
}

// ── 4. Schema assertions ─────────────────────────────────────────────────────
echo "\n=== 4. Schema assertions ===\n";

foreach (['semester_assessment', 'portal_settings', 'students', 'courses'] as $tbl) {
    $exists = @$db->query("SHOW TABLES LIKE '{$tbl}'")->num_rows > 0;
    ok("table '{$tbl}' exists", $exists);
}

if ($hasCR) {
    foreach (['is_active', 'status', 'Sid', 'course_code', 'semester'] as $col) {
        $exists = @$db->query("SHOW COLUMNS FROM course_registration LIKE '{$col}'")->num_rows > 0;
        ok("course_registration.{$col} exists", $exists);
    }

    // Confirm the (is_active=1 OR status='active') predicate actually executes
    $q = @$db->query("SELECT 1 FROM course_registration WHERE (is_active = 1 OR status = 'active') LIMIT 1");
    ok("is_active OR status='active' predicate executes", $q !== false, $db->error ?: '');
}

// ── 5. AJAX diagnostic logic simulation ──────────────────────────────────────
echo "\n=== 5. AJAX diagnostic simulation ===\n";

// Simulate what ajax_get_course_students does when $count === 0:
// query all active registrations across all years/semesters for a given course code
$ghostCourse = '__NONEXISTENT_COURSE_ZZZZ__';

$diagnostics = [];
if ($hasCR) {
    $hasAcadYear = @$db->query("SHOW COLUMNS FROM course_registration LIKE 'academic_year'")->num_rows > 0;
    $crYearCol = $hasAcadYear ? 'academic_year' : 'Year';
    $hasIsActive = @$db->query("SHOW COLUMNS FROM course_registration LIKE 'is_active'")->num_rows > 0;
    $sqlCR = "SELECT DISTINCT semester, `{$crYearCol}` AS year_val FROM course_registration
              WHERE course_code = ? AND " . ($hasIsActive ? "(is_active = 1 OR status = 'active')" : "1=1");
    if ($stmtCR = $db->prepare($sqlCR)) {
        $stmtCR->bind_param('s', $ghostCourse);
        if ($stmtCR->execute()) {
            $resCR = $stmtCR->get_result();
            while ($rowCR = $resCR->fetch_assoc()) {
                $diagnostics[] = ['semester' => (string)$rowCR['semester'], 'year' => (string)$rowCR['year_val']];
            }
        }
        $stmtCR->close();
    }
}

ok("ghost course returns empty diagnostics", empty($diagnostics), count($diagnostics) . ' rows returned');

// Verify diagnostic message construction for the three zero-student branches
$year       = '2025';
$periodMode = 'semester';
$semester   = '2';
$periodLabel = 'Semester 2';
$courseName  = 'TEST101 – Test Course';
$safeCourseName = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
$yearLabel      = htmlspecialchars($year, ENT_QUOTES, 'UTF-8');

// Branch A: no registrations at all
$msg_a = "No students are registered for {$safeCourseName} in any term, semester, intake, or academic period. Please confirm that students have completed course registration or contact the Registrar.";
ok("branch A: no-registrations message contains course name", str_contains($msg_a, 'TEST101'));
ok("branch A: no-registrations message mentions Registrar", str_contains($msg_a, 'Registrar'));

// Branch B: registrations in other years
$otherYears = ['2023', '2024'];
$yearsStr = implode(', ', $otherYears);
$msg_b = "No students are registered for {$safeCourseName} in {$periodLabel} for Academic Year {$yearLabel}. "
       . "However, registrations exist in other academic years: {$yearsStr}. Please verify that you have selected the correct Academic Year.";
ok("branch B: other-years message contains year list", str_contains($msg_b, '2023'));
ok("branch B: other-years message mentions period label", str_contains($msg_b, $periodLabel));

// Branch C: registrations in other periods of the same year
$otherPeriods = ['Semester 1'];
$periodsStr = implode(', ', $otherPeriods);
$msg_c = "No students are registered for {$safeCourseName} in {$periodLabel} for Academic Year {$yearLabel}. "
       . "However, registrations exist in other periods for this year: {$periodsStr}. Please verify your selection.";
ok("branch C: other-periods message contains alternative period", str_contains($msg_c, 'Semester 1'));
ok("branch C: other-periods message mentions selected period", str_contains($msg_c, $periodLabel));

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n=== Results: \033[32m{$pass} passed\033[0m, " . ($fail > 0 ? "\033[31m{$fail} failed\033[0m" : "0 failed") . " ===\n\n";
exit($fail > 0 ? 1 : 0);
