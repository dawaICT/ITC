<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db/connect.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function notification_column_exists(mysqli $db, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="portal_alerts" AND COLUMN_NAME=? LIMIT 1'
    );
    $stmt->bind_param('s', $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $exists;
}

try {
    if (!notification_column_exists($db, 'source_portal')) {
        $db->query("ALTER TABLE portal_alerts ADD COLUMN source_portal VARCHAR(32) NULL AFTER user_role");
    }
    if (!notification_column_exists($db, 'target_portal')) {
        $db->query("ALTER TABLE portal_alerts ADD COLUMN target_portal VARCHAR(32) NULL AFTER source_portal");
    }
    if (!notification_column_exists($db, 'target_page')) {
        $db->query("ALTER TABLE portal_alerts ADD COLUMN target_page VARCHAR(500) NULL AFTER action_url");
    }
    if (!notification_column_exists($db, 'expires_at')) {
        $db->query("ALTER TABLE portal_alerts ADD COLUMN expires_at DATETIME NULL AFTER created_at");
    }

    $index = $db->query("SHOW INDEX FROM portal_alerts WHERE Key_name='idx_portal_alert_recipient_context'");
    if ($index->num_rows === 0) {
        $db->query('CREATE INDEX idx_portal_alert_recipient_context ON portal_alerts (user_id, target_portal, status, expires_at)');
    }
    $index->free();

    $db->query(
        "UPDATE portal_alerts SET
            target_page = COALESCE(target_page, action_url),
            source_portal = COALESCE(source_portal,
                CASE
                    WHEN action_url LIKE '%/elearning/%' THEN 'elearning'
                    WHEN action_url LIKE '%/employer/%' THEN 'employer'
                    WHEN action_url LIKE '%/alumni/%' THEN 'alumni'
                    WHEN action_url LIKE '%/library/%' THEN 'library'
                    WHEN action_url LIKE '%/applicant_portal.php%' THEN 'applicant'
                    ELSE 'academic'
                END),
            target_portal = COALESCE(target_portal,
                CASE
                    WHEN action_url LIKE '%/elearning/%' THEN 'elearning'
                    WHEN action_url LIKE '%/employer/%' THEN 'employer'
                    WHEN action_url LIKE '%/alumni/%' THEN 'alumni'
                    WHEN action_url LIKE '%/library/%' THEN 'library'
                    WHEN action_url LIKE '%/applicant_portal.php%' THEN 'applicant'
                    ELSE 'academic'
                END)"
    );

    echo "portal_alerts now records source portal, target portal/page, and expiry.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Notification context migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
