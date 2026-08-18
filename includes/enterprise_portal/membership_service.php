<?php
declare(strict_types=1);

/**
 * EnterpriseMembershipService — voluntary opt-in lifecycle.
 */

if (!function_exists('ep_membership_statuses')) {
    /** @return list<string> */
    function ep_membership_statuses(): array
    {
        return ['not_enrolled', 'pending', 'changes_requested', 'active', 'declined', 'suspended', 'withdrawn', 'archived'];
    }
}

if (!function_exists('ep_membership_allowed_transitions')) {
    /** @return array<string,list<string>> */
    function ep_membership_allowed_transitions(): array
    {
        return [
            'not_enrolled' => ['pending'],
            'pending' => ['active', 'changes_requested', 'declined'],
            'changes_requested' => ['pending'],
            'active' => ['suspended', 'withdrawn'],
            'suspended' => ['active'],
            'withdrawn' => ['pending', 'archived'],
            'declined' => ['pending', 'archived'],
            'archived' => [],
        ];
    }
}

if (!function_exists('ep_get_membership_for_user')) {
    function ep_get_membership_for_user(mysqli $db, int $userId, ?string $studentId = null): ?array
    {
        if ($userId <= 0 && ($studentId === null || $studentId === '')) {
            return null;
        }
        if ($userId > 0) {
            $stmt = $db->prepare("SELECT * FROM enterprise_memberships WHERE user_id = ? AND status <> 'archived' ORDER BY id DESC LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
        if ($studentId) {
            $stmt = $db->prepare("SELECT * FROM enterprise_memberships WHERE student_id = ? AND status <> 'archived' ORDER BY id DESC LIMIT 1");
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        }
        return null;
    }
}

if (!function_exists('ep_get_memberships_for_user')) {
    /**
     * All non-archived memberships for workspace switching.
     *
     * @return list<array<string,mixed>>
     */
    function ep_get_memberships_for_user(mysqli $db, int $userId, ?string $studentId = null): array
    {
        $rows = [];
        if ($userId > 0) {
            $stmt = $db->prepare("SELECT * FROM enterprise_memberships WHERE user_id = ? AND status <> 'archived' ORDER BY id DESC");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $stmt->close();
            }
        }
        if ($rows === [] && $studentId) {
            $stmt = $db->prepare("SELECT * FROM enterprise_memberships WHERE student_id = ? AND status <> 'archived' ORDER BY id DESC");
            if ($stmt) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $stmt->close();
            }
        }
        return $rows;
    }
}

if (!function_exists('ep_resolve_membership_context')) {
    function ep_resolve_membership_context(mysqli $db, int $userId, ?string $studentId = null): ?array
    {
        $ctxId = (int)($_SESSION['enterprise_membership_id'] ?? 0);
        if ($ctxId > 0) {
            $stmt = $db->prepare('SELECT * FROM enterprise_memberships WHERE id = ? AND status <> ? LIMIT 1');
            if ($stmt) {
                $arch = 'archived';
                $stmt->bind_param('is', $ctxId, $arch);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    if ($userId > 0 && (int)$row['user_id'] !== $userId) {
                        return ep_get_membership_for_user($db, $userId, $studentId);
                    }
                    if ($studentId && !empty($row['student_id']) && (string)$row['student_id'] !== $studentId) {
                        return ep_get_membership_for_user($db, $userId, $studentId);
                    }
                    return $row;
                }
            }
        }
        return ep_get_membership_for_user($db, $userId, $studentId);
    }
}

if (!function_exists('ep_membership_effective_status')) {
    function ep_membership_effective_status(?array $membership): string
    {
        if (!$membership) {
            return 'not_enrolled';
        }
        return (string)($membership['status'] ?? 'not_enrolled');
    }
}

if (!function_exists('ep_can_transition_membership')) {
    function ep_can_transition_membership(string $from, string $to): bool
    {
        return in_array($to, ep_membership_allowed_transitions()[$from] ?? [], true);
    }
}

