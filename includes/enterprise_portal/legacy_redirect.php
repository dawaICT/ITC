<?php
declare(strict_types=1);

/**
 * Shared deprecation redirect from legacy academic exhibition hub → permanent Skills and Enterprise Portal.
 */
if (!function_exists('ep_legacy_hub_redirect')) {
    function ep_legacy_hub_redirect(string $audience = 'participant'): void
    {
        $map = [
            'participant' => '/wucportal/enterprise/join.php',
            'reviewer' => '/wucportal/enterprise/reviewer/index.php',
            'management' => '/wucportal/enterprise/management/index.php',
            'public' => '/wucportal/opportunities/index.php',
        ];
        $target = $map[$audience] ?? $map['participant'];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash_success'] = $_SESSION['flash_success']
                ?? 'The Skills and Enterprise Portal is now a separate portal. You have been redirected from the legacy academic hub.';
            $_SESSION['flash_info'] = $_SESSION['flash_info']
                ?? 'Use Portal Selection → Skills and Enterprise Portal for voluntary participation.';
        }
        if (function_exists('wuc_redirect')) {
            wuc_redirect($target);
        }
        header('Location: ' . $target, true, 302);
        exit;
    }
}
