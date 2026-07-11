-- Zero-cost AI foundations (2026-07-02)
-- Additive only. Apply as wucportal_migrator (or root on dev):
--   C:\xampp\mysql\bin\mysql.exe -u root wucportal < migrations\20260702_zero_cost_ai_foundations.sql
-- The app user (wucportal_app) already holds DML on wucportal.*, so no new grants needed.

-- 1. Unified portal alerts (dashboard alert hub, no SMS/email required)
CREATE TABLE IF NOT EXISTS portal_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    user_role VARCHAR(50) NOT NULL,
    alert_type VARCHAR(80) NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id VARCHAR(50) NULL,
    action_url VARCHAR(500) NULL,
    status ENUM('unread','read','dismissed') NOT NULL DEFAULT 'unread',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    KEY idx_user_status (user_id, status),
    KEY idx_type_created (alert_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Rule-based recommendations (explainable)
CREATE TABLE IF NOT EXISTS ai_recommendations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_user_id VARCHAR(50) NOT NULL,
    target_role VARCHAR(50) NOT NULL,
    recommendation_type VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id VARCHAR(50) NULL,
    score DECIMAL(5,2) NULL,
    reasons_json JSON NOT NULL,
    status ENUM('active','accepted','dismissed','expired') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    KEY idx_target (target_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. FAQ / knowledge base (zero-cost chatbot layer)
CREATE TABLE IF NOT EXISTS faq_knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(80) NOT NULL,
    intent_key VARCHAR(80) NOT NULL,
    keywords TEXT NOT NULL,
    question VARCHAR(500) NOT NULL,
    answer_template TEXT NOT NULL,
    roles_allowed VARCHAR(200) NOT NULL DEFAULT 'all',
    priority INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_intent (intent_key),
    KEY idx_category (category, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Structured report insights
CREATE TABLE IF NOT EXISTS report_insights (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_key VARCHAR(80) NOT NULL,
    scope_type VARCHAR(50) NOT NULL,
    scope_id VARCHAR(50) NULL,
    period_label VARCHAR(100) NULL,
    summary_json JSON NOT NULL,
    warnings_json JSON NULL,
    trends_json JSON NULL,
    actions_json JSON NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by VARCHAR(50) NULL,
    KEY idx_report_scope (report_key, scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Search analytics (recent searches for smart search)
CREATE TABLE IF NOT EXISTS search_recent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    user_role VARCHAR(50) NOT NULL,
    query_text VARCHAR(500) NOT NULL,
    result_count INT NOT NULL DEFAULT 0,
    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_searched (user_id, searched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Lecturer task alerts (missing CA uploads, pending grading, etc.)
CREATE TABLE IF NOT EXISTS lecturer_task_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(20) NOT NULL,
    task_type VARCHAR(80) NOT NULL,
    course_code VARCHAR(30) NULL,
    due_hint VARCHAR(100) NULL,
    message TEXT NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    status ENUM('open','done','dismissed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_staff_status (staff_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Automated decision audit (batch runs, alert creation, rule outcomes)
CREATE TABLE IF NOT EXISTS ai_decision_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feature VARCHAR(80) NOT NULL,
    decision_type VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id VARCHAR(50) NULL,
    input_summary VARCHAR(500) NULL,
    outcome VARCHAR(200) NOT NULL,
    reasons_json JSON NULL,
    user_id VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_feature_created (feature, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