if (!function_exists('ep_check_eligibility')) {
    /** @return array{ok:bool,result:string,notes:string,membership_type:string} */
    function ep_check_eligibility(mysqli $db): array
    {
        $allowStudents = ep_setting_bool($db, 'allow_current_students', true);
        $allowGrads = ep_setting_bool($db, 'allow_graduates', true);
        $sid = ep_current_student_id();
        if ($sid && $allowStudents) {
            return ['ok' => true, 'result' => 'eligible_student', 'notes' => 'Current student account.', 'membership_type' => 'student'];
        }
        // Graduate: alumni portal access or role
        $userId = ep_current_user_id();
        if ($allowGrads && $userId > 0 && function_exists('wuc_user_has_portal_access') && wuc_user_has_portal_access($db, $userId, 'alumni')) {
            return ['ok' => true, 'result' => 'eligible_graduate', 'notes' => 'Graduate / alumni account.', 'membership_type' => 'graduate'];
        }
        if ($sid && !$allowStudents) {
            return ['ok' => false, 'result' => 'students_disabled', 'notes' => 'Current student participation is disabled.', 'membership_type' => 'student'];
        }
        return ['ok' => false, 'result' => 'not_eligible', 'notes' => 'Only eligible students or graduates may join.', 'membership_type' => 'student'];
    }
}

