<?php
declare(strict_types=1);

if (!function_exists('eh_allowed_transitions')) {
    /** @return array<string, list<string>> */
    function eh_allowed_transitions(): array
    {
        return [
            'draft' => ['submitted', 'archived'],
            'submitted' => ['changes_requested', 'lecturer_verified', 'rejected'],
            'changes_requested' => ['submitted'],
            'lecturer_verified' => ['approved', 'rejected'],
            'rejected' => ['archived'],
            'approved' => ['published'],
            'published' => ['unpublished'],
            'unpublished' => ['published', 'archived'],
            'archived' => [],
        ];
    }
}

if (!function_exists('eh_can_transition')) {
    function eh_can_transition(string $from, string $to): bool
    {
        $allowed = eh_allowed_transitions()[$from] ?? [];
        return in_array($to, $allowed, true);
    }
}

if (!function_exists('eh_decision_to_status')) {
    function eh_decision_to_status(string $decision): ?string
    {
        return match ($decision) {
            'request_changes' => 'changes_requested',
            'verify' => 'lecturer_verified',
            'reject' => 'rejected',
            'approve' => 'approved',
            'publish' => 'published',
            'unpublish' => 'unpublished',
            'submit' => 'submitted',
            'archive' => 'archived',
            default => null,
        };
    }
}

if (!function_exists('eh_required_capability_for_transition')) {
    function eh_required_capability_for_transition(string $toStatus): ?string
    {
        return match ($toStatus) {
            'submitted' => 'enterprise.item.submit',
            'changes_requested' => 'enterprise.review.request_changes',
            'lecturer_verified' => 'enterprise.review.verify',
            'rejected' => 'enterprise.review.reject',
            'approved' => 'enterprise.approve',
            'published' => 'enterprise.publish',
            'unpublished' => 'enterprise.unpublish',
            'archived' => 'enterprise.item.edit_own',
            default => null,
        };
    }
}

if (!function_exists('eh_item_submission_errors')) {
    /** @return list<string> */
    function eh_item_submission_errors(mysqli $db, int $itemId): array
    {
        $errors = [];
        $item = eh_get_item($db, $itemId);
        if (!$item) {
            return ['Item not found.'];
        }
        if (trim((string)$item['title']) === '') {
            $errors[] = 'Title is required.';
        }
        if (trim((string)($item['short_description'] ?? '')) === '') {
            $errors[] = 'Short description is required.';
        }
        if (trim((string)($item['full_description'] ?? '')) === '') {
            $errors[] = 'Full description is required.';
        }
        if (empty($item['category_id'])) {
            $errors[] = 'Category is required.';
        }
        if (empty($item['item_type'])) {
            $errors[] = 'Item type is required.';
        }

        $mediaCount = eh_count_item_media($db, $itemId);
        if ($mediaCount < 1) {
            $errors[] = 'At least one image is required before submission.';
        }
        if (!eh_item_has_primary_media($db, $itemId)) {
            $errors[] = 'A primary image must be selected.';
        }

        $cost = eh_get_costs($db, $itemId);
        if (!$cost) {
            $errors[] = 'Cost and profit calculation is required.';
        }

        $readiness = eh_get_readiness($db, $itemId);
        if (!$readiness) {
            $errors[] = 'Business-readiness assessment is required.';
        }

        $profile = eh_get_profile($db, (int)$item['enterprise_profile_id']);
        if (!$profile || trim((string)$profile['business_name']) === '' || trim((string)($profile['description'] ?? '')) === '') {
            $errors[] = 'Complete your enterprise profile before submitting.';
        }

        return $errors;
    }
}

