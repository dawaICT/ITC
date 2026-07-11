-- Preserve the complete filter/scope context used for generated ITC reports.
-- Existing report_logs columns remain in place for indexed summary queries.

ALTER TABLE report_logs
    ADD COLUMN IF NOT EXISTS filter_snapshot_json LONGTEXT NULL AFTER academic_period_id;

ALTER TABLE report_logs
    ADD COLUMN IF NOT EXISTS scope_snapshot_json LONGTEXT NULL AFTER filter_snapshot_json;
