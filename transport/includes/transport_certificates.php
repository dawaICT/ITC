<?php
/**
 * Transport certificate gate (spec §15 / BR015).
 *
 * A certificate may be issued ONLY when:
 *   1. training is complete  (enrolment.status = 'completed' OR a passed assessment on record), AND
 *   2. payment is verified   (enrolment.payment_status = 'verified')  -- ties §15 to BR001.
 *
 * Issuing records a row in `transport_certificates` with a unique certificate
 * number and flips transport_enrollments.certificate_issued. Pure functions
 * taking a `mysqli $db`; the caller (certificate.php / a UI action) owns output.
 */

function tc_actor(): string
{
    return (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'system');
}

function tc_audit(mysqli $db, int $enrollmentId, string $action, ?string $notes = null): void
{
    $actor = tc_actor();
    $stmt = $db->prepare(
        "INSERT INTO transport_audit_log (entity, entity_id, action, actor, notes) VALUES ('transport_enrollment', ?, ?, ?, ?)"
    );
    if (!$stmt) {
        error_log('tc_audit: failed to record "' . $action . '" for enrollment ' . $enrollmentId . ': ' . $db->error);
        return;
    }
    $stmt->bind_param('isss', $enrollmentId, $action, $actor, $notes);
    if (!$stmt->execute()) {
        error_log('tc_audit: failed to record "' . $action . '" for enrollment ' . $enrollmentId . ': ' . $stmt->error);
    }
    $stmt->close();
}

/**
 * Completion picture for an enrolment (§14 inputs).
 * @return array{status:string,status_completed:bool,passed_count:int,failed_count:int,completion_met:bool}
 */
function tc_completion_state(mysqli $db, int $enrollmentId): array
{
    $stmt = $db->prepare(
        "SELECT e.status,
                (SELECT COUNT(*) FROM transport_assessments a WHERE a.enrollment_id = e.id AND a.result = 'pass') AS passed_count,
                (SELECT COUNT(*) FROM transport_assessments a WHERE a.enrollment_id = e.id AND a.result = 'fail') AS failed_count
         FROM transport_enrollments e WHERE e.id = ? LIMIT 1"
    );
    $passed = 0;
    $failed = 0;
    $status = '';
    if ($stmt) {
        $stmt->bind_param('i', $enrollmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $status = (string)$row['status'];
            $passed = (int)$row['passed_count'];
            $failed = (int)$row['failed_count'];
        }
    }
    $statusCompleted = ($status === 'completed');
    return [
        'status'           => $status,
        'status_completed' => $statusCompleted,
        'passed_count'     => $passed,
        'failed_count'     => $failed,
        'completion_met'   => $statusCompleted || $passed >= 1,
    ];
}

/**
 * §15 gate. Can a certificate be issued for this enrolment?
 * @return array{allowed:bool,reason:string,payment_status:string,completion:array}
 */
function tc_can_issue(mysqli $db, int $enrollmentId): array
{
    $stmt = $db->prepare("SELECT id, payment_status, status FROM transport_enrollments WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['allowed' => false, 'reason' => 'Unable to load the enrolment.', 'payment_status' => '', 'completion' => []];
    }
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $enr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$enr) {
        return ['allowed' => false, 'reason' => 'Enrolment not found.', 'payment_status' => '', 'completion' => []];
    }

    $completion = tc_completion_state($db, $enrollmentId);
    $paymentStatus = (string)$enr['payment_status'];

    // §15.1: training must be complete.
    if (!$completion['completion_met']) {
        return [
            'allowed'        => false,
            'reason'         => 'Certificate cannot be generated before training completion (no passed assessment and the enrolment is not marked completed).',
            'payment_status' => $paymentStatus,
            'completion'     => $completion,
        ];
    }
    // §15.2 / BR015: payment must be verified.
    if ($paymentStatus !== 'verified') {
        return [
            'allowed'        => false,
            'reason'         => 'Certificate blocked because payment is not verified (BR015). Verify payment on the Payments & Booking screen first.',
            'payment_status' => $paymentStatus,
            'completion'     => $completion,
        ];
    }

    return ['allowed' => true, 'reason' => 'Eligible for certification.', 'payment_status' => $paymentStatus, 'completion' => $completion];
}

/** Deterministic, unique certificate number for an enrolment. */
function tc_certificate_number(int $enrollmentId, ?string $completionDate = null): string
{
    $year = $completionDate ? (int)date('Y', strtotime($completionDate)) : (int)date('Y');
    return sprintf('ITC/TR/%d/%05d', $year, $enrollmentId);
}

/** Return an existing certificate row for an enrolment, or null. */
function tc_existing_certificate(mysqli $db, int $enrollmentId): ?array
{
    $stmt = $db->prepare("SELECT * FROM transport_certificates WHERE enrollment_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Issue (or return the already-issued) certificate for an enrolment.
 * Enforces tc_can_issue(); idempotent on enrollment_id.
 * @return array{certificate_number:string,reused:bool,message:string}
 * @throws RuntimeException when the §15 gate is not satisfied.
 */
function tc_issue(mysqli $db, int $enrollmentId): array
{
    $existing = tc_existing_certificate($db, $enrollmentId);
    if ($existing) {
        return ['certificate_number' => (string)$existing['certificate_number'], 'reused' => true, 'message' => 'Certificate already issued.'];
    }

    $gate = tc_can_issue($db, $enrollmentId);
    if (!$gate['allowed']) {
        throw new RuntimeException($gate['reason']);
    }

    // Prefer the cohort end date as the completion date, else today.
    $stmt = $db->prepare(
        "SELECT c.end_date FROM transport_enrollments e JOIN transport_cohorts c ON c.id = e.cohort_id WHERE e.id = ? LIMIT 1"
    );
    $completionDate = date('Y-m-d');
    if ($stmt) {
        $stmt->bind_param('i', $enrollmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && !empty($row['end_date']) && $row['end_date'] !== '0000-00-00') {
            $completionDate = (string)$row['end_date'];
        }
    }

    $certNo = tc_certificate_number($enrollmentId, $completionDate);
    $actor  = tc_actor();
    $issueDate = date('Y-m-d');

    $ins = $db->prepare(
        "INSERT INTO transport_certificates
            (enrollment_id, certificate_number, completion_date, approved_by, issue_date, status)
         VALUES (?,?,?,?,?, 'issued')"
    );
    if (!$ins) {
        throw new RuntimeException('Unable to record the certificate.');
    }
    $ins->bind_param('issss', $enrollmentId, $certNo, $completionDate, $actor, $issueDate);
    try {
        $ins->execute();
    } catch (mysqli_sql_exception $e) {
        $ins->close();
        // Lost a race: another request inserted it first -> return that one.
        $existing = tc_existing_certificate($db, $enrollmentId);
        if ($existing) {
            return ['certificate_number' => (string)$existing['certificate_number'], 'reused' => true, 'message' => 'Certificate already issued.'];
        }
        throw new RuntimeException('Unable to record the certificate: ' . $e->getMessage());
    }
    $ins->close();

    $upd = $db->prepare("UPDATE transport_enrollments SET certificate_issued = 1 WHERE id = ?");
    if ($upd) {
        $upd->bind_param('i', $enrollmentId);
        $upd->execute();
        $upd->close();
    }

    tc_audit($db, $enrollmentId, 'certificate_issued', 'Certificate ' . $certNo);

    return ['certificate_number' => $certNo, 'reused' => false, 'message' => 'Certificate issued.'];
}
