-- Align audit.view with its own module so hasPermission($staffId, 'audit.view') works.

INSERT INTO modules (module_name, module_key, module_url, module_icon, display_order, status)
VALUES ('Audit Logs', 'audit', 'admin/audit_logs.php', 'fas fa-shield-alt', 95, 'active')
ON DUPLICATE KEY UPDATE
    module_name = VALUES(module_name),
    module_url = VALUES(module_url),
    module_icon = VALUES(module_icon),
    status = 'active';

UPDATE role_permissions rp
JOIN permissions p ON p.permission_id = rp.permission_id
JOIN modules m ON m.module_key = 'audit'
SET rp.module_id = m.module_id,
    rp.status = 'active'
WHERE p.permission_key = 'audit.view';

INSERT INTO schema_migrations (migration, applied_at)
SELECT '20260629_itc_audit_view_permission_module.sql', NOW()
WHERE NOT EXISTS (
    SELECT 1
    FROM schema_migrations
    WHERE migration = '20260629_itc_audit_view_permission_module.sql'
);
