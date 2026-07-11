<?php
/**
 * Shared schema-flexible DB helpers for short-course features.
 *
 * Single definition consumed by both the admissions helpers
 * (admissions/includes/short_course_helpers.php) and the consolidated
 * enrollment action handler (includes/short_course_actions.php), so the two
 * short-course management modules no longer carry divergent copies.
 */

if (!function_exists('sc_identifier')) {
    function sc_identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }
        return '`' . $identifier . '`';
    }
}

if (!function_exists('sc_table_exists')) {
    function sc_table_exists(mysqli $db, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $cache[$table] = $exists;
    }
}

if (!function_exists('sc_columns')) {
    function sc_columns(mysqli $db, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        if (!sc_table_exists($db, $table)) {
            return $cache[$table] = [];
        }

        $columns = [];
        $result = $db->query('SHOW COLUMNS FROM ' . sc_identifier($table));
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }

        return $cache[$table] = $columns;
    }
}

if (!function_exists('sc_has_column')) {
    function sc_has_column(mysqli $db, string $table, string $column): bool
    {
        $columns = sc_columns($db, $table);
        return isset($columns[$column]);
    }
}

if (!function_exists('sc_insert')) {
    function sc_insert(mysqli $db, string $table, array $data): void
    {
        $columns = sc_columns($db, $table);
        $filtered = [];

        foreach ($data as $column => $value) {
            if (isset($columns[$column])) {
                $filtered[$column] = $value;
            }
        }

        if (!$filtered) {
            throw new RuntimeException("No compatible columns found for {$table}.");
        }

        $columnSql = implode(', ', array_map('sc_identifier', array_keys($filtered)));
        $placeholders = implode(', ', array_fill(0, count($filtered), '?'));
        $stmt = $db->prepare('INSERT INTO ' . sc_identifier($table) . " ({$columnSql}) VALUES ({$placeholders})");

        $values = array_values($filtered);
        $bindValues = [str_repeat('s', count($values))];
        foreach ($values as $index => $value) {
            $bindValues[] = &$values[$index];
        }

        $stmt->bind_param(...$bindValues);
        $stmt->execute();
        $stmt->close();
    }
}

if (!defined('SC_MAX_SHORT_COURSE_DAYS')) {
    define('SC_MAX_SHORT_COURSE_DAYS', 183);
}

if (!function_exists('sc_duration_to_days')) {
    function sc_duration_to_days($value, $unit): ?int
    {
        $value = (int)$value;
        if ($value <= 0) {
            return null;
        }

        switch (strtolower(trim((string)$unit))) {
            case 'day':
            case 'days':
                return $value;
            case 'week':
            case 'weeks':
                return $value * 7;
            case 'month':
            case 'months':
                return $value * 30;
            case 'year':
            case 'years':
                return $value * 365;
            default:
                return null;
        }
    }
}

if (!function_exists('sc_is_short_course_duration')) {
    function sc_is_short_course_duration($value, $unit): bool
    {
        $days = sc_duration_to_days($value, $unit);
        return $days !== null && sc_is_short_course_days($days);
    }
}

if (!function_exists('sc_is_short_course_days')) {
    function sc_is_short_course_days($days): bool
    {
        global $db;
        $days = (int)$days;
        
        $maxMonths = 6;
        if (isset($db) && $db instanceof mysqli) {
            require_once __DIR__ . '/academic_settings_helper.php';
            $maxMonths = (int)wuc_get_academic_setting($db, 'short_course_max_duration_months', '6');
        }
        $maxDays = $maxMonths * 30;
        if ($maxMonths === 6) {
            $maxDays = 183; // maintain precise legacy fallback for standard 6 months
        }
        
        return $days > 0 && $days <= $maxDays;
    }
}
