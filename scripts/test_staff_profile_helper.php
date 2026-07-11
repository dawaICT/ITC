<?php
require_once dirname(__DIR__) . '/db/connect.php';
require_once dirname(__DIR__) . '/includes/staff_profile_helpers.php';

$result = $db->query('SELECT staff_id FROM staff ORDER BY id LIMIT 1');
$row = $result ? $result->fetch_assoc() : null;
$staffId = (string) ($row['staff_id'] ?? '');
if ($staffId === '') {
    fwrite(STDERR, "FAIL: no staff record is available for the profile-helper test.\n");
    exit(1);
}

$profile = wuc_get_staff_profile($db, $staffId);
if (!$profile || (string) $profile->staff_id !== $staffId) {
    fwrite(STDERR, "FAIL: prepared profile lookup did not return the selected staff record.\n");
    exit(1);
}

if (wuc_get_staff_profile($db, '__missing_staff__') !== null) {
    fwrite(STDERR, "FAIL: missing staff lookup must return null.\n");
    exit(1);
}

echo "PASS: shared staff profile helper matches the live schema.\n";
