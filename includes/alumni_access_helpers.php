<?php
declare(strict_types=1);

require_once __DIR__ . '/portal_access.php';

if (!function_exists('wuc_sync_alumni_portal_access')) {
    /**
     * Keep the alumni portal grant in step with a student's graduation
     * clearance. Approved/Graduated → grant (assigned_by 'graduation').
     * Any other status → revoke only grants that 'graduation' itself made,
     * so manual admin grants are never clobbered.
     *
     * Safe no-op when the student has no users row (no login account).
     */
    function wuc_sync_alumni_portal_access(mysqli $db, string $studentId, string $gradStatus): void
    {
        $studentId = trim($studentId);
        if ($studentId === '' || !wuc_portal_table_exists($db, 'users')) {
            return;
        }

        $stmt = $db->prepare('SELECT user_id FROM users WHERE student_id = ? LIMIT 1');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        // Keep grant/revoke aligned with wuc_student_is_alumni_eligible().
        if (in_array($gradStatus, ['Approved', 'Graduated'], true)) {
            wuc_grant_user_portal_access($db, $userId, ['alumni'], 'graduation');
            return;
        }

        // Regression (e.g. Approved → Applied): withdraw only our own grant.
        $portalId = wuc_portal_id($db, 'alumni');
        if ($portalId === null) {
            return;
        }
        $stmt = $db->prepare(
            "UPDATE user_portal_access
                SET access_status = 'disabled', end_date = CURDATE(), updated_at = NOW()
              WHERE user_id = ? AND portal_id = ? AND access_status = 'active' AND assigned_by = 'graduation'"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $userId, $portalId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