if (!function_exists('ep_submit_membership')) {
    /**
     * @param list<string> $goals
     * @param array<string,bool> $consents consent_type => accepted
     * @return array{ok:bool,message:string,id?:int}
     */
    function ep_submit_membership(mysqli $db, array $goals, array $consents, string $ip = '', string $ua = ''): array
    {
        if (!ep_portal_enabled($db)) {
            return ['ok' => false, 'message' => 'The Skills and Enterprise Portal is currently unavailable.'];
        }
        $userId = ep_current_user_id();
        $sid = ep_current_student_id();
        if ($userId <= 0 && !$sid) {
            return ['ok' => false, 'message' => 'You must be signed in to join.'];
        }

        $existing = ep_get_membership_for_user($db, $userId, $sid);
        $current = ep_membership_effective_status($existing);
        if (in_array($current, ['pending', 'active', 'changes_requested', 'suspended'], true)) {
            return ['ok' => false, 'message' => 'You already have an active or pending membership.'];
        }

        $elig = ep_check_eligibility($db);
        if (!$elig['ok']) {
            return ['ok' => false, 'message' => $elig['notes']];
        }

        $validGoals = array_keys(ep_participation_goals());
        $goals = array_values(array_intersect($goals, $validGoals));
        if ($goals === []) {
            return ['ok' => false, 'message' => 'Select at least one participation goal.'];
        }

        $requiredConsents = ep_required_consent_types();
        foreach ($requiredConsents as $type) {
            if (empty($consents[$type])) {
                return ['ok' => false, 'message' => 'All required consent declarations must be accepted.'];
            }
        }

        $version = (string)(ep_setting($db, 'consent_version', '1.0') ?? '1.0');
        $goalsJson = json_encode($goals, JSON_UNESCAPED_UNICODE);
        $now = date('Y-m-d H:i:s');
        $mode = strtolower((string)(ep_setting($db, 'membership_approval_mode', 'manual') ?? 'manual'));
        $auto = in_array($mode, ['automatic', 'auto'], true);

        $db->begin_transaction();
        try {
            if ($existing && in_array($current, ['withdrawn', 'declined'], true)) {
                if (!ep_can_transition_membership($current, 'pending')) {
                    throw new RuntimeException('Cannot reapply from current status.');
                }
                $id = (int)$existing['id'];
                $status = $auto ? 'active' : 'pending';
                $stmt = $db->prepare("UPDATE enterprise_memberships SET
                    status=?, participation_goals_json=?, eligibility_result=?, eligibility_notes=?,
                    membership_type=?, submitted_at=?, approved_at=?, approved_by=?,
                    declined_at=NULL, declined_by=NULL, decline_reason=NULL,
                    withdrawn_at=NULL, withdrawal_reason=NULL,
                    last_status_change_at=?, updated_at=NOW()
                    WHERE id=?");
                $approvedAt = $auto ? $now : null;
                $approvedBy = $auto ? 'system_auto' : null;
                $eligResult = $elig['result'];
                $eligNotes = $elig['notes'];
                $mtype = $elig['membership_type'];
                $stmt->bind_param(
                    'sssssssssi',
                    $status, $goalsJson, $eligResult, $eligNotes, $mtype,
                    $now, $approvedAt, $approvedBy, $now, $id
                );
                $stmt->execute();
                $stmt->close();
            } else {
                $status = $auto ? 'active' : 'pending';
                $stmt = $db->prepare("INSERT INTO enterprise_memberships (
                    user_id, student_id, membership_type, status, participation_goals_json,
                    eligibility_result, eligibility_notes, submitted_at, approved_at, approved_by, last_status_change_at
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $approvedAt = $auto ? $now : null;
                $approvedBy = $auto ? 'system_auto' : null;
                $uid = $userId > 0 ? $userId : 0;
                $mtype = $elig['membership_type'];
                $eligResult = $elig['result'];
                $eligNotes = $elig['notes'];
                $stmt->bind_param(
                    'issssssssss',
                    $uid, $sid, $mtype, $status, $goalsJson, $eligResult, $eligNotes,
                    $now, $approvedAt, $approvedBy, $now
                );
                $stmt->execute();
                $id = (int)$db->insert_id;
                $stmt->close();
            }

            ep_store_consents($db, $id, $userId > 0 ? $userId : 0, $consents, $version, $ip, $ua);

            if ($auto && $userId > 0 && function_exists('wuc_grant_user_portal_access')) {
                wuc_grant_user_portal_access($db, $userId, ['enterprise'], 'enterprise_membership');
            }

            $db->commit();
            ep_audit($db, 'enterprise_portal.membership_submitted', ['membership_id' => $id, 'status' => $status]);
            if (function_exists('ep_notify_membership')) {
                ep_notify_membership($db, $id, $status);
            }
            $msg = $auto
                ? 'Your membership is active. You may open the Skills and Enterprise Portal.'
                : 'Your opt-in request has been submitted and is pending review.';
            return ['ok' => true, 'message' => $msg, 'id' => $id];
        } catch (Throwable $e) {
            $db->rollback();
            error_log('ep_submit_membership: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not submit membership. Please try again.'];
        }
    }
}

if (!function_exists('ep_transition_membership')) {
    /** @return array{ok:bool,message:string} */
    function ep_transition_membership(mysqli $db, int $membershipId, string $toStatus, string $reason = '', string $actor = ''): array
    {
        $actor = $actor !== '' ? $actor : ep_current_actor();
        $stmt = $db->prepare('SELECT * FROM enterprise_memberships WHERE id = ? FOR UPDATE');
        $db->begin_transaction();
        try {
            $stmt->bind_param('i', $membershipId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                throw new RuntimeException('Membership not found.');
            }
            $from = (string)$row['status'];
            if (!ep_can_transition_membership($from, $toStatus)) {
                throw new RuntimeException("Cannot change membership from {$from} to {$toStatus}.");
            }
            if (in_array($toStatus, ['changes_requested', 'declined', 'suspended', 'archived'], true) && trim($reason) === '') {
                throw new RuntimeException('A reason is required for this decision.');
            }

            $now = date('Y-m-d H:i:s');
            $sets = ['status = ?', 'last_status_change_at = ?', 'updated_at = NOW()'];
            $types = 'ss';
            $params = [$toStatus, $now];

            if ($toStatus === 'active') {
                $sets[] = 'approved_at = ?';
                $sets[] = 'approved_by = ?';
                $types .= 'ss';
                $params[] = $now;
                $params[] = $actor;
            } elseif ($toStatus === 'declined') {
                $sets[] = 'declined_at = ?';
                $sets[] = 'declined_by = ?';
                $sets[] = 'decline_reason = ?';
                $types .= 'sss';
                $params[] = $now;
                $params[] = $actor;
                $params[] = $reason;
            } elseif ($toStatus === 'suspended') {
                $sets[] = 'suspended_at = ?';
                $sets[] = 'suspended_by = ?';
                $sets[] = 'suspension_reason = ?';
                $types .= 'sss';
                $params[] = $now;
                $params[] = $actor;
                $params[] = $reason;
            } elseif ($toStatus === 'withdrawn') {
                $sets[] = 'withdrawn_at = ?';
                $sets[] = 'withdrawal_reason = ?';
                $types .= 'ss';
                $params[] = $now;
                $params[] = $reason !== '' ? $reason : 'Participant withdrew';
            } elseif ($toStatus === 'changes_requested') {
                $sets[] = 'eligibility_notes = ?';
                $types .= 's';
                $params[] = $reason;
            } elseif ($toStatus === 'archived') {
                $sets[] = 'archived_at = ?';
                $types .= 's';
                $params[] = $now;
            } elseif ($toStatus === 'pending') {
                $sets[] = 'submitted_at = ?';
                $types .= 's';
                $params[] = $now;
            }

            $types .= 'i';
            $params[] = $membershipId;
            $sql = 'UPDATE enterprise_memberships SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $upd = $db->prepare($sql);
            $upd->bind_param($types, ...$params);
            $upd->execute();
            $upd->close();

            $userId = (int)$row['user_id'];
            if ($toStatus === 'active' && $userId > 0 && function_exists('wuc_grant_user_portal_access')) {
                wuc_grant_user_portal_access($db, $userId, ['enterprise'], $actor);
            }
            if (in_array($toStatus, ['suspended', 'withdrawn', 'declined', 'archived'], true) && $userId > 0 && function_exists('wuc_revoke_user_portal_access')) {
                wuc_revoke_user_portal_access($db, $userId, ['enterprise'], $actor);
            }
            if (in_array($toStatus, ['suspended', 'withdrawn'], true)) {
                ep_unpublish_member_opportunities($db, $membershipId);
            }

            $db->commit();
            ep_audit($db, 'enterprise_portal.membership_transition', [
                'membership_id' => $membershipId,
                'from' => $from,
                'to' => $toStatus,
                'reason' => $reason,
            ]);
            if (function_exists('ep_notify_membership')) {
                ep_notify_membership($db, $membershipId, $toStatus, $reason);
            }
            return ['ok' => true, 'message' => 'Membership updated.'];
        } catch (Throwable $e) {
            $db->rollback();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('ep_unpublish_member_opportunities')) {
    function ep_unpublish_member_opportunities(mysqli $db, int $membershipId): void
    {
        $sql = "UPDATE enterprise_opportunities o
                JOIN enterprise_member_profiles p ON p.id = o.enterprise_profile_id
                SET o.status = 'unpublished', o.unpublished_at = NOW()
                WHERE p.membership_id = ? AND o.status = 'published'";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $membershipId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('ep_list_memberships')) {
    function ep_list_memberships(mysqli $db, string $status = '', int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if ($status !== '') {
            $stmt = $db->prepare('SELECT * FROM enterprise_memberships WHERE status = ? ORDER BY updated_at DESC LIMIT ?');
            $stmt->bind_param('si', $status, $limit);
        } else {
            $stmt = $db->prepare("SELECT * FROM enterprise_memberships WHERE status <> 'archived' ORDER BY updated_at DESC LIMIT ?");
            $stmt->bind_param('i', $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }
}
