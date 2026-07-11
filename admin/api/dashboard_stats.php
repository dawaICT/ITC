<?php
// Lightweight dashboard statistics feed. Returns a handful of aggregate counts
// so a dashboard can render its shell instantly and hydrate the numbers via a
// single fetch, instead of running every count inline during page load.
require __DIR__ . '/bootstrap.php';

/** Safe COUNT(*) for a table that may not exist in every environment. */
function wuc_api_count(mysqli $db, string $table, string $where = ''): int
{
    if (function_exists('wuc_table_exists') && !wuc_table_exists($db, $table)) {
        return 0;
    }
    $sql = "SELECT COUNT(*) AS c FROM `" . str_replace('`', '', $table) . "`"
        . ($where !== '' ? " WHERE $where" : '');
    if ($res = @$db->query($sql)) {
        return (int) ($res->fetch_assoc()['c'] ?? 0);
    }
    return 0;
}

$stats = [
    'students'            => wuc_api_count($db, 'students'),
    'staff'               => wuc_api_count($db, 'staff'),
    'applicants'          => wuc_api_count($db, 'online_applicants'),
    'courses'             => wuc_api_count($db, 'courses'),
    'programs'            => wuc_api_count($db, 'programs', 'is_active = 1'),
    'active_registrations'=> wuc_api_count($db, 'course_registration', 'is_active = 1'),
];

// Total collected payments (guarded).
$stats['payments_total'] = 0.0;
if (!function_exists('wuc_table_exists') || wuc_table_exists($db, 'student_payments')) {
    if ($res = @$db->query("SELECT COALESCE(SUM(amount_paid), 0) AS total FROM student_payments")) {
        $stats['payments_total'] = (float) ($res->fetch_assoc()['total'] ?? 0);
    }
}

echo json_encode(['ok' => true, 'stats' => $stats]);
