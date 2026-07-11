<?php
declare(strict_types=1);

/**
 * Enterprise foundations for WUCPortal.
 *
 * Additive only: central business rules, academic calendar, notifications,
 * approvals, documents, background jobs, academic record versions, and data
 * integrity exception tracking. Existing module tables and business logic are
 * not replaced.
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

function ent_table_exists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    $exists = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $exists;
}

function ent_column_exists(mysqli $db, string $table, string $column): bool
{
    $safeTable = $db->real_escape_string($table);
    $safeColumn = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    $exists = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $exists;
}

function ent_add_column(mysqli $db, string $table, string $column, string $definition): void
{
    if (!ent_column_exists($db, $table, $column)) {
        $db->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    }
}

$db->begin_transaction();

try {
    $db->query("
        CREATE TABLE IF NOT EXISTS business_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            rule_key VARCHAR(120) NOT NULL,
            module_name VARCHAR(80) NOT NULL DEFAULT 'system',
            scope_key VARCHAR(120) NULL,
            rule_value JSON NULL,
            description VARCHAR(255) NULL,
            effective_from DATE NULL,
            effective_to DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by VARCHAR(50) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_business_rule (rule_key, module_name, scope_key),
            KEY idx_business_rules_status (status, effective_from, effective_to)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS academic_calendar_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(120) NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            academic_year VARCHAR(20) NULL,
            period_label VARCHAR(40) NULL,
            title VARCHAR(160) NOT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            rules JSON NULL,
            created_by VARCHAR(50) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_calendar_event (event_key),
            KEY idx_calendar_lookup (event_type, academic_year, status, starts_at, ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            recipient_user_id INT NULL,
            recipient_student_id VARCHAR(50) NULL,
            recipient_staff_id VARCHAR(50) NULL,
            channel VARCHAR(30) NOT NULL DEFAULT 'internal',
            module_name VARCHAR(80) NOT NULL DEFAULT 'system',
            title VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_by VARCHAR(50) NULL,
            metadata JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_notifications_user (recipient_user_id, status),
            KEY idx_notifications_student (recipient_student_id, status),
            KEY idx_notifications_staff (recipient_staff_id, status),
            KEY idx_notifications_due (channel, status, scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS approval_workflows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            workflow_key VARCHAR(120) NOT NULL,
            module_name VARCHAR(80) NOT NULL,
            action_name VARCHAR(120) NOT NULL,
            steps JSON NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by VARCHAR(50) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_workflow_key (workflow_key),
            KEY idx_workflow_lookup (module_name, action_name, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS approval_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            workflow_id BIGINT UNSIGNED NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(80) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            current_step INT NOT NULL DEFAULT 1,
            requested_by VARCHAR(50) NOT NULL,
            payload JSON NULL,
            decided_by VARCHAR(50) NULL,
            decided_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_open_approval (workflow_id, entity_type, entity_id, status),
            KEY idx_approval_status (status, current_step),
            CONSTRAINT fk_approval_request_workflow FOREIGN KEY (workflow_id)
                REFERENCES approval_workflows(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS approval_actions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id BIGINT UNSIGNED NOT NULL,
            step_no INT NOT NULL,
            action_taken VARCHAR(30) NOT NULL,
            acted_by VARCHAR(50) NOT NULL,
            comments TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_approval_actions_request (request_id, step_no),
            CONSTRAINT fk_approval_actions_request FOREIGN KEY (request_id)
                REFERENCES approval_requests(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS document_repository (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_type VARCHAR(40) NOT NULL,
            owner_id VARCHAR(80) NOT NULL,
            document_type VARCHAR(80) NOT NULL,
            original_name VARCHAR(255) NULL,
            file_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) NULL,
            file_size BIGINT UNSIGNED NULL,
            checksum_sha256 CHAR(64) NULL,
            visibility VARCHAR(30) NOT NULL DEFAULT 'private',
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            uploaded_by VARCHAR(50) NULL,
            metadata JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_documents_owner (owner_type, owner_id, document_type, status),
            KEY idx_documents_checksum (checksum_sha256)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS data_integrity_exceptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            exception_key VARCHAR(160) NOT NULL,
            module_name VARCHAR(80) NOT NULL DEFAULT 'system',
            severity VARCHAR(20) NOT NULL DEFAULT 'medium',
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(100) NULL,
            message TEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            metadata JSON NULL,
            detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            resolved_by VARCHAR(50) NULL,
            UNIQUE KEY uq_exception_key (exception_key),
            KEY idx_integrity_status (status, severity, module_name),
            KEY idx_integrity_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS background_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            job_type VARCHAR(100) NOT NULL,
            payload JSON NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_at DATETIME NULL,
            completed_at DATETIME NULL,
            last_error TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_jobs_due (status, available_at),
            KEY idx_jobs_type (job_type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS academic_record_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(100) NOT NULL,
            version_no INT NOT NULL,
            change_reason VARCHAR(255) NULL,
            old_value JSON NULL,
            new_value JSON NULL,
            changed_by VARCHAR(50) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_record_version (entity_type, entity_id, version_no),
            KEY idx_record_versions_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (ent_table_exists($db, 'audit_logs')) {
        ent_add_column($db, 'audit_logs', 'role_name', "role_name VARCHAR(80) NULL AFTER module");
        ent_add_column($db, 'audit_logs', 'session_id', "session_id VARCHAR(128) NULL AFTER role_name");
        ent_add_column($db, 'audit_logs', 'browser', "browser VARCHAR(120) NULL AFTER user_agent");
        ent_add_column($db, 'audit_logs', 'previous_hash', "previous_hash CHAR(64) NULL AFTER browser");
        ent_add_column($db, 'audit_logs', 'event_hash', "event_hash CHAR(64) NULL AFTER previous_hash");
        if (!ent_column_exists($db, 'audit_logs', 'locked_at')) {
            $db->query("ALTER TABLE audit_logs ADD COLUMN locked_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER event_hash");
        }
    }

    $stmt = $db->prepare("
        INSERT INTO business_rules (rule_key, module_name, scope_key, rule_value, description, status, created_by)
        VALUES (?, ?, ?, ?, ?, 'active', 'migration')
        ON DUPLICATE KEY UPDATE rule_value = VALUES(rule_value), description = VALUES(description), status = 'active'
    ");
    $rules = [
        ['assessment.ca.minimum_payment_percent', 'assessment', 'default', '{"percent":50}', 'Minimum payment required before CA marks can be saved.'],
        ['assessment.exam.minimum_payment_percent', 'assessment', 'default', '{"percent":100}', 'Minimum payment required before test or exam marks can be saved.'],
        ['programme.default_pass_mark', 'academics', 'default', '{"percent":50}', 'Default pass mark when no programme-specific rule is configured.'],
        ['notification.default_channels', 'notification', 'default', '{"channels":["internal"]}', 'Default delivery channels for system notifications.'],
    ];
    foreach ($rules as $rule) {
        $stmt->bind_param('sssss', $rule[0], $rule[1], $rule[2], $rule[3], $rule[4]);
        $stmt->execute();
    }
    $stmt->close();

    $stmt = $db->prepare("
        INSERT INTO approval_workflows (workflow_key, module_name, action_name, steps, status, created_by)
        VALUES (?, ?, ?, ?, 'active', 'migration')
        ON DUPLICATE KEY UPDATE steps = VALUES(steps), status = 'active'
    ");
    $workflows = [
        ['ca_upload_hos_review', 'assessment', 'CA Upload Approval', '[{"step":1,"role":"lecturer","action":"submit"},{"step":2,"role":"head_of_section","action":"approve"}]'],
        ['results_publication', 'results', 'Results Publication', '[{"step":1,"role":"lecturer","action":"submit"},{"step":2,"role":"head_of_section","action":"approve"},{"step":3,"role":"registrar","action":"publish"}]'],
        ['student_registration', 'registration', 'Student Registration', '[{"step":1,"role":"student","action":"request"},{"step":2,"role":"registrar","action":"approve"}]'],
    ];
    foreach ($workflows as $workflow) {
        $stmt->bind_param('ssss', $workflow[0], $workflow[1], $workflow[2], $workflow[3]);
        $stmt->execute();
    }
    $stmt->close();

    $db->commit();
    echo "Enterprise foundations are ready.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "Enterprise foundations migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
