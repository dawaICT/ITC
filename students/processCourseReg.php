<?php
// Enhanced course registration submission with robust schema handling and error safety
declare(strict_types=1);

// First make sure session is started to prevent session_start errors
if (session_status() === PHP_SESSION_NONE) { 
    session_start();
}

// Log request details and session info for debugging
error_log("processCourseReg.php - Request start: " . json_encode([
    'session_id' => session_id(),
    'post_count' => count($_POST),
    'sid_in_session' => isset($_SESSION['Sid']),
    'last_activity' => isset($_SESSION['last_activity']) ? time() - $_SESSION['last_activity'] : 'not set',
]));

// Refresh session activity timestamp immediately
$_SESSION['last_activity'] = time();

// Include other required files
require_once __DIR__ . '/includes/guard.php';

$_SESSION['student_notice'] = 'Courses are registered automatically when you complete period registration.';
header('Location: registration.php');
exit;

// Legacy course registration processor — disabled in favour of auto-enrolment.
require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/includes/EligibilityService.php';
require_once __DIR__ . '/includes/FeeGuard.php';
require_once __DIR__ . '/includes/InvoiceService.php';
require_once __DIR__ . '/includes/period_mode_helper.php';
require_once __DIR__ . '/includes/RegistrationDataService.php';
require_once __DIR__ . '/../includes/payment_helpers.php';

