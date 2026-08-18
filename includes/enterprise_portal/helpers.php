<?php
declare(strict_types=1);

if (!function_exists('ep_h')) {
    function ep_h(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ep_money')) {
    function ep_money($amount, string $currency = 'ZMW'): string
    {
        return $currency . ' ' . number_format((float)$amount, 2, '.', ',');
    }
}

if (!function_exists('ep_slugify')) {
    function ep_slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        $text = trim($text, '-');
        return $text !== '' ? substr($text, 0, 180) : 'opportunity';
    }
}

if (!function_exists('ep_public_code')) {
    function ep_public_code(): string
    {
        return 'ENT-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    }
}

if (!function_exists('ep_current_user_id')) {
    function ep_current_user_id(): int
    {
        return (int)($_SESSION['user_id_db'] ?? 0);
    }
}

if (!function_exists('ep_current_student_id')) {
    function ep_current_student_id(): ?string
    {
        $sid = trim((string)($_SESSION['Sid'] ?? ''));
        return $sid !== '' ? $sid : null;
    }
}

if (!function_exists('ep_current_actor')) {
    function ep_current_actor(): string
    {
        if (!empty($_SESSION['Sid'])) {
            return (string)$_SESSION['Sid'];
        }
        return (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
    }
}

if (!function_exists('ep_require_post_csrf')) {
    function ep_require_post_csrf(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        $token = $_POST['csrf_token'] ?? '';
        if (!function_exists('wuc_validate_csrf') || !wuc_validate_csrf(is_string($token) ? $token : null)) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }
    }
}

if (!function_exists('ep_audit')) {
    function ep_audit(mysqli $db, string $action, array $details = []): void
    {
        if (function_exists('audit_log_current_user')) {
            $details['module'] = 'enterprise_portal';
            audit_log_current_user($db, $action, $details);
        }
    }
}

if (!function_exists('ep_participation_goals')) {
    /** @return array<string,string> */
    function ep_participation_goals(): array
    {
        return [
            'find_employment' => 'Find employment',
            'offer_services' => 'Offer professional services',
            'sell_products' => 'Sell products',
            'showcase_skills' => 'Showcase practical skills',
            'develop_business_idea' => 'Develop a business idea',
            'develop_innovation' => 'Develop an innovation',
            'find_mentorship' => 'Find mentorship',
            'find_partners' => 'Find business partners',
            'seek_equipment' => 'Seek equipment support',
            'seek_market_access' => 'Seek market access',
            'prepare_investment' => 'Prepare for investment',
        ];
    }
}

if (!function_exists('ep_opportunity_types')) {
    /** @return array<string,string> */
    function ep_opportunity_types(): array
    {
        return [
            'professional_skill' => 'Professional skill',
            'employment_profile' => 'Employment profile',
            'service' => 'Service',
            'product' => 'Product',
            'innovation' => 'Innovation',
            'business_idea' => 'Business idea',
            'investment_opportunity' => 'Investment opportunity',
            'produce_listing' => 'Produce listing',
            'crop_demand' => 'Buyer crop demand',
        ];
    }
}

if (!function_exists('ep_status_label')) {
    function ep_status_label(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('ep_status_badge_class')) {
    function ep_status_badge_class(string $status): string
    {
        return match ($status) {
            'draft' => 'secondary',
            'pending', 'submitted' => 'info',
            'changes_requested', 'update_required' => 'warning',
            'active', 'approved', 'published', 'reviewer_verified' => 'success',
            'declined', 'rejected', 'suspended' => 'danger',
            'withdrawn', 'unpublished', 'archived' => 'dark',
            default => 'secondary',
        };
    }
}

if (!function_exists('ep_disclaimer_finance')) {
    function ep_disclaimer_finance(): string
    {
        return 'Financial calculations are estimates based on information supplied by the participant and do not represent guaranteed profits or investment returns.';
    }
}

if (!function_exists('ep_public_opportunity_url')) {
    function ep_public_opportunity_url(string $code): string
    {
        $path = '/wucportal/opportunities/view.php?code=' . rawurlencode($code);
        return function_exists('wuc_public_app_url') ? wuc_public_app_url(ltrim($path, '/')) : $path;
    }
}
