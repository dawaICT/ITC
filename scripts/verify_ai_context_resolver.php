<?php
declare(strict_types=1);

/**
 * Verify multi-portal AI context resolver coverage against current role call-sites.
 *
 * Run:
 *   C:\xampp\php\php.exe scripts\verify_ai_context_resolver.php
 */

require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/ai_context_service.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$failures = 0;

function verify_line(bool $ok, string $message): void
{
    global $failures;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function verify_required_context_row(mysqli $db, string $role): void
{
    $sql = "
        SELECT ac.context_type
          FROM ai_contexts ac
          INNER JOIN portals p ON p.id = ac.portal_id
         WHERE p.portal_code = 'academic'
           AND ac.module_name = 'academic'
           AND ac.user_role = ?
           AND ac.status = 'active'
         LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    verify_line((bool)$row, "active academic ai_context exists for {$role}");
}

function verify_resolver_case(mysqli $db, string $label, array $options, string $expectedRole, string $expectedFirstRole): void
{
    $resolved = wuc_ai_resolve_context($db, $options);
    $contexts = $resolved['contexts'] ?? [];
    $first = $contexts[0] ?? [];
    $firstRole = (string)($first['user_role'] ?? '');
    $firstType = (string)($first['context_type'] ?? '');

    verify_line((string)$resolved['portal_code'] === 'academic', "{$label}: portal resolves to academic");
    verify_line((string)$resolved['module_name'] === 'academic', "{$label}: module resolves to academic");
    verify_line((string)$resolved['user_role'] === $expectedRole, "{$label}: role normalizes to {$expectedRole}");
    verify_line($firstRole === $expectedFirstRole, "{$label}: first context is {$expectedFirstRole} ({$firstType})");
    verify_line(strpos((string)$resolved['rules_text'], $firstType . ':') !== false, "{$label}: rules_text includes first context rules");
}

$requiredRoles = ['dean', 'exams_officer', 'transport_officer', 'systems_admin'];
foreach ($requiredRoles as $role) {
    verify_required_context_row($db, $role);
}

$cases = [
    [
        'label' => 'dean/reports.php current options',
        'options' => [
            'portal_code' => 'academic',
            'feature' => 'dean_report_summary',
            'user_role' => 'dean',
            'user_id' => 'VERIFY_DEAN',
        ],
        'expected_role' => 'dean',
        'expected_first_role' => 'dean',
    ],
    [
        'label' => 'exams_officer explicit role',
        'options' => [
            'portal_code' => 'academic',
            'feature' => 'exam_results_processing',
            'user_role' => 'exams_officer',
            'user_id' => 'VERIFY_EXAMS',
        ],
        'expected_role' => 'exams_officer',
        'expected_first_role' => 'exams_officer',
    ],
    [
        'label' => 'transport/reports.php current options',
        'options' => [
            'portal_code' => 'academic',
            'feature' => 'transport_report_summary',
            'user_role' => 'transport',
            'user_id' => 'VERIFY_TRANSPORT',
        ],
        'expected_role' => 'transport_officer',
        'expected_first_role' => 'transport_officer',
    ],
    [
        'label' => 'admin/ai_reports.php current options',
        'options' => [
            'portal_code' => 'academic',
            'feature' => 'admin_ai_reports',
            'user_role' => 'admin',
            'user_id' => 'VERIFY_ADMIN',
        ],
        'expected_role' => 'systems_admin',
        'expected_first_role' => 'systems_admin',
    ],
    [
        'label' => 'systems_admin explicit role',
        'options' => [
            'portal_code' => 'academic',
            'feature' => 'systems_admin_console',
            'user_role' => 'systems_admin',
            'user_id' => 'VERIFY_SYSADMIN',
        ],
        'expected_role' => 'systems_admin',
        'expected_first_role' => 'systems_admin',
    ],
];

foreach ($cases as $case) {
    verify_resolver_case(
        $db,
        $case['label'],
        $case['options'],
        $case['expected_role'],
        $case['expected_first_role']
    );
}

if ($failures > 0) {
    echo "AI context resolver verification failed with {$failures} failure(s)." . PHP_EOL;
    exit(1);
}

echo 'AI context resolver verification passed.' . PHP_EOL;
