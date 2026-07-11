<?php
/**
 * Live enrol preview (progressive enhancement for the Trainee Enrolment form).
 * Given a cohort + applicant + study mode, returns eligibility (§7) + a fee quote
 * (§8) + the optional charges available, as JSON. The authoritative gate still
 * lives server-side in transport_management.php add_trainee.
 */
require_once __DIR__ . '/includes/transport.php';            // auth + $db + canAccessTransport gate
require_once __DIR__ . '/includes/transport_eligibility.php';
require_once __DIR__ . '/includes/transport_fees.php';

header('Content-Type: application/json; charset=utf-8');

$fail = static function (string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail('POST required.', 405);
}
$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['transport_csrf']) || !hash_equals((string)$_SESSION['transport_csrf'], $csrf)) {
    $fail('Security token mismatch.', 403);
}

$cohortId  = (int)($_POST['cohort_id'] ?? 0);
$studentId = trim((string)($_POST['student_id'] ?? ''));
$mode      = trim((string)($_POST['training_mode'] ?? ''));
$optionIds = array_map('intval', (array)($_POST['fee_options'] ?? []));

// Resolve the program behind the cohort.
$programId = 0;
if ($cohortId > 0) {
    $stmt = $db->prepare("SELECT program_id FROM transport_cohorts WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $cohortId);
    $stmt->execute();
    $programId = (int)($stmt->get_result()->fetch_assoc()['program_id'] ?? 0);
    $stmt->close();
}

$response = ['eligibility' => null, 'fee_quote' => null, 'fee_options' => []];

if ($programId <= 0) {
    echo json_encode($response);
    exit;
}

// ── Eligibility (§7) ────────────────────────────────────────────────────────
$program = te_program_requirements($db, $programId);
if ($program) {
    $studentRow = [];
    if ($studentId !== '') {
        $sStmt = $db->prepare("SELECT dob, nrc_pass FROM students WHERE SID = ? LIMIT 1");
        $sStmt->bind_param('s', $studentId);
        $sStmt->execute();
        $studentRow = $sStmt->get_result()->fetch_assoc() ?: [];
        $sStmt->close();
    }
    $applicant = te_applicant_from_student($studentRow, $_POST);
    $elig = te_check_eligibility($applicant, $program);
    $response['eligibility'] = [
        'eligible' => $elig['eligible'],
        'reasons'  => $elig['reasons'],
        'warnings' => $elig['warnings'],
    ];
}

// ── Fee quote (§8) ──────────────────────────────────────────────────────────
$response['fee_quote'] = tf_calculate($db, $programId, $mode, $optionIds);

// Optional, applicant-selectable charges for this program.
foreach (tf_additional_fees($db, $programId) as $af) {
    if ((int)$af['is_mandatory'] === 0) {
        $response['fee_options'][] = [
            'id'       => (int)$af['id'],
            'fee_name' => (string)$af['fee_name'],
            'amount'   => (float)$af['amount'],
        ];
    }
}

echo json_encode($response);
