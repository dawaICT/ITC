<?php
declare(strict_types=1);

if (!function_exists('eh_h')) {
    function eh_h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('eh_money')) {
    function eh_money($amount, string $currency = 'ZMW'): string
    {
        return $currency . ' ' . number_format((float)$amount, 2, '.', ',');
    }
}

if (!function_exists('eh_slugify')) {
    function eh_slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        return $text !== '' ? substr($text, 0, 180) : 'item';
    }
}

if (!function_exists('eh_generate_public_code')) {
    function eh_generate_public_code(): string
    {
        return 'ENT-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('eh_unique_slug')) {
    function eh_unique_slug(mysqli $db, string $base, ?int $excludeId = null): string
    {
        $slug = eh_slugify($base);
        $candidate = $slug;
        $i = 2;
        while (true) {
            if ($excludeId) {
                $stmt = $db->prepare('SELECT id FROM enterprise_items WHERE slug = ? AND id <> ? LIMIT 1');
                $stmt->bind_param('si', $candidate, $excludeId);
            } else {
                $stmt = $db->prepare('SELECT id FROM enterprise_items WHERE slug = ? LIMIT 1');
                $stmt->bind_param('s', $candidate);
            }
            $stmt->execute();
            $exists = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exists) {
                return $candidate;
            }
            $candidate = $slug . '-' . $i;
            $i++;
            if ($i > 500) {
                return $slug . '-' . bin2hex(random_bytes(3));
            }
        }
    }
}

if (!function_exists('eh_status_badge_class')) {
    function eh_status_badge_class(string $status): string
    {
        return match ($status) {
            'draft' => 'secondary',
            'submitted' => 'info',
            'changes_requested' => 'warning',
            'lecturer_verified' => 'primary',
            'rejected' => 'danger',
            'approved' => 'success',
            'published' => 'success',
            'unpublished' => 'dark',
            'archived' => 'secondary',
            default => 'secondary',
        };
    }
}

if (!function_exists('eh_status_label')) {
    function eh_status_label(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('eh_item_types')) {
    /** @return array<string,string> */
    function eh_item_types(): array
    {
        return [
            'product' => 'Product',
            'service' => 'Service',
            'innovation' => 'Innovation',
            'business_idea' => 'Business Idea',
            'investment_opportunity' => 'Investment Opportunity',
        ];
    }
}

if (!function_exists('eh_profile_types')) {
    /** @return array<string,string> */
    function eh_profile_types(): array
    {
        return [
            'student_project' => 'Student Project',
            'graduate_enterprise' => 'Graduate Enterprise',
            'existing_sme' => 'Existing SME',
            'group_project' => 'Group Project',
            'institutional_innovation' => 'Institutional Innovation',
        ];
    }
}

if (!function_exists('eh_interest_types')) {
    /** @return array<string,string> */
    function eh_interest_types(): array
    {
        return [
            'product_purchase' => 'Product Purchase',
            'service_request' => 'Service Request',
            'employment_offer' => 'Employment Offer',
            'equipment_support' => 'Equipment Support',
            'funding' => 'Funding / Investment',
            'mentorship' => 'Mentorship',
            'distribution_partnership' => 'Distribution Partnership',
            'training_partnership' => 'Training Partnership',
            'request_information' => 'Request Information',
        ];
    }
}

if (!function_exists('eh_follow_up_statuses')) {
    /** @return array<string,string> */
    function eh_follow_up_statuses(): array
    {
        return [
            'new' => 'New',
            'acknowledged' => 'Acknowledged',
            'contacted' => 'Contacted',
            'meeting_scheduled' => 'Meeting Scheduled',
            'under_review' => 'Under Review',
            'converted' => 'Converted',
            'closed' => 'Closed',
            'not_suitable' => 'Not Suitable',
        ];
    }
}

if (!function_exists('eh_public_item_url')) {
    function eh_public_item_url(string $publicCode): string
    {
        $path = '/wucportal/showcase/item.php?code=' . rawurlencode($publicCode);
        return function_exists('wuc_public_app_url')
            ? wuc_public_app_url(ltrim($path, '/'))
            : $path;
    }
}

if (!function_exists('eh_require_post_csrf')) {
    function eh_require_post_csrf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!function_exists('wuc_validate_csrf') || !wuc_validate_csrf(is_string($token) ? $token : null)) {
            http_response_code(403);
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }
    }
}

if (!function_exists('eh_current_actor_id')) {
    function eh_current_actor_id(): string
    {
        if (!empty($_SESSION['Sid'])) {
            return (string)$_SESSION['Sid'];
        }
        return (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
    }
}

if (!function_exists('eh_current_owner_user_id')) {
    function eh_current_owner_user_id(): int
    {
        return (int)($_SESSION['user_id_db'] ?? 0);
    }
}

if (!function_exists('eh_current_student_id')) {
    function eh_current_student_id(): ?string
    {
        $sid = trim((string)($_SESSION['Sid'] ?? $_SESSION['student_id'] ?? ''));
        return $sid !== '' ? $sid : null;
    }
}

if (!function_exists('eh_audit')) {
    function eh_audit(mysqli $db, string $action, array $details = []): void
    {
        if (function_exists('audit_log_current_user')) {
            $details['module'] = 'enterprise_hub';
            audit_log_current_user($db, $action, $details);
        }
    }
}

if (!function_exists('eh_csv_safe')) {
    function eh_csv_safe($value): string
    {
        $text = (string)$value;
        if ($text !== '' && preg_match('/^[=+\-@]/', $text)) {
            return "'" . $text;
        }
        return $text;
    }
}

if (!function_exists('eh_disclaimer_finance')) {
    function eh_disclaimer_finance(): string
    {
        return 'Financial calculations are estimates based on information supplied by the enterprise owner and do not constitute an investment guarantee.';
    }
}
