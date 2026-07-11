<?php
declare(strict_types=1);
require __DIR__ . '/includes/nav.php';
require_once dirname(__DIR__) . '/includes/auth_helpers.php';
require_once dirname(__DIR__) . '/includes/grading_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !wuc_validate_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request.');
}

$id = filter_input(INPUT_POST, 'assessment_id', FILTER_VALIDATE_INT);
$action = strtolower(trim((string)($_POST['action'] ?? 'approve')));
$reason = trim((string)($_POST['reason'] ?? ''));

if (!$id || $id < 1) {
    $_SESSION['errorMsg'] = 'Invalid assessment reference.';
    header('Location: CAmanager.php', true, 303);
    exit;
}

// Each workflow action maps to the status it moves the result into.
$targetByAction = ['approve' => 'Approved', 'reject' => 'Rejected', 'publish' => 'Published', 'moderate' => 'moderate'];
if (!isset($targetByAction[$action])) {
    $_SESSION['errorMsg'] = 'Unknown action.';
    header('Location: CAmanager.php', true, 303);
    exit;
}
$target = $targetByAction[$action];
$actor = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'unknown');

try {
    // Load the current row so we can validate the transition and record an audit
    // entry that captures the before/after status.
    $stmt = $db->prepare('SELECT Sid, Course_Code, semester, Year, status FROM semester_assessment WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $_SESSION['errorMsg'] = 'Assessment was not found.';
        header('Location: CAmanager.php', true, 303);
        exit;
    }

    // Section scope: a HOS may only decide on assessments for courses that
    // belong to their own section (unless they are a systems admin).
    require_once __DIR__ . '/includes/hod_schema_helpers.php';
    if (!hos_can_switch_sections($db, $actor)) {
        $deptContext = hod_resolve_department($db, $actor);
        $sectionCourses = hod_section_course_codes($db, $deptContext, $actor);
        if (!in_array((string)$row['Course_Code'], $sectionCourses, true)) {
            require_once dirname(__DIR__) . '/includes/audit.php';
            if (function_exists('audit_log_current_user')) {
                audit_log_current_user($db, 'security.hos_ca_decision_denied', [
                    'assessment_id' => $id,
                    'course_code' => (string)$row['Course_Code'],
                ]);
            }
            $_SESSION['errorMsg'] = 'That assessment belongs to a course outside your section.';
            header('Location: CAmanager.php', true, 303);
            exit;
        }
    }

    $current = (string)$row['status'];
    if ($action !== 'moderate') {
        if (!wuc_result_can_transition($current, $target)) {
            $_SESSION['errorMsg'] = 'Cannot ' . htmlspecialchars($action) . ' a result that is "'
                . htmlspecialchars(wuc_result_status_label($current)) . '".';
            header('Location: CAmanager.php', true, 303);
            exit;
        }
        if ($action === 'reject' && $reason === '') {
            $_SESSION['errorMsg'] = 'A reason is required when returning a result for correction.';
            header('Location: CAmanager.php', true, 303);
            exit;
        }
    }

    if ($action === 'approve') {
        $stmt = $db->prepare('UPDATE semester_assessment
            SET status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = NULL
            WHERE id = ?');
        $stmt->bind_param('ssi', $target, $actor, $id);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'reject') {
        $stmt = $db->prepare('UPDATE semester_assessment
            SET status = ?, rejection_reason = ?
            WHERE id = ?');
        $stmt->bind_param('ssi', $target, $reason, $id);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'publish') {
        $stmt = $db->prepare('UPDATE semester_assessment
            SET status = ?, published_by = ?, published_at = NOW()
            WHERE id = ?');
        $stmt->bind_param('ssi', $target, $actor, $id);
        $stmt->execute();
        $stmt->close();
    } else { // moderate
        $internal_status = trim((string)($_POST['internal_status'] ?? 'pending'));
        $internal_notes = trim((string)($_POST['internal_notes'] ?? ''));
        $external_status = trim((string)($_POST['external_status'] ?? 'pending'));
        $external_notes = trim((string)($_POST['external_notes'] ?? ''));
        $external_moderator = trim((string)($_POST['external_moderator'] ?? ''));
        
        $mainStatusUpdate = '';
        if ($internal_status === 'approved') {
            $mainStatusUpdate = ", status = 'Approved', approved_by = ?, approved_at = NOW(), rejection_reason = NULL";
        } elseif ($internal_status === 'rejected') {
            $mainStatusUpdate = ", status = 'Rejected', rejection_reason = ?";
        }
        
        $sql = "UPDATE semester_assessment
                SET internal_moderation_status = ?,
                    internal_moderator_id = ?,
                    internal_moderated_at = NOW(),
                    internal_moderation_notes = ?,
                    external_moderation_status = ?,
                    external_moderator_id = ?,
                    external_moderated_at = NOW(),
                    external_moderation_notes = ?
                    {$mainStatusUpdate}
                WHERE id = ?";
                
        $stmt = $db->prepare($sql);
        if ($internal_status === 'approved') {
            $stmt->bind_param('ssssssssi', $internal_status, $actor, $internal_notes, $external_status, $external_moderator, $external_notes, $actor, $id);
        } elseif ($internal_status === 'rejected') {
            $stmt->bind_param('ssssssssi', $internal_status, $actor, $internal_notes, $external_status, $external_moderator, $external_notes, $internal_notes, $id);
        } else {
            $stmt->bind_param('sssssssi', $internal_status, $actor, $internal_notes, $external_status, $external_moderator, $external_notes, $id);
        }
        $stmt->execute();
        $stmt->close();
    }

    wuc_result_sync_normalized_by_assessment_id($db, $id, $actor);

    wuc_result_log($db, [
        'assessment_id' => $id,
        'Sid' => (string)$row['Sid'],
        'Course_Code' => (string)$row['Course_Code'],
        'semester' => (string)$row['semester'],
        'Year' => (string)$row['Year'],
        'action' => $action === 'moderate' ? 'moderated' : ($action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'published')),
        'field_changed' => $action === 'moderate' ? 'internal_moderation_status' : 'status',
        'old_value' => $action === 'moderate' ? $current : $current,
        'new_value' => $action === 'moderate' ? $internal_status : $target,
        'reason' => $action === 'moderate' ? $internal_notes : ($action === 'reject' ? $reason : null),
        'actor_staff_id' => $actor,
    ]);

    $_SESSION['successMsg'] = $action === 'moderate' ? 'Moderation evaluation logged successfully.' : 'Result ' . strtolower($target) . '.';
} catch (Throwable $e) {
    error_log('result transition failed: ' . $e->getMessage());
    $_SESSION['errorMsg'] = 'The decision could not be saved: ' . $e->getMessage();
}

header('Location: CAmanager.php', true, 303);
exit;
