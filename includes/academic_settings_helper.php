<?php
/**
 * Academic Settings Helper Functions
 * 
 * Provides access to key-value settings from the `academic_settings` table.
 */

/**
 * Canonical academic year dropdown helper.
 *
 * Returns an array of 4-digit calendar year strings (e.g. ['2026','2025'])
 * suitable for <select> option values. Sources in priority order:
 *   1. course_registration.academic_year  (dedicated calendar column, if present)
 *   2. course_registration.Year           (filtered to >= 1990 to exclude year-of-study 1–4)
 *   3. Hardcoded fallback: current year and the two preceding years.
 *
 * Only 4-digit values are returned — values like "1", "2", "2025/2026" are rejected.
 * The returned array is sorted newest-first and has no duplicates.
 */
if (!function_exists('wuc_academic_year_options')) {
    function wuc_academic_year_options(mysqli $db): array
    {
        // Per-request memo: the academic-year list is stable within a request
        // yet this helper is called once per year <select> (filter forms,
        // report forms, registration forms can render several). Cache it so the
        // SHOW COLUMNS probe and DISTINCT scan run at most once per request.
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $years = [];

        // Detect dedicated academic_year column.
        $ayColExists = false;
        if ($r = @$db->query("SHOW COLUMNS FROM course_registration LIKE 'academic_year'")) {
            $ayColExists = $r->num_rows > 0;
            $r->free();
        }

        $sql = $ayColExists
            ? "SELECT DISTINCT academic_year AS yr FROM course_registration
               WHERE academic_year IS NOT NULL AND academic_year <> ''
               ORDER BY academic_year DESC"
            : "SELECT DISTINCT Year AS yr FROM course_registration
               WHERE Year IS NOT NULL AND Year <> '' AND CAST(Year AS UNSIGNED) >= 1990
               ORDER BY Year DESC";

        if ($res = @$db->query($sql)) {
            while ($row = $res->fetch_assoc()) {
                $v = trim((string)($row['yr'] ?? ''));
                // Accept 4-digit years only (bare YYYY format).
                if (preg_match('/^\d{4}$/', $v) && !in_array($v, $years, true)) {
                    $years[] = $v;
                }
            }
            $res->free();
        }

        if (empty($years)) {
            $y = (int)date('Y');
            $years = [(string)$y, (string)($y - 1), (string)($y - 2)];
        }

        return $cached = $years;
    }
}

if (!function_exists('wuc_get_academic_setting')) {
    /**
     * Get a setting value from the academic_settings table.
     * Caches all settings in a static variable on the first call.
     *
     * @param mysqli $db Database connection
     * @param string $key Setting key
     * @param string $default Default value if key is not found
     * @return string Setting value
     */
    function wuc_get_academic_setting(mysqli $db, string $key, string $default = ''): string
    {
        static $settings = null;
        if ($settings === null) {
            $settings = [];
            $result = @$db->query("SELECT setting_key, setting_value FROM academic_settings");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
                $result->free();
            }
        }
        return $settings[$key] ?? $default;
    }
}
?>
