<?php
declare(strict_types=1);

if (!function_exists('eh_notify_status_change')) {
    function eh_notify_status_change(mysqli $db, array $item, string $from, string $to, string $comments = ''): void
    {
        $studentId = (string)($item['student_id'] ?? '');
        $title = (string)($item['title'] ?? 'Opportunity');
        $itemId = (int)($item['id'] ?? 0);

        $studentMessages = [
            'submitted' => ['Item submitted', 'Your opportunity "' . $title . '" was submitted for lecturer verification.'],
            'changes_requested' => ['Changes requested', 'Changes were requested on "' . $title . '". ' . ($comments !== '' ? $comments : 'Please review feedback and resubmit.')],
            'lecturer_verified' => ['Lecturer verified', '"' . $title . '" was verified by a lecturer and awaits administrative approval.'],
            'rejected' => ['Item rejected', '"' . $title . '" was rejected. ' . ($comments !== '' ? $comments : '')],
            'approved' => ['Item approved', '"' . $title . '" was approved and can be published.'],
            'published' => ['Item published', '"' . $title . '" is now live in the public Skills-to-Trade showcase.'],
            'unpublished' => ['Item unpublished', '"' . $title . '" was unpublished from the public showcase.'],
        ];

        if ($studentId !== '' && isset($studentMessages[$to])) {
            [$t, $m] = $studentMessages[$to];
            wuc_notify_portal($db, [
                'user_id' => $studentId,
                'user_role' => 'student',
                'module' => 'enterprise_hub',
                'alert_type' => 'enterprise_status_' . $to,
                'severity' => in_array($to, ['rejected', 'changes_requested'], true) ? 'warning' : 'info',
                'title' => $t,
                'message' => $m,
                'entity_type' => 'enterprise_item',
                'entity_id' => (string)$itemId,
                'action_url' => '/wucportal/students/enterprise/item_view.php?id=' . $itemId,
                'target_portal' => 'academic',
                'dedupe_days' => 1,
            ]);
        }

        // Notify lecturers on new submissions
        if ($to === 'submitted' && function_exists('hasRole')) {
            // Soft notify: store a general admin/lecturer alert via staff who manage reviews — best-effort to systems admins not required.
        }

        if ($to === 'lecturer_verified') {
            // Admin officers — notify current actor's peers via audit only; officers see dashboard counts.
        }
    }
}

if (!function_exists('eh_notify_new_interest')) {
    function eh_notify_new_interest(mysqli $db, array $item, int $interestId): void
    {
        $title = (string)($item['title'] ?? 'Opportunity');
        $studentId = (string)($item['student_id'] ?? '');
        if ($studentId !== '') {
            wuc_notify_portal($db, [
                'user_id' => $studentId,
                'user_role' => 'student',
                'module' => 'enterprise_hub',
                'alert_type' => 'enterprise_interest_new',
                'severity' => 'info',
                'title' => 'New market interest',
                'message' => 'Someone expressed interest in "' . $title . '".',
                'entity_type' => 'enterprise_interest',
                'entity_id' => (string)$interestId,
                'action_url' => '/wucportal/students/enterprise/interests.php',
                'target_portal' => 'academic',
                'dedupe_days' => 1,
            ]);
        }

        // Find staff with interests.manage via systems_admin session not available; notify via portal alerts for registrar role users is heavy.
        // Log for admin dashboard visibility.
        eh_audit($db, 'enterprise_hub.interest_notify', [
            'interest_id' => $interestId,
            'item_id' => (int)($item['id'] ?? 0),
        ]);
    }
}

if (!function_exists('eh_notify_interest_assigned')) {
    function eh_notify_interest_assigned(mysqli $db, int $interestId, string $staffId): void
    {
        if ($staffId === '') {
            return;
        }
        wuc_notify_portal($db, [
            'user_id' => $staffId,
            'user_role' => 'staff',
            'module' => 'enterprise_hub',
            'alert_type' => 'enterprise_interest_assigned',
            'severity' => 'info',
            'title' => 'Interest assigned to you',
            'message' => 'An expression of interest was assigned for follow-up.',
            'entity_type' => 'enterprise_interest',
            'entity_id' => (string)$interestId,
            'action_url' => '/wucportal/admin/enterprise/interest_view.php?id=' . $interestId,
            'target_portal' => 'academic',
            'dedupe_days' => 1,
        ]);
    }
}
