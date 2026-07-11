-- Decision Support System findings for Head of Section workspaces.
-- Detectors on hod/decision_support.php upsert rows here so review status
-- (pending / reviewed / resolved) survives page reloads.
-- Run as wucportal_migrator (the app user is DML-only).

CREATE TABLE IF NOT EXISTS hos_dss_findings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id VARCHAR(30) NOT NULL,
    finding_key VARCHAR(190) NOT NULL,
    category VARCHAR(60) NOT NULL,
    severity ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
    title VARCHAR(255) NOT NULL,
    detail TEXT NULL,
    affected_ref VARCHAR(190) NULL,
    recommended_action VARCHAR(255) NULL,
    responsible VARCHAR(120) NULL,
    status ENUM('pending','reviewed','resolved') NOT NULL DEFAULT 'pending',
    detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(50) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_section_finding (section_id, finding_key),
    KEY idx_section_status (section_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
