<?php
/**
 * Phase 1: controlled AI learning, lecturer escalation, review and usage audit.
 *
 * Usage:
 *   php migrations/20260718_ai_learning_bridge.php --dry-run
 *   php migrations/20260718_ai_learning_bridge.php --apply
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../db/connect.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$apply = in_array('--apply', $argv ?? [], true);
if (!$dryRun && !$apply) {
    fwrite(STDERR, "Usage: php migrations/20260718_ai_learning_bridge.php [--dry-run|--apply]\n");
    exit(1);
}

$statements = [
    'ai_conversations' => "CREATE TABLE IF NOT EXISTS ai_conversations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(50) NOT NULL,
        user_role VARCHAR(30) NOT NULL,
        student_id VARCHAR(50) NULL,
        course_id VARCHAR(20) NOT NULL,
        academic_period_id INT NULL,
        active_portal VARCHAR(40) NOT NULL DEFAULT 'student_academic',
        title VARCHAR(180) NULL,
        context_summary TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_message_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ai_conv_user (user_id), INDEX idx_ai_conv_student (student_id),
        INDEX idx_ai_conv_course (course_id), INDEX idx_ai_conv_period (academic_period_id),
        INDEX idx_ai_conv_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_messages' => "CREATE TABLE IF NOT EXISTS ai_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NOT NULL,
        sender_role VARCHAR(30) NOT NULL,
        message_type VARCHAR(30) NOT NULL DEFAULT 'chat',
        content MEDIUMTEXT NOT NULL,
        explanation_level VARCHAR(30) NULL,
        source_status VARCHAR(40) NULL,
        confidence DECIMAL(5,4) NULL,
        lecturer_confirmation_recommended TINYINT(1) NOT NULL DEFAULT 0,
        model_name VARCHAR(100) NULL,
        prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        content_status VARCHAR(20) NOT NULL DEFAULT 'draft',
        question_hash CHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ai_msg_conversation (conversation_id), INDEX idx_ai_msg_hash (question_hash),
        INDEX idx_ai_msg_status (content_status), INDEX idx_ai_msg_created (created_at),
        CONSTRAINT fk_ai_msg_conversation FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_message_sources' => "CREATE TABLE IF NOT EXISTS ai_message_sources (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        message_id BIGINT UNSIGNED NOT NULL,
        source_type VARCHAR(40) NOT NULL,
        source_id VARCHAR(80) NULL,
        title VARCHAR(255) NOT NULL,
        reference_url VARCHAR(500) NULL,
        excerpt TEXT NULL,
        priority_rank TINYINT UNSIGNED NOT NULL DEFAULT 6,
        verification_status VARCHAR(30) NOT NULL DEFAULT 'unverified',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ai_source_message (message_id),
        CONSTRAINT fk_ai_source_message FOREIGN KEY (message_id) REFERENCES ai_messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_feedback' => "CREATE TABLE IF NOT EXISTS ai_feedback (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        message_id BIGINT UNSIGNED NOT NULL,
        user_id VARCHAR(50) NOT NULL,
        rating VARCHAR(20) NOT NULL,
        comment VARCHAR(1000) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ai_feedback_user_message (message_id, user_id), INDEX idx_ai_feedback_user (user_id),
        CONSTRAINT fk_ai_feedback_message FOREIGN KEY (message_id) REFERENCES ai_messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_usage_logs' => "CREATE TABLE IF NOT EXISTS ai_usage_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(50) NOT NULL, department_id VARCHAR(80) NULL, course_id VARCHAR(20) NULL,
        conversation_id BIGINT UNSIGNED NULL, request_type VARCHAR(30) NOT NULL, model_name VARCHAR(100) NOT NULL,
        prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0, completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        estimated_cost DECIMAL(12,6) NOT NULL DEFAULT 0, latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
        cache_hit TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ai_usage_user (user_id), INDEX idx_ai_usage_course (course_id),
        INDEX idx_ai_usage_department (department_id), INDEX idx_ai_usage_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_audit_logs' => "CREATE TABLE IF NOT EXISTS ai_audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        actor_id VARCHAR(50) NOT NULL, actor_role VARCHAR(30) NOT NULL, action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50) NULL, entity_id VARCHAR(80) NULL, course_id VARCHAR(20) NULL,
        ip_address VARCHAR(45) NULL, metadata LONGTEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ai_audit_actor (actor_id), INDEX idx_ai_audit_course (course_id), INDEX idx_ai_audit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_course_settings' => "CREATE TABLE IF NOT EXISTS ai_course_settings (
        course_id VARCHAR(20) PRIMARY KEY, is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        default_ai_mode VARCHAR(40) NOT NULL DEFAULT 'full_tutoring', allow_external_knowledge TINYINT(1) NOT NULL DEFAULT 0,
        max_prompt_tokens INT UNSIGNED NOT NULL DEFAULT 1800, max_response_tokens INT UNSIGNED NOT NULL DEFAULT 700,
        per_user_daily_tokens INT UNSIGNED NOT NULL DEFAULT 30000, department_monthly_tokens INT UNSIGNED NOT NULL DEFAULT 1000000,
        updated_by VARCHAR(50) NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_assessment_settings' => "CREATE TABLE IF NOT EXISTS ai_assessment_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, assessment_id VARCHAR(80) NOT NULL, course_id VARCHAR(20) NOT NULL,
        ai_mode VARCHAR(40) NOT NULL DEFAULT 'disabled', updated_by VARCHAR(50) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ai_assessment (assessment_id, course_id), INDEX idx_ai_assessment_course (course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'knowledge_documents' => "CREATE TABLE IF NOT EXISTS knowledge_documents (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL,
        document_type VARCHAR(40) NOT NULL, source_path VARCHAR(1000) NULL, access_level VARCHAR(30) NOT NULL DEFAULT 'course',
        status VARCHAR(20) NOT NULL DEFAULT 'draft', created_by VARCHAR(50) NOT NULL, approved_by VARCHAR(50) NULL,
        approved_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_knowledge_course (course_id), INDEX idx_knowledge_status (status), INDEX idx_knowledge_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'knowledge_document_versions' => "CREATE TABLE IF NOT EXISTS knowledge_document_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_id BIGINT UNSIGNED NOT NULL, version_no INT UNSIGNED NOT NULL,
        checksum_sha256 CHAR(64) NULL, extraction_status VARCHAR(30) NOT NULL DEFAULT 'pending', created_by VARCHAR(50) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_knowledge_version (document_id, version_no),
        CONSTRAINT fk_knowledge_version_document FOREIGN KEY (document_id) REFERENCES knowledge_documents(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'knowledge_chunks' => "CREATE TABLE IF NOT EXISTS knowledge_chunks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, document_version_id BIGINT UNSIGNED NOT NULL, course_id VARCHAR(20) NOT NULL,
        chunk_index INT UNSIGNED NOT NULL, heading VARCHAR(255) NULL, content TEXT NOT NULL, token_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_knowledge_chunk_course (course_id),
        UNIQUE KEY uniq_knowledge_chunk (document_version_id, chunk_index),
        FULLTEXT KEY ft_knowledge_chunk (heading, content),
        CONSTRAINT fk_knowledge_chunk_version FOREIGN KEY (document_version_id) REFERENCES knowledge_document_versions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'verified_answers' => "CREATE TABLE IF NOT EXISTS verified_answers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(20) NOT NULL, topic VARCHAR(255) NULL,
        question TEXT NOT NULL, question_hash CHAR(64) NOT NULL, answer MEDIUMTEXT NOT NULL, source_message_id BIGINT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'approved', verified_by VARCHAR(50) NOT NULL, verified_at DATETIME NOT NULL,
        use_count INT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_verified_course_question (course_id, question_hash), INDEX idx_verified_course (course_id),
        INDEX idx_verified_status (status), INDEX idx_verified_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_content_reviews' => "CREATE TABLE IF NOT EXISTS ai_content_reviews (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, message_id BIGINT UNSIGNED NOT NULL, course_id VARCHAR(20) NOT NULL,
        reviewer_id VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', review_notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ai_review_message (message_id), INDEX idx_ai_review_course (course_id), INDEX idx_ai_review_status (status),
        CONSTRAINT fk_ai_review_message FOREIGN KEY (message_id) REFERENCES ai_messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'support_cases' => "CREATE TABLE IF NOT EXISTS support_cases (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id VARCHAR(50) NOT NULL, course_id VARCHAR(20) NOT NULL,
        academic_period_id INT NULL, lecturer_id VARCHAR(20) NULL, hod_id VARCHAR(20) NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        original_question TEXT NOT NULL, ai_response MEDIUMTEXT NOT NULL, sources_used LONGTEXT NULL, student_attempt TEXT NULL,
        priority VARCHAR(20) NOT NULL DEFAULT 'normal', status VARCHAR(30) NOT NULL DEFAULT 'open',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, due_at DATETIME NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, resolved_at DATETIME NULL,
        INDEX idx_support_student (student_id), INDEX idx_support_lecturer (lecturer_id), INDEX idx_support_course (course_id),
        INDEX idx_support_period (academic_period_id), INDEX idx_support_conversation (conversation_id),
        INDEX idx_support_status (status), INDEX idx_support_created (created_at),
        CONSTRAINT fk_support_conversation FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'support_case_messages' => "CREATE TABLE IF NOT EXISTS support_case_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, support_case_id BIGINT UNSIGNED NOT NULL,
        sender_id VARCHAR(50) NOT NULL, sender_role VARCHAR(30) NOT NULL, message MEDIUMTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_support_msg_case (support_case_id),
        CONSTRAINT fk_support_msg_case FOREIGN KEY (support_case_id) REFERENCES support_cases(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'support_case_assignments' => "CREATE TABLE IF NOT EXISTS support_case_assignments (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, support_case_id BIGINT UNSIGNED NOT NULL,
        assigned_to_id VARCHAR(50) NOT NULL, assigned_to_role VARCHAR(30) NOT NULL, assigned_by VARCHAR(50) NOT NULL,
        is_current TINYINT(1) NOT NULL DEFAULT 1, assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ended_at DATETIME NULL,
        INDEX idx_support_assignment_case (support_case_id), INDEX idx_support_assignment_user (assigned_to_id),
        CONSTRAINT fk_support_assignment_case FOREIGN KEY (support_case_id) REFERENCES support_cases(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'ai_response_cache' => "CREATE TABLE IF NOT EXISTS ai_response_cache (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, course_id VARCHAR(20) NOT NULL, question_hash CHAR(64) NOT NULL,
        explanation_level VARCHAR(30) NOT NULL, answer MEDIUMTEXT NOT NULL, source_status VARCHAR(40) NOT NULL,
        sources LONGTEXT NULL, model_name VARCHAR(100) NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ai_cache (course_id, question_hash, explanation_level), INDEX idx_ai_cache_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($statements as $table => $sql) {
    $exists = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'")->num_rows > 0;
    echo '[' . ($exists ? 'present' : ($dryRun ? 'would-create' : 'create')) . "] {$table}\n";
    if ($apply) {
        $db->query($sql);
    }
}

echo $dryRun ? "Dry run complete.\n" : "AI learning bridge migration complete.\n";
