<?php
declare(strict_types=1);

/**
 * Legacy Skills-to-Trade hub under students/enterprise — not part of the certificate academic portal.
 */

require_once dirname(__DIR__, 2) . '/includes/student_program_portal.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_portal/legacy_redirect.php';

if (!function_exists('ep_redirect_certificate_from_legacy_skills_hub')) {
    function ep_redirect_certificate_from_legacy_skills_hub(mysqli $db, string $studentId): void
    {
        if (!wuc_student_is_certificate_portal($db, $studentId)) {
            return;
        }
        ep_legacy_hub_redirect('participant');
    }
}
