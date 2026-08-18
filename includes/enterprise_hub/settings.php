<?php
declare(strict_types=1);

if (!function_exists('eh_setting')) {
    function eh_setting(mysqli $db, string $key, ?string $default = null): ?string
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        // Env overrides for exhibition / AI
        if ($key === 'ai_enabled') {
            $env = getenv('ENTERPRISE_AI_ENABLED');
            if ($env !== false && $env !== '') {
                return $cache[$key] = in_array(strtolower(trim($env)), ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
            }
        }
        if ($key === 'exhibition_mode') {
            $env = getenv('ENTERPRISE_EXHIBITION_MODE');
            if ($env !== false && $env !== '') {
                return $cache[$key] = in_array(strtolower(trim($env)), ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
            }
            if (function_exists('wuc_exhibition_mode_enabled') && wuc_exhibition_mode_enabled($db)) {
                return $cache[$key] = 'true';
            }
        }

        $stmt = $db->prepare('SELECT setting_value FROM enterprise_settings WHERE setting_key = ? LIMIT 1');
        if (!$stmt) {
            return $default;
        }
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $val = $row['setting_value'] ?? $default;
        return $cache[$key] = is_string($val) ? $val : $default;
    }
}

if (!function_exists('eh_setting_bool')) {
    function eh_setting_bool(mysqli $db, string $key, bool $default = false): bool
    {
        $val = eh_setting($db, $key, $default ? 'true' : 'false');
        return in_array(strtolower(trim((string)$val)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('eh_set_setting')) {
    function eh_set_setting(mysqli $db, string $key, string $value): void
    {
        $stmt = $db->prepare("
            INSERT INTO enterprise_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('eh_ai_enabled')) {
    function eh_ai_enabled(mysqli $db): bool
    {
        return eh_setting_bool($db, 'ai_enabled', true);
    }
}

if (!function_exists('eh_exhibition_mode')) {
    function eh_exhibition_mode(mysqli $db): bool
    {
        return eh_setting_bool($db, 'exhibition_mode', false);
    }
}

if (!function_exists('eh_reset_demo_data')) {
    /**
     * Remove demonstration hub records (is_demo = 1) and re-apply seed SQL.
     * Restricted to systems administrators. Does not touch non-demo data.
     *
     * @return array{ok:bool, message:string}
     */
    function eh_reset_demo_data(mysqli $db): array
    {
        if (!eh_is_systems_admin()) {
            return ['ok' => false, 'message' => 'Only systems administrators can reset demonstration data.'];
        }

        $seedFile = dirname(__DIR__, 2) . '/database/enterprise_hub_seed.sql';
        if (!is_readable($seedFile)) {
            return ['ok' => false, 'message' => 'Seed file is not available.'];
        }

        $db->begin_transaction();
        try {
            // Delete demo-linked rows in dependency order
            $db->query("
                DELETE ii FROM enterprise_interests ii
                INNER JOIN enterprise_items i ON i.id = ii.enterprise_item_id
                WHERE i.is_demo = 1
            ");
            $db->query("
                DELETE m FROM enterprise_item_media m
                INNER JOIN enterprise_items i ON i.id = m.enterprise_item_id
                WHERE i.is_demo = 1
            ");
            $db->query("
                DELETE c FROM enterprise_costs c
                INNER JOIN enterprise_items i ON i.id = c.enterprise_item_id
                WHERE i.is_demo = 1
            ");
            $db->query("
                DELETE r FROM enterprise_readiness_assessments r
                INNER JOIN enterprise_items i ON i.id = r.enterprise_item_id
                WHERE i.is_demo = 1
            ");
            $db->query("
                DELETE rv FROM enterprise_reviews rv
                INNER JOIN enterprise_items i ON i.id = rv.enterprise_item_id
                WHERE i.is_demo = 1
            ");
            $db->query("DELETE FROM enterprise_items WHERE is_demo = 1");
            $db->query("DELETE FROM enterprise_profiles WHERE is_demo = 1");

            $sql = file_get_contents($seedFile);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('Could not read seed SQL.');
            }

            // Execute multi-statement seed (demo inserts are idempotent for categories)
            if (!$db->multi_query($sql)) {
                throw new RuntimeException('Seed reload failed: ' . $db->error);
            }
            do {
                if ($result = $db->store_result()) {
                    $result->free();
                }
            } while ($db->more_results() && $db->next_result());

            if ($db->errno) {
                throw new RuntimeException('Seed reload error: ' . $db->error);
            }

            $db->commit();
            eh_audit($db, 'enterprise_hub.demo_reset', ['by' => eh_current_actor_id()]);
            return ['ok' => true, 'message' => 'Demonstration data was reset and re-seeded successfully.'];
        } catch (Throwable $e) {
            $db->rollback();
            error_log('enterprise_hub demo reset failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Demo reset failed. Check server logs.'];
        }
    }
}
