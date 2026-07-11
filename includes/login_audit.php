<?php
require_once __DIR__ . '/../db/connect.php';
function audit_login(mysqli $db, string $staff_id, string $status, string $ip = null) {
    static $tableReady = null;
    if ($tableReady === null) {
        $tableReady = $db->query("SHOW TABLES LIKE 'login_audit'")->num_rows === 1;
    }
    if (!$tableReady) {
        error_log('login_audit table is missing; run migrations.');
        return;
    }
    $stmt = $db->prepare("INSERT INTO login_audit (staff_id, status, ip) VALUES (?,?,?)");
    $stmt->bind_param('sss', $staff_id, $status, $ip);
    @$stmt->execute();
}
?>