if (!function_exists('eh_transition_item')) {
    /**
     * Centralized status transition with permission, comment and audit controls.
     *
     * @return array{success:bool, message:string, item?:array}
     */
    function eh_transition_item(
        mysqli $db,
        int $itemId,
        string $toStatus,
        string $decision,
        string $comments = '',
        string $reviewStage = ''
    ): array {
        $actor = eh_current_actor_id();
        if ($actor === '') {
            return ['success' => false, 'message' => 'Not authenticated.'];
        }

        $cap = eh_required_capability_for_transition($toStatus);
        if ($cap && !eh_can($db, $cap)) {
            return ['success' => false, 'message' => 'Permission denied for this status change.'];
        }

        $db->begin_transaction();
        try {
            $stmt = $db->prepare('SELECT * FROM enterprise_items WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$item) {
                throw new RuntimeException('Item not found.');
            }

            $from = (string)$item['status'];
            if (!eh_can_transition($from, $toStatus)) {
                throw new RuntimeException("Cannot change status from {$from} to {$toStatus}.");
            }

            if (in_array($decision, ['request_changes', 'reject'], true) && trim($comments) === '') {
                throw new RuntimeException('Comments are required for this decision.');
            }

            if ($toStatus === 'submitted') {
                $errors = eh_item_submission_errors($db, $itemId);
                if ($errors) {
                    throw new RuntimeException(implode(' ', $errors));
                }
                // Ownership check for student submit
                eh_assert_owns_item($db, $item);
            }

            if ($toStatus === 'published') {
                if (!eh_item_has_primary_media($db, $itemId)) {
                    throw new RuntimeException('A primary image is required before publication.');
                }
            }

            $now = date('Y-m-d H:i:s');
            $sets = ['status = ?', 'updated_at = NOW()'];
            $types = 's';
            $params = [$toStatus];

            if ($toStatus === 'submitted') {
                $sets[] = 'submitted_at = ?';
                $types .= 's';
                $params[] = $now;
            } elseif ($toStatus === 'lecturer_verified') {
                $sets[] = 'lecturer_verified_at = ?';
                $types .= 's';
                $params[] = $now;
            } elseif ($toStatus === 'approved') {
                $sets[] = 'approved_at = ?';
                $types .= 's';
                $params[] = $now;
            } elseif ($toStatus === 'published') {
                $sets[] = 'published_at = ?';
                $sets[] = 'unpublished_at = NULL';
                $types .= 's';
                $params[] = $now;
            } elseif ($toStatus === 'unpublished') {
                $sets[] = 'unpublished_at = ?';
                $types .= 's';
                $params[] = $now;
            } elseif ($toStatus === 'archived') {
                $sets[] = 'archived_at = ?';
                $types .= 's';
                $params[] = $now;
            }

            $types .= 'i';
            $params[] = $itemId;
            $sql = 'UPDATE enterprise_items SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $upd = $db->prepare($sql);
            $upd->bind_param($types, ...$params);
            $upd->execute();
            $upd->close();

            if ($reviewStage === '') {
                $reviewStage = in_array($toStatus, ['changes_requested', 'lecturer_verified', 'rejected'], true) && $from === 'submitted'
                    ? 'lecturer'
                    : (in_array($toStatus, ['approved', 'rejected', 'published', 'unpublished'], true) ? 'administration' : 'workflow');
            }

            $rev = $db->prepare("
                INSERT INTO enterprise_reviews
                    (enterprise_item_id, reviewer_id, review_stage, decision, comments, previous_status, resulting_status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $rev->bind_param('issssss', $itemId, $actor, $reviewStage, $decision, $comments, $from, $toStatus);
            $rev->execute();
            $rev->close();

            $db->commit();

            $fresh = eh_get_item($db, $itemId);
            eh_audit($db, 'enterprise_hub.status_transition', [
                'item_id' => $itemId,
                'from' => $from,
                'to' => $toStatus,
                'decision' => $decision,
            ]);

            if (function_exists('eh_notify_status_change')) {
                eh_notify_status_change($db, $fresh ?? $item, $from, $toStatus, $comments);
            }

            return ['success' => true, 'message' => 'Status updated.', 'item' => $fresh ?? $item];
        } catch (Throwable $e) {
            $db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
