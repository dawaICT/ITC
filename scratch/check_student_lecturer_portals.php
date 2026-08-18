<?php
declare(strict_types=1);
/**
 * Runtime check: what portals students vs lecturers see after the portal_selection fix.
 * Run: C:\xampp\php\php.exe scratch/check_student_lecturer_portals.php
 */
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/portal_access.php';
require_once dirname(__DIR__) . '/includes/portal_switch.php';

header('Content-Type: text/plain; charset=utf-8');

function dump_user(mysqli $db, int $userId, string $label, string $kind): void
{
    echo "=== {$label} (user_id={$userId}, kind={$kind}) ===\n";
    $user = wuc_load_portal_user_row($db, $userId);
    if (!$user) {
        echo "  MISSING user row\n\n";
        return;
    }
    echo '  username: ' . ($user['username'] ?? '') . "\n";
    echo '  primary_role: ' . ($user['primary_role'] ?? '') . "\n";
    echo '  staff_id: ' . ($user['staff_id'] ?? '') . "\n";
    echo '  student_id: ' . ($user['student_id'] ?? '') . "\n";
    echo '  is_fully_registered_student: ' . (wuc_user_is_fully_registered_student($db, $userId, $user) ? 'yes' : 'no') . "\n";
    echo '  is_staff_account: ' . (wuc_user_is_staff_account($db, $userId, $user) ? 'yes' : 'no') . "\n";
    echo '  applicant_stage: ' . (wuc_user_applicant_stage($db, $userId, $user) ?? 'null') . "\n";

    $portals = wuc_user_active_portals($db, $userId);
    if ($portals === []) {
        echo "  active_portals: (none)\n";
    } else {
        echo "  active_portals (" . count($portals) . "):\n";
        foreach ($portals as $p) {
            $code = (string)$p['portal_code'];
            $name = (string)($p['portal_name'] ?? $code);
            $desc = trim((string)($p['description'] ?? ''));
            echo "    - {$code} | {$name}" . ($desc !== '' ? " | {$desc}" : '') . "\n";
            if (function_exists('wuc_portal_landing_url')) {
                echo '      landing: ' . wuc_portal_landing_url($code, $kind) . "\n";
            }
        }
    }

    foreach (['applicant', 'academic', 'elearning', 'library'] as $code) {
        $ok = wuc_user_has_portal_access($db, $userId, $code) ? 'yes' : 'no';
        echo "  has_{$code}: {$ok}\n";
    }
    echo '  after_login_url: ' . wuc_after_login_portal_url($db, $userId, $kind) . "\n";

    if (count($portals) === 1) {
        echo "  UI: auto-switch (portal_selection skipped)\n";
    } elseif (count($portals) > 1) {
        echo "  UI: portal_selection shows Choose Portal cards\n";
    } else {
        echo "  UI: redirected to login with no-access error\n";
    }
    echo "\n";
}

// Resolve known test accounts
$targets = [
    ['label' => 'Student CSE26456789', 'kind' => 'student', 'sql' => "SELECT user_id FROM users WHERE student_id='CSE26456789' LIMIT 1"],
    ['label' => 'Lecturer ITC907', 'kind' => 'lecturer', 'sql' => "SELECT user_id FROM users WHERE username='ITC907' OR staff_id='ITC907' LIMIT 1"],
    ['label' => 'Lecturer ITC900 (admin)', 'kind' => 'lecturer', 'sql' => "SELECT user_id FROM users WHERE username='ITC900' OR staff_id='ITC900' LIMIT 1"],
];

// Also sample a few more students/lecturers with multiple portals
$extraStudents = $db->query(
    "SELECT u.user_id, u.username, u.student_id,
            GROUP_CONCAT(p.portal_code ORDER BY p.portal_code) AS portals
       FROM users u
       INNER JOIN user_portal_access upa ON upa.user_id=u.user_id AND upa.access_status='active'
       INNER JOIN portals p ON p.id=upa.portal_id AND p.status='active'
      WHERE u.student_id IS NOT NULL AND u.student_id <> ''
      GROUP BY u.user_id
     HAVING COUNT(*) > 1
      LIMIT 5"
);
$extraLecturers = $db->query(
    "SELECT u.user_id, u.username, u.staff_id, u.primary_role,
            GROUP_CONCAT(p.portal_code ORDER BY p.portal_code) AS portals
       FROM users u
       INNER JOIN user_portal_access upa ON upa.user_id=u.user_id AND upa.access_status='active'
       INNER JOIN portals p ON p.id=upa.portal_id AND p.status='active'
      WHERE u.staff_id IS NOT NULL AND u.staff_id <> ''
        AND (u.primary_role='lecturer' OR EXISTS (
              SELECT 1 FROM user_roles ur
              INNER JOIN roles r ON r.role_id=ur.role_id
              WHERE ur.user_id=u.user_id AND r.role_name='lecturer'
            ))
      GROUP BY u.user_id
      LIMIT 5"
);

echo "PORTAL SELECTION — STUDENT vs LECTURER DETAIL CHECK\n";
echo "Generated: " . date('c') . "\n\n";

foreach ($targets as $t) {
    $r = $db->query($t['sql']);
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        echo "=== {$t['label']} ===\n  NOT FOUND\n\n";
        continue;
    }
    dump_user($db, (int)$row['user_id'], $t['label'], $t['kind']);
}

echo "=== Sample students with multiple DB portal grants ===\n";
if ($extraStudents) {
    while ($row = $extraStudents->fetch_assoc()) {
        $uid = (int)$row['user_id'];
        $filtered = wuc_user_active_portals($db, $uid);
        $filteredCodes = implode(',', array_column($filtered, 'portal_code'));
        $hasApplicant = in_array('applicant', array_column($filtered, 'portal_code'), true) ? 'YES' : 'no';
        echo "  user {$uid} {$row['student_id']} db=[{$row['portals']}] filtered=[{$filteredCodes}] applicant_visible={$hasApplicant}\n";
    }
}
echo "\n=== Sample lecturers (raw grants) ===\n";
if ($extraLecturers) {
    while ($row = $extraLecturers->fetch_assoc()) {
        $uid = (int)$row['user_id'];
        $filtered = wuc_user_active_portals($db, $uid);
        $filteredCodes = implode(',', array_column($filtered, 'portal_code'));
        echo "  user {$uid} {$row['staff_id']} role={$row['primary_role']} db=[{$row['portals']}] filtered=[{$filteredCodes}]\n";
    }
}

// Count registered students still seeing applicant
$bad = 0;
$res = $db->query(
    "SELECT u.user_id, u.student_id
       FROM users u
      WHERE u.student_id IS NOT NULL AND u.student_id <> ''
      LIMIT 200"
);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $uid = (int)$row['user_id'];
        if (!wuc_user_is_fully_registered_student($db, $uid)) {
            continue;
        }
        $codes = array_column(wuc_user_active_portals($db, $uid), 'portal_code');
        if (in_array('applicant', $codes, true)) {
            $bad++;
            echo "FAIL: registered student {$row['student_id']} still sees applicant\n";
        }
    }
}
echo "\nregistered_students_with_applicant_visible: {$bad}\n";
echo "RESULT=" . ($bad === 0 ? 'OK' : 'FAIL') . "\n";
