ALTER TABLE semester_assessment
    ADD COLUMN IF NOT EXISTS status VARCHAR(24) NOT NULL DEFAULT 'Pending' AFTER Total_CA,
    ADD COLUMN IF NOT EXISTS approved_by VARCHAR(80) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER approved_by;

CREATE INDEX IF NOT EXISTS idx_semester_assessment_status
    ON semester_assessment (status, Year, semester);
