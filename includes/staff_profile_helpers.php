<?php

if (!function_exists('wuc_get_staff_profile')) {
    function wuc_get_staff_profile(mysqli $db, string $staffId): ?object
    {
        if ($staffId === '') {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT staff_id, title, Fname, Lname, email, role, status
             FROM staff
             WHERE staff_id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $staffId);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_object() ?: null;
        $stmt->close();
        return $profile;
    }
}