try {
    if (!isset($_SESSION['Sid'])) {
        throw new Exception('Session expired. Please login again.');
    }

    $sid = (string)$_SESSION['Sid'];
    $semester = (int)($_POST['semester'] ?? 0);
    $year = (int)($_POST['Year'] ?? 0);
    $selected = isset($_POST['course_code']) ? (array)$_POST['course_code'] : [];
    $selected = array_values(array_unique(array_filter(array_map(static function ($code): string {
        return strtoupper(trim((string)$code));
    }, $selected))));

    if ($semester === 0 || $year === 0 || empty($selected)) {
        throw new Exception('Missing semester/year or no courses selected.');
    }

    // Program code and structure are resolved from the student's assigned
    // program, never from a posted form field. This prevents a semester/term
    // mismatch when a student's program changes.
    $regDataService = new RegistrationDataService($db);
    // Pick the active, curriculum-backed program for this student. Ordering by
    // newest student_program row alone can attach term courses to an unrelated
    // semester program when a student has multiple assignments.
    $program = $regDataService->getBestStudentProgramCode($sid);
    if ($program === '') {
        throw new Exception('No program found for your account.');
    }
    $programStructure = getStudentProgramPeriodMode($db, $sid);
    $legacyPeriodType = wuc_legacy_period_type($programStructure);
    $periodLabel = getPeriodLabel($db, $sid);

    // Compute failures and flags
    $failed = EligibilityService::getFailedCourses($db, $sid);
    $flags = EligibilityService::computeFailureFlags(count($failed));
    $failedOffered = EligibilityService::filterFailedCoursesOfferedThisTerm($db, $program, $year, $semester, $failed);
    if (!empty($flags['repeat_semester'])) {
        foreach ($failedOffered as $f) {
            $failedCode = strtoupper(trim((string)$f['course_code']));
            if ($failedCode !== '' && !in_array($failedCode, $selected, true)) { $selected[] = $failedCode; }
        }
    }

    $registrationGuard = wuc_legacy_course_registration_guard($db, $sid, $program, $year, $semester, $selected);
    if (!$registrationGuard['ok']) {
        throw new Exception($registrationGuard['reason']);
    }
    $legacyPeriodType = $registrationGuard['period_type'];

    // Validate prerequisites
    $prereq = EligibilityService::validatePrerequisites($db, $sid, $selected);
    if (!$prereq['isValid']) {
        throw new Exception('Prerequisite validation failed: ' . implode('; ', $prereq['errors']));
    }

    // Enforce credit limits
    $totalCredits = EligibilityService::sumCredits($db, $selected);
    $maxCredits = EligibilityService::maxCreditsForYear($year);
    if ($totalCredits > $maxCredits) {
        throw new Exception("Selected credits ($totalCredits) exceed maximum allowed ($maxCredits). Please adjust your selection or seek advisor override.");
    }

    // Find the exact semester_registration row for this submitted period before
    // enforcing fees or writing courses. The previous logic used the latest row
    // regardless of period, which could attach courses and invoices to the wrong
    // intake/term/semester.
    $semRegId = null;
    $srRow = null;
    $srCols = [];
    if ($meta = $db->query("SHOW COLUMNS FROM semester_registration")) {
        while ($c = $meta->fetch_assoc()) { $srCols[strtolower((string)$c['Field'])] = (string)$c['Field']; }
        $meta->free();
    }
    $srSidCol  = $srCols['student_id'] ?? ($srCols['sid'] ?? 'student_id');
    $srSemCol  = $srCols['semester'] ?? 'semester';
    $srYearCol = $srCols['year_of_study'] ?? ($srCols['year'] ?? 'year_of_study');
    $srAYCol = $srCols['academic_year'] ?? null;
    $srPeriodCol = $srCols['period_type'] ?? null;
    $srSelect = "id, `{$srSemCol}` AS semester, `{$srYearCol}` AS year_of_study";
    $srSelect .= $srAYCol ? ", `{$srAYCol}` AS academic_year" : ", NULL AS academic_year";
    $srSql = "SELECT {$srSelect} FROM semester_registration WHERE `{$srSidCol}` = ? AND `{$srSemCol}` = ? AND `{$srYearCol}` = ?";
    $srTypes = 'sss';
    $srParams = [$sid, (string)$semester, (string)$year];
    if ($srPeriodCol) {
        $srSql .= " AND `{$srPeriodCol}` = ?";
        $srTypes .= 's';
        $srParams[] = $legacyPeriodType;
    }
    $srSql .= " ORDER BY id DESC LIMIT 1";
    if ($stmt = $db->prepare($srSql)) {
        $stmt->bind_param($srTypes, ...$srParams);
        $stmt->execute();
        if ($srRow = $stmt->get_result()->fetch_assoc()) {
            $semRegId = (int)$srRow['id'];
        }
        $stmt->close();
    }
    if (!$semRegId) {
        throw new Exception("No {$periodLabel} registration found for Year {$year}, {$periodLabel} {$semester}. Please complete {$periodLabel} registration first.");
    }

    // Accounts clearance: optional registration payment gate (configurable, default
    // 50%). Fails open when no fee structure is configured. courseReg.php and
    // RegistrationDataService intentionally do not lock course selection on fees;
    // when the student is below the threshold we only enter pending_payment when
    // they explicitly submit via the DPO checkout path. Otherwise registration
    // proceeds and finance rules apply later (CA marks, exams).
    $regGate = payment_registration_gate_settings($db);
    $pendingPaymentMode = false;
    if ($regGate['enabled']) {
        $fgRes = fg_check_fee_threshold(
            $db,
            $sid,
            (int)$year,
            (int)$semester,
            (float)$regGate['threshold_pct'],
            isset($srRow['academic_year']) ? (string)$srRow['academic_year'] : null
        );
        if (!$fgRes['ok']) {
            $payOnlineRequested = !empty($_POST['pay_online']);
            $dpoConfig = payment_get_dpo_config($db);
            if ($payOnlineRequested
                && payment_dpo_is_ready($dpoConfig)
                && payment_ensure_gateway_transactions_table($db)) {
                $pendingPaymentMode = true;
            }
        }
    }

    // Discover course_registration column names
    $crCols = [];
    if ($m = $db->query('SHOW COLUMNS FROM course_registration')) {
        while ($c = $m->fetch_assoc()) { $crCols[strtolower((string)$c['Field'])] = (string)$c['Field']; }
        $m->free();
    }
    $crSidCol  = $crCols['sid'] ?? ($crCols['student_id'] ?? 'Sid');
    $crSemCol  = $crCols['semester'] ?? ($crCols['semester_term'] ?? 'semester');
    $crYearCol = $crCols['year'] ?? 'Year'; // year-of-study (1..4), NOT calendar year
    $crAcadYearCol = $crCols['academic_year'] ?? null; // calendar year column (YYYY)
    $crIdCol   = $crCols['coregid'] ?? null; // optional CoRegID
    $crPkCol   = $crCols['id'] ?? null;
    $crActiveCol = $crCols['is_active'] ?? null;
    $crUpdatedCol = $crCols['updated_at'] ?? null;

    // Calendar year for this registration (from semester_registration, not year-of-study).
    $calendarYear = null;
    if ($crAcadYearCol) {
        $ayRaw = isset($srRow['academic_year']) ? (string)$srRow['academic_year'] : '';
        $calendarYear = preg_match('/^\d{4}$/', $ayRaw) ? (int)$ayRaw : (int)date('Y');
    }

    $Sid = $db->real_escape_string($sid);
    $semesterEsc = $db->real_escape_string((string)$semester);
    $yearEsc = $db->real_escape_string((string)$year);

    // Duplicate-term guard: prepared
    $dupActiveSql = "SELECT COUNT(*) AS cnt FROM course_registration WHERE `{$crSidCol}` = ? AND `{$crSemCol}` = ? AND `{$crYearCol}` = ?";
    if ($crActiveCol) {
        $dupActiveSql .= " AND COALESCE(`{$crActiveCol}`, 1) = 1";
    }
    $dupStmt = $db->prepare($dupActiveSql);
    if ($dupStmt) {
        $semStr = (string)$semester;
        $yrStr = (string)$year;
        $dupStmt->bind_param('sss', $sid, $semStr, $yrStr);
        $dupStmt->execute();
        $dupRow = $dupStmt->get_result()->fetch_assoc();
        $dupStmt->close();
        if ((int)($dupRow['cnt'] ?? 0) > 0) {
            throw new Exception('You have already registered courses for Year ' . $year . ', ' . $periodLabel . ' ' . $semester . '. To modify your registration, please contact the academic office.');
        }
    }

    // Build prepared INSERT shape once, based on columns present in this schema.
    $insertCols = ["`{$crSidCol}`", "course_code", "`{$crSemCol}`", "`{$crYearCol}`"];
    $insertTypes = 'ssss';
    // Write calendar year into the dedicated column when it exists.
    if ($crAcadYearCol && $calendarYear !== null) {
        $insertCols[] = "`{$crAcadYearCol}`";
        $insertTypes .= 'i';
    }
    $hasSemRegFK = isset($crCols['semester_registration_id']);
    if ($hasSemRegFK) { $insertCols[] = 'semester_registration_id'; $insertTypes .= 'i'; }
    if ($crIdCol)     { $insertCols[] = "`{$crIdCol}`";              $insertTypes .= 's'; }
    $crStatusCol = $crCols['status'] ?? null;
    if ($pendingPaymentMode && !$crStatusCol) {
        // Cannot hold a registration without a status column — proceed normally.
        error_log('processCourseReg: pending_payment unavailable (no status column); proceeding with normal registration.');
        $pendingPaymentMode = false;
    }
    if ($pendingPaymentMode) {
        $insertCols[] = "`{$crStatusCol}`";
        $insertTypes .= 's';
        if ($crActiveCol) { $insertCols[] = "`{$crActiveCol}`"; $insertTypes .= 'i'; }
    }
    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $insertSql = "INSERT INTO course_registration (" . implode(', ', $insertCols) . ") VALUES ({$placeholders})";

    $existsSelect = $crPkCol ? "`{$crPkCol}` AS row_id" : "NULL AS row_id";
    $existsSelect .= $crActiveCol ? ", COALESCE(`{$crActiveCol}`, 1) AS is_active" : ", 1 AS is_active";
    $existsStmt = $db->prepare("SELECT {$existsSelect} FROM course_registration WHERE `{$crSidCol}` = ? AND course_code = ? AND `{$crSemCol}` = ? AND `{$crYearCol}` = ? LIMIT 1");
    $insertStmt = $db->prepare($insertSql);
    if (!$existsStmt || !$insertStmt) {
        throw new Exception('Failed to prepare course registration statements: ' . $db->error);
    }

    $reactivateSql = null;
    if ($crActiveCol) {
        $setParts = ["`{$crActiveCol}` = 1"];
        if ($crStatusCol) {
            // A row held for online payment that is later cleared through the
            // normal path (e.g. bank transfer) must not stay pending_payment.
            $setParts[] = "`{$crStatusCol}` = CASE WHEN `{$crStatusCol}` = 'pending_payment' THEN 'registered' ELSE `{$crStatusCol}` END";
        }
        if ($hasSemRegFK) {
            $setParts[] = "semester_registration_id = ?";
        }
        if ($crUpdatedCol) {
            $setParts[] = "`{$crUpdatedCol}` = NOW()";
        }
        $reactivateWhere = $crPkCol
            ? "`{$crPkCol}` = ?"
            : "`{$crSidCol}` = ? AND course_code = ? AND `{$crSemCol}` = ? AND `{$crYearCol}` = ?";
        $reactivateSql = "UPDATE course_registration SET " . implode(', ', $setParts) . " WHERE {$reactivateWhere}";
    }

    $hasLegacyMirror = false;
    if ($legacyChk = $db->query("SHOW TABLES LIKE 'student_courses'")) {
        $hasLegacyMirror = $legacyChk->num_rows > 0;
        $legacyChk->free();
    }
    $legacyInsStmt = null;
    if ($hasLegacyMirror) {
        $legacyInsStmt = $db->prepare("INSERT IGNORE INTO student_courses (student_id, course_code, academic_year, semester) VALUES (?, ?, ?, ?)");
    }

    $db->begin_transaction();
    try {
        $semStr = (string)$semester;
        $yrStr = (string)$year;
        foreach ($selected as $code) {
            $code = (string)$code;
            $existsStmt->bind_param('ssss', $sid, $code, $semStr, $yrStr);
            $existsStmt->execute();
            $existingRow = $existsStmt->get_result()->fetch_assoc();
            if ($existingRow) {
                if ((int)($existingRow['is_active'] ?? 1) === 1) {
                    $sync = wuc_sync_legacy_course_registration_to_canonical($db, $sid, $program, $code, $year, $semester);
                    if (!$sync['ok']) {
                        throw new Exception('Could not sync canonical registration for ' . $code . ': ' . $sync['reason']);
                    }
                    continue;
                }
                if ($pendingPaymentMode) {
                    // Retry path: re-use the existing inactive row as the pending
                    // obligation instead of activating it before payment.
                    $pendingSets = ["`{$crStatusCol}` = 'pending_payment'"];
                    if ($crActiveCol) { $pendingSets[] = "`{$crActiveCol}` = 0"; }
                    if ($hasSemRegFK) { $pendingSets[] = 'semester_registration_id = ' . (int)$semRegId; }
                    if ($crUpdatedCol) { $pendingSets[] = "`{$crUpdatedCol}` = NOW()"; }
                    $pendingWhere = $crPkCol
                        ? "`{$crPkCol}` = ?"
                        : "`{$crSidCol}` = ? AND course_code = ? AND `{$crSemCol}` = ? AND `{$crYearCol}` = ?";
                    $pendingStmt = $db->prepare("UPDATE course_registration SET " . implode(', ', $pendingSets) . " WHERE {$pendingWhere}");
                    if (!$pendingStmt) {
                        throw new Exception('Failed to prepare pending registration update: ' . $db->error);
                    }
                    if ($crPkCol) {
                        $rowId = (int)$existingRow['row_id'];
                        $pendingStmt->bind_param('i', $rowId);
                    } else {
                        $pendingStmt->bind_param('ssss', $sid, $code, $semStr, $yrStr);
                    }
                    if (!$pendingStmt->execute()) {
                        $error = $pendingStmt->error;
                        $pendingStmt->close();
                        throw new Exception('Failed to hold course ' . $code . ' for payment: ' . $error);
                    }
                    $pendingStmt->close();
                    continue;
                }
                if ($reactivateSql) {
                    $reactivateStmt = $db->prepare($reactivateSql);
                    if (!$reactivateStmt) {
                        throw new Exception('Failed to prepare course reactivation statement: ' . $db->error);
                    }
                    if ($crPkCol) {
                        if ($hasSemRegFK) {
                            $rowId = (int)$existingRow['row_id'];
                            $reactivateStmt->bind_param('ii', $semRegId, $rowId);
                        } else {
                            $rowId = (int)$existingRow['row_id'];
                            $reactivateStmt->bind_param('i', $rowId);
                        }
                    } else {
                        if ($hasSemRegFK) {
                            $reactivateStmt->bind_param('issss', $semRegId, $sid, $code, $semStr, $yrStr);
                        } else {
                            $reactivateStmt->bind_param('ssss', $sid, $code, $semStr, $yrStr);
                        }
                    }
                    if (!$reactivateStmt->execute()) {
                        $error = $reactivateStmt->error;
                        $reactivateStmt->close();
                        throw new Exception('Failed to reactivate course ' . $code . ': ' . $error);
                    }
                    $reactivateStmt->close();
                    $sync = wuc_sync_legacy_course_registration_to_canonical($db, $sid, $program, $code, $year, $semester);
                    if (!$sync['ok']) {
                        throw new Exception('Could not sync canonical registration for ' . $code . ': ' . $sync['reason']);
                    }
                    continue;
                }
            }

            $params = [$sid, $code, $semStr, $yrStr];
            if ($crAcadYearCol && $calendarYear !== null) { $params[] = $calendarYear; }
            if ($hasSemRegFK) { $params[] = $semRegId; }
            if ($crIdCol)     { $params[] = uniqid('CR', true); }
            if ($pendingPaymentMode) {
                $params[] = 'pending_payment';
                if ($crActiveCol) { $params[] = 0; }
            }
            $insertStmt->bind_param($insertTypes, ...$params);
            if (!$insertStmt->execute()) {
                throw new Exception('Failed to register course ' . $code . ': ' . $insertStmt->error);
            }
            if (!$pendingPaymentMode) {
                // Canonical/legacy mirrors only reflect ACTIVE registrations;
                // pending_payment rows are mirrored on activation after the
                // verified payment (students/payments/paygate_return.php).
                $sync = wuc_sync_legacy_course_registration_to_canonical($db, $sid, $program, $code, $year, $semester);
                if (!$sync['ok']) {
                    throw new Exception('Could not sync canonical registration for ' . $code . ': ' . $sync['reason']);
                }

                if ($legacyInsStmt) {
                    $legacyAY = $calendarYear !== null ? (string)$calendarYear : $yrStr;
                    $legacyInsStmt->bind_param('ssss', $sid, $code, $legacyAY, $semStr);
                    @$legacyInsStmt->execute();
                }
            }
        }
        $existsStmt->close();
        $insertStmt->close();
        if ($legacyInsStmt) { $legacyInsStmt->close(); }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        @$existsStmt->close();
        @$insertStmt->close();
        if ($legacyInsStmt) { @$legacyInsStmt->close(); }
        throw $e;
    }

    // ---- POST-COMMIT REGION ---------------------------------------------------
    // Courses are persisted at this point. Do not let invoice/audit failures
    // surface as "Registration failed" to the user — surface them as warnings
    // instead and let the success flow continue.
    $postCommitWarning = null;
    $invoiceNumber = null;
    try {
        EligibilityService::audit($db, $sid, $pendingPaymentMode ? 'course_registration_pending_payment' : 'course_registration_submit', [
            'semester' => $semester,
            'year' => $year,
            'selected_courses' => array_values($selected),
            'repeat_semester' => !empty($flags['repeat_semester']),
            'auto_append_failed' => !empty($flags['auto_append_failed']),
            'total_credits' => $totalCredits,
            'max_credits' => $maxCredits,
            'pending_payment' => $pendingPaymentMode
        ]);
    } catch (Throwable $e) {
        error_log('processCourseReg audit failed: ' . $e->getMessage());
    }

    // Re-tally fees from configured finance rows first, then fall back to course credits.
    $fees = 0;
    $billedCredits = 0;
    try {
        $feeWhere = "program_code = ? AND year_of_study = ? AND semester = ?";
        if (fg_column_exists($db, 'fee_structure', 'entity_type')) {
            $feeWhere .= " AND entity_type = 'program'";
        }
        if (fg_column_exists($db, 'fee_structure', 'status')) {
            $feeWhere .= " AND status = 'active'";
        }
        $feeStmt = $db->prepare("SELECT SUM(amount) AS total FROM fee_structure WHERE {$feeWhere}");
        if ($feeStmt) {
            $feeStmt->bind_param('sii', $program, $year, $semester);
            $feeStmt->execute();
            $feeRow = $feeStmt->get_result()->fetch_assoc();
            $fees = (float)($feeRow['total'] ?? 0);
            $feeStmt->close();
        }

        $ph = implode(',', array_fill(0, count($selected), '?'));
        $stmt = $db->prepare("SELECT course_code, credits FROM courses WHERE course_code IN ($ph)");
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($selected)), ...array_values($selected));
            $stmt->execute();
            $result = $stmt->get_result();
            while ($cr = $result->fetch_assoc()) {
                $credit = (int)($cr['credits'] ?: 3);
                $billedCredits += $credit;
                if ($fees <= 0) {
                    $fees += ($credit * 350);
                }
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('processCourseReg fee tally failed: ' . $e->getMessage());
    }
    if ($fees <= 0) {
        $fees += 150; // Standard registration fee fallback
    }

    // Resolve academic year for the invoice (use the semester_registration row
    // we looked up earlier; never re-use a variable named $row from a later query).
    $acYearForInv = $srRow['academic_year'] ?? date('Y');

    try {
        $invoiceService = new InvoiceService($db);
        $invoiceNumber = $invoiceService->generateInvoice($sid, $semRegId, $fees, $acYearForInv, (string)$semester);
    } catch (Throwable $e) {
        error_log('InvoiceService failed: ' . $e->getMessage());
        $invoiceNumber = 'CR' . date('Ymd') . rand(1000, 9999);
        $postCommitWarning = 'Courses saved, but invoice generation fell back to a temporary number. Please verify with accounts.';
    }

    $_SESSION['successMessage'] = $pendingPaymentMode
        ? 'Your course selection has been saved. Complete the payment to activate your registration.'
        : ($postCommitWarning ?? 'Courses have been successfully registered.');
    $_SESSION['last_course_reg'] = [
        'Sid' => $sid,
        'semester' => $semester,
        'Year' => $year,
        'courses' => array_values($selected),
        'credits' => $billedCredits,
        'fees' => $fees,
        'invoice' => $invoiceNumber,
        'pending_payment' => $pendingPaymentMode,
        'ts' => time()
    ];

    // Optional legacy ledger — gated on table existence to avoid STRICT-mode throws.
    try {
        $hasLegacyLedger = false;
        if ($tbl = $db->query("SHOW TABLES LIKE 'student_payments'")) {
            $hasLegacyLedger = $tbl->num_rows > 0;
            $tbl->free();
        }
        if ($hasLegacyLedger) {
            // student_payments is a legacy table whose column names vary by install.
            $spCols = [];
            if ($spMeta = $db->query("SHOW COLUMNS FROM student_payments")) {
                while ($spCol = $spMeta->fetch_assoc()) {
                    $spCols[strtolower((string)$spCol['Field'])] = (string)$spCol['Field'];
                }
                $spMeta->free();
            }
            $spSidCol = $spCols['sid'] ?? ($spCols['student_id'] ?? null);
            $spYearCol = $spCols['year'] ?? ($spCols['year_of_study'] ?? ($spCols['academic_year'] ?? null));
            $spSemCol = $spCols['semester'] ?? ($spCols['semester_term'] ?? null);
            $spPkCol = $spCols['payment_id'] ?? ($spCols['id'] ?? null);
            if ($spSidCol && $spYearCol && $spSemCol) {
                $pkSelect = $spPkCol ? "`{$spPkCol}`" : "`{$spSidCol}`";
                $checkSql = "SELECT {$pkSelect} FROM student_payments WHERE `{$spSidCol}` = ? AND `{$spYearCol}` = ? AND `{$spSemCol}` = ? LIMIT 1";
                $st = $db->prepare($checkSql);
            } else {
                $st = false;
            }
            if ($st) {
                $semStr = (string)$semester;
                $yrStr = (string)$year;
                $st->bind_param('sss', $sid, $yrStr, $semStr);
                $st->execute();
                $st->store_result();
                $exists = $st->num_rows > 0;
                $st->close();
                if (!$exists) {
                    $ledgerCols = [];
                    $ledgerPlaceholders = [];
                    $ledgerTypes = '';
                    $ledgerParams = [];
                    $addLedgerValue = static function (string $column, string $placeholder, string $type, $value) use (&$ledgerCols, &$ledgerPlaceholders, &$ledgerTypes, &$ledgerParams): void {
                        $ledgerCols[] = "`{$column}`";
                        $ledgerPlaceholders[] = $placeholder;
                        if ($type !== '') {
                            $ledgerTypes .= $type;
                            $ledgerParams[] = $value;
                        }
                    };
                    $narration = "Course Registration Fee - $billedCredits credits";
                    $addLedgerValue($spSidCol, '?', 's', $sid);
                    if (isset($spCols['balance'])) { $addLedgerValue($spCols['balance'], '?', 'd', $fees); }
                    if (isset($spCols['invoice'])) { $addLedgerValue($spCols['invoice'], '?', 's', $invoiceNumber); }
                    elseif (isset($spCols['reference_number'])) { $addLedgerValue($spCols['reference_number'], '?', 's', $invoiceNumber); }
                    $addLedgerValue($spSemCol, '?', 's', $semStr);
                    if (isset($spCols['narration'])) { $addLedgerValue($spCols['narration'], '?', 's', $narration); }
                    elseif (isset($spCols['description'])) { $addLedgerValue($spCols['description'], '?', 's', $narration); }
                    $addLedgerValue($spYearCol, '?', 's', $yrStr);
                    if (isset($spCols['dte_time'])) { $addLedgerValue($spCols['dte_time'], 'NOW()', '', null); }
                    if (isset($spCols['amount_paid'])) { $addLedgerValue($spCols['amount_paid'], '?', 'd', 0.0); }
                    if (isset($spCols['payment_status'])) { $addLedgerValue($spCols['payment_status'], '?', 's', 'pending'); }
                    elseif (isset($spCols['status'])) { $addLedgerValue($spCols['status'], '?', 's', 'pending'); }

                    $insertLedger = $db->prepare(
                        "INSERT INTO student_payments (" . implode(', ', $ledgerCols) . ") VALUES (" . implode(', ', $ledgerPlaceholders) . ")"
                    );
                    if ($insertLedger) {
                        if ($ledgerTypes !== '') {
                            $insertLedger->bind_param($ledgerTypes, ...$ledgerParams);
                        }
                        $insertLedger->execute();
                        $insertLedger->close();
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Legacy ledger sync skipped: ' . $e->getMessage());
    }

    if ($pendingPaymentMode) {
        // Hand over to the hosted checkout. paygate_start.php recomputes the
        // amount server-side from the pending rows — nothing here is trusted.
        $csrfForPay = htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');
        $semAttr = htmlspecialchars((string)$semester, ENT_QUOTES, 'UTF-8');
        $yearAttr = htmlspecialchars((string)$year, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Redirecting to payment…</title>'
            . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"></head>'
            . '<body class="bg-light"><div class="container py-5 text-center">'
            . '<div class="spinner-border text-primary mb-3" role="status"></div>'
            . '<h5>Your course selection is saved.</h5>'
            . '<p class="text-muted">Taking you to the secure payment page to complete your registration…</p>'
            . '<form id="payForm" method="post" action="payments/paygate_start.php">'
            . '<input type="hidden" name="csrf_token" value="' . $csrfForPay . '">'
            . '<input type="hidden" name="payment_type" value="course_registration">'
            . '<input type="hidden" name="semester" value="' . $semAttr . '">'
            . '<input type="hidden" name="Year" value="' . $yearAttr . '">'
            . '<button type="submit" class="btn btn-primary">Continue to payment</button>'
            . '</form>'
            . '<script>document.getElementById("payForm").submit();</script>'
            . '</div></body></html>';
        exit;
    }

    $_SESSION['show_course_invoice'] = true;
    header('Location: fees.php?invoice=' . urlencode((string)$invoiceNumber));
    exit;
} catch (Throwable $e) {
    // Rollback any open transaction - avoid inTransaction() for compatibility with older PHP
    try {
        @$db->rollback();
    } catch (Throwable $ignore) {}
    
    // Log detailed error info
    error_log('processCourseReg CRITICAL ERROR for SID=' . ($_SESSION['Sid'] ?? 'unknown') . ' => ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    // Check if session is still valid
    if (!isset($_SESSION['Sid'])) {
        // Session expired during processing - provide a more helpful error
        error_log('Session expired during registration process - handling gracefully');
        
        // Start a new session if needed
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Store error for display
        $_SESSION['processCourseRegFatal'] = [
            'error' => 'Your session expired during registration. Please try again.',
            'timestamp' => time()
        ];
        
        // Redirect to login with return path
        if (!headers_sent()) {
            header('Location: studentLogout.php?expired=1&return=' . urlencode('courseReg.php'));
        } else {
            echo "<script>window.location.href='studentLogout.php?expired=1&return=" . urlencode('courseReg.php') . "';</script>";
        }
        exit;
    }
    
    // Save error message for display
    $_SESSION['failedMessage'] = 'Registration failed: ' . $e->getMessage();
    
    // Generate a unique error reference number for tracking
    $errorRef = 'ERR-' . date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
    error_log("Error reference number: {$errorRef}");
    $_SESSION['failedMessage'] .= " (Ref: {$errorRef})";
    
    // Provide JSON response for AJAX calls
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'error' => $_SESSION['failedMessage'],
            'errorRef' => $errorRef
        ]);
        exit;
    }
    
    // Redirect for standard form submission
    if (!headers_sent()) {
        header('Location: courseReg.php?error=' . $errorRef);
    } else {
        echo "<script>window.location.href='courseReg.php?error=" . $errorRef . "';</script>";
    }
    exit;
}

// Legacy content removed to prevent duplicate handlers/headers
