<?php
/**
 * ITC Course Management helpers — the single source of truth for the
 * classification / duration / intake-type logic described in
 * ITC_Courses_Duration_Intake_Level_Logic.md.
 *
 * Reused by the admin catalogue UI (admin/short_courses.php), the AJAX
 * "Suggest" endpoint, the §15 seeder (scripts/seed_itc_catalogue.php) and the
 * intake/batch workflow. Keep the algorithms here so every caller agrees.
 *
 * Tables: course_categories, course_classification_levels, intake_types
 * (created by migrations/20260623_itc_course_management.sql).
 */

// ─── Duration conversion (§6) ─────────────────────────────────────────────
if (!function_exists('itc_duration_to_days')) {
    /**
     * Convert a structured duration into standard training days.
     * day=1, week=7, month=30, year=365. Returns null for unknown units.
     */
    function itc_duration_to_days(int $value, string $unit): ?int
    {
        if ($value <= 0) {
            return null;
        }
        switch (strtolower(trim($unit))) {
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

if (!function_exists('itc_duration_in_months')) {
    /** Whole-month equivalent of a duration, or null when not month-expressible. */
    function itc_duration_in_months(int $value, string $unit): ?int
    {
        switch (strtolower(trim($unit))) {
            case 'month':
            case 'months':
                return $value;
            case 'year':
            case 'years':
                return $value * 12;
            default:
                return null;
        }
    }
}

// ─── Level classification (§5) ────────────────────────────────────────────
if (!function_exists('itc_classify_level')) {
    /**
     * Suggest a classification level_code from the course name, duration and
     * (optionally) its category code. Mirrors the ClassifyCourseLevel algorithm
     * (§5) and additionally understands arabic "Level 1/2/3 Trade Test" naming
     * used by the real ITC catalogue.
     */
    function itc_classify_level(string $name, ?int $durationDays = null, ?string $categoryCode = null): string
    {
        $n = strtolower(trim($name));

        if (strpos($n, 'diploma') !== false) {
            return 'DIPLOMA';
        }
        if (strpos($n, 'technician cert') !== false) {
            return 'TECH_CERT';
        }
        if (strpos($n, 'craft cert') !== false || strpos($n, 'craft certificate') !== false) {
            return 'CRAFT_CERT';
        }

        // Trade test levels — accept "trade test level iii" and "level 3 ... trade test".
        $isTrade = (strpos($n, 'trade test') !== false) || (strpos($n, 'tevet') !== false);
        if ($isTrade) {
            if (preg_match('/level\s*(?:iii|3)\b/', $n)) {
                return 'TRADE_III';
            }
            if (preg_match('/level\s*(?:ii|2)\b/', $n)) {
                return 'TRADE_II';
            }
            if (preg_match('/level\s*(?:i|1)\b/', $n)) {
                return 'TRADE_I';
            }
        }

        if (strpos($n, 'refresher') !== false) {
            return 'REFRESHER';
        }
        if (strpos($n, 'advanced') !== false) {
            return 'ADVANCED';
        }
        if (strpos($n, 'basic') !== false) {
            return 'BASIC';
        }
        if ($durationDays !== null && $durationDays >= 1 && $durationDays <= 20) {
            return 'SHORT';
        }
        if ($categoryCode !== null && strtoupper($categoryCode) === 'SERV') {
            return 'SERVICE';
        }
        return 'CERTIFICATE';
    }
}

// ─── Intake-type suggestion (§8) ──────────────────────────────────────────
if (!function_exists('itc_suggest_intake_type')) {
    /**
     * Suggest an intake type_code from the (already chosen) level and duration.
     * Follows AssignIntakeType (§8) ordering exactly, so a 12-month diploma maps
     * to SEMESTER_BASED while a 24-month diploma maps to ANNUAL.
     */
    function itc_suggest_intake_type(string $levelCode, int $value, string $unit, ?int $durationDays = null): string
    {
        $level = strtoupper(trim($levelCode));
        $days  = $durationDays ?? itc_duration_to_days($value, $unit);
        $months = itc_duration_in_months($value, $unit);

        if ($level === 'SERVICE' || $level === 'ASSESSMENT') {
            return 'ON_DEMAND';
        }
        if ($days !== null) {
            if ($days <= 5) {
                return 'ROLLING';
            }
            if ($days <= 10) {
                return 'WEEKLY';
            }
            if ($days <= 20) {
                return 'MONTHLY';
            }
        }
        if ($level === 'TRADE_III' || $months === 3) {
            return 'TERM_BASED';
        }
        if ($level === 'TRADE_II' || $months === 6) {
            return 'TERM_BASED';
        }
        if ($level === 'TRADE_I' || $months === 12) {
            return 'SEMESTER_BASED';
        }
        if ($level === 'CERTIFICATE' || $level === 'TECH_CERT') {
            return 'SEMESTER_BASED';
        }
        if ($level === 'CRAFT_CERT' || $level === 'DIPLOMA') {
            return 'ANNUAL';
        }
        return 'MONTHLY';
    }
}

// ─── Publish guard (BR-COURSE-001 / BR-CAT-002) ───────────────────────────
if (!function_exists('itc_course_is_publishable')) {
    /**
     * A course may only be activated/published when it has a category, level,
     * duration and intake type. $c is an associative array or object of the
     * short_courses row (or the submitted form values).
     */
    function itc_course_is_publishable($c): bool
    {
        $get = static function ($k) use ($c) {
            if (is_array($c)) {
                return $c[$k] ?? null;
            }
            return is_object($c) && isset($c->$k) ? $c->$k : null;
        };
        $catId    = (int)($get('category_id') ?? 0);
        $levelId  = (int)($get('level_id') ?? 0);
        $intakeId = (int)($get('intake_type_id') ?? 0);
        $durVal   = (int)($get('duration_value') ?? 0);
        return $catId > 0 && $levelId > 0 && $intakeId > 0 && $durVal > 0;
    }
}

if (!function_exists('itc_missing_classification')) {
    /** Human-readable list of the classification fields still missing. */
    function itc_missing_classification($c): array
    {
        $get = static function ($k) use ($c) {
            if (is_array($c)) {
                return $c[$k] ?? null;
            }
            return is_object($c) && isset($c->$k) ? $c->$k : null;
        };
        $missing = [];
        if ((int)($get('category_id') ?? 0) <= 0)   { $missing[] = 'category'; }
        if ((int)($get('level_id') ?? 0) <= 0)       { $missing[] = 'level'; }
        if ((int)($get('intake_type_id') ?? 0) <= 0) { $missing[] = 'intake type'; }
        if ((int)($get('duration_value') ?? 0) <= 0) { $missing[] = 'duration'; }
        return $missing;
    }
}

// ─── Reference-table lookups (cached) ─────────────────────────────────────
if (!function_exists('itc_categories')) {
    /** All categories, ordered, as id => row. */
    function itc_categories(mysqli $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if ($res = @$db->query("SELECT id, category_code, category_name FROM course_categories WHERE is_active = 1 ORDER BY sort_order, category_name")) {
            while ($r = $res->fetch_assoc()) {
                $cache[(int)$r['id']] = $r;
            }
            $res->free();
        }
        return $cache;
    }
}

if (!function_exists('itc_levels')) {
    /** All classification levels, ordered by rank, as id => row. */
    function itc_levels(mysqli $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if ($res = @$db->query("SELECT id, level_code, level_name, level_rank FROM course_classification_levels ORDER BY level_rank, level_name")) {
            while ($r = $res->fetch_assoc()) {
                $cache[(int)$r['id']] = $r;
            }
            $res->free();
        }
        return $cache;
    }
}

if (!function_exists('itc_intake_types')) {
    /** All intake types as id => row. */
    function itc_intake_types(mysqli $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if ($res = @$db->query("SELECT id, type_code, type_name, min_days, max_days FROM intake_types ORDER BY id")) {
            while ($r = $res->fetch_assoc()) {
                $cache[(int)$r['id']] = $r;
            }
            $res->free();
        }
        return $cache;
    }
}

// ── code → id resolvers (used by the seeder and the AJAX suggest endpoint) ──
if (!function_exists('itc_category_id_by_code')) {
    function itc_category_id_by_code(mysqli $db, string $code): ?int
    {
        foreach (itc_categories($db) as $id => $row) {
            if (strcasecmp($row['category_code'], $code) === 0) {
                return (int)$id;
            }
        }
        return null;
    }
}

if (!function_exists('itc_level_id_by_code')) {
    function itc_level_id_by_code(mysqli $db, string $code): ?int
    {
        foreach (itc_levels($db) as $id => $row) {
            if (strcasecmp($row['level_code'], $code) === 0) {
                return (int)$id;
            }
        }
        return null;
    }
}

if (!function_exists('itc_intake_type_id_by_code')) {
    function itc_intake_type_id_by_code(mysqli $db, string $code): ?int
    {
        foreach (itc_intake_types($db) as $id => $row) {
            if (strcasecmp($row['type_code'], $code) === 0) {
                return (int)$id;
            }
        }
        return null;
    }
}

// ─── Batch end-date from course duration (§18) ────────────────────────────
if (!function_exists('itc_batch_end_date')) {
    /**
     * Compute a batch end date from its start + the course duration. Unlike
     * sc_derive_end_date() this also understands 'years' (diplomas / craft certs).
     * Returns Y-m-d, or null when inputs are unusable.
     */
    function itc_batch_end_date(?string $start, int $value, string $unit): ?string
    {
        $start = $start !== null ? trim($start) : '';
        $unit  = strtolower(trim($unit));
        if ($start === '' || $value <= 0 || !in_array($unit, ['days', 'weeks', 'months', 'years'], true)) {
            return null;
        }
        $startTs = strtotime($start);
        if ($startTs === false) {
            return null;
        }
        $endTs = strtotime("+{$value} {$unit}", $startTs);
        return $endTs ? date('Y-m-d', $endTs) : null;
    }
}

// ─── Intake scheduling helper (§16, Phase B) ──────────────────────────────
if (!function_exists('itc_default_intake_dates')) {
    /**
     * Suggest sensible application/training dates for a new intake, given the
     * intake type cadence and a preferred training start. All editable by admin.
     * Returns ['application_open_date','application_close_date','training_start_date'].
     */
    function itc_default_intake_dates(string $typeCode, string $trainingStart): array
    {
        $start = strtotime($trainingStart) ?: time();
        // Application window length scales loosely with programme cadence.
        $leadDays = [
            'ON_DEMAND' => 0,  'ROLLING' => 7,   'WEEKLY' => 10, 'MONTHLY' => 21,
            'TERM_BASED' => 30, 'SEMESTER_BASED' => 45, 'ANNUAL' => 60,
        ][strtoupper($typeCode)] ?? 14;

        $open  = strtotime("-" . ($leadDays + 7) . " days", $start);
        $close = strtotime("-3 days", $start);
        return [
            'application_open_date'  => date('Y-m-d', $open),
            'application_close_date' => date('Y-m-d', $close),
            'training_start_date'    => date('Y-m-d', $start),
        ];
    }
}
