<?php
/**
 * Lightweight performance monitoring for the legacy PHP app.
 *
 * Opt-in: set WUC_PERF_LOG=1 (or true/on/yes) to write request timing to the
 * configured error log. Slow requests (>= WUC_PERF_SLOW_MS, default 800) are
 * always logged once per request at shutdown — even when the verbose flag is
 * off — so production can spot regressions without flooding the log.
 *
 * Never logs passwords, tokens, cookies, or request bodies.
 */

if (!function_exists('wuc_perf_enabled')) {
    function wuc_perf_enabled(): bool
    {
        static $on = null;
        if ($on !== null) {
            return $on;
        }
        $flag = strtolower(trim((string)(
            (function_exists('wuc_portal_env') ? wuc_portal_env('WUC_PERF_LOG', '') : null)
            ?? getenv('WUC_PERF_LOG')
            ?: ''
        )));
        return $on = in_array($flag, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('wuc_perf_slow_ms')) {
    function wuc_perf_slow_ms(): int
    {
        $raw = function_exists('wuc_portal_env')
            ? wuc_portal_env('WUC_PERF_SLOW_MS', '800')
            : (getenv('WUC_PERF_SLOW_MS') ?: '800');
        $ms = (int)$raw;
        return $ms > 0 ? $ms : 800;
    }
}

if (!function_exists('wuc_perf_boot')) {
    function wuc_perf_boot(): void
    {
        if (!empty($GLOBALS['wuc_perf_booted'])) {
            return;
        }
        $GLOBALS['wuc_perf_booted'] = true;
        $GLOBALS['wuc_perf_t0'] = hrtime(true);
        $GLOBALS['wuc_perf_marks'] = [];
        $GLOBALS['wuc_perf_sql'] = ['count' => 0, 'slow' => []];

        register_shutdown_function(static function (): void {
            if (empty($GLOBALS['wuc_perf_t0'])) {
                return;
            }
            $ms = (hrtime(true) - (int)$GLOBALS['wuc_perf_t0']) / 1e6;
            $slowMs = wuc_perf_slow_ms();
            $verbose = wuc_perf_enabled();
            if (!$verbose && $ms < $slowMs) {
                return;
            }

            $uri = (string)($_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli' : '/'));
            // Strip query string values that may contain tokens; keep path only.
            $path = parse_url($uri, PHP_URL_PATH) ?: $uri;
            $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI');
            $sqlCount = (int)($GLOBALS['wuc_perf_sql']['count'] ?? 0);
            $mem = memory_get_peak_usage(true);
            $status = http_response_code() ?: 0;

            $extra = '';
            $slowSql = $GLOBALS['wuc_perf_sql']['slow'] ?? [];
            if (is_array($slowSql) && $slowSql !== []) {
                $parts = [];
                foreach (array_slice($slowSql, 0, 5) as $row) {
                    $parts[] = sprintf('%sms:%s', $row['ms'], $row['preview']);
                }
                $extra = ' slow_sql=[' . implode('; ', $parts) . ']';
            }

            error_log(sprintf(
                'WUC_PERF method=%s path=%s status=%d time_ms=%.1f sql=%d mem_peak=%d%s',
                $method,
                $path,
                (int)$status,
                $ms,
                $sqlCount,
                $mem,
                $extra
            ));
        });
    }
}

if (!function_exists('wuc_perf_mark')) {
    function wuc_perf_mark(string $label): void
    {
        if (empty($GLOBALS['wuc_perf_t0'])) {
            return;
        }
        $GLOBALS['wuc_perf_marks'][$label] = (hrtime(true) - (int)$GLOBALS['wuc_perf_t0']) / 1e6;
    }
}

if (!function_exists('wuc_perf_sql_observe')) {
    /**
     * Record a completed SQL call for slow-query summaries.
     * Pass a short, non-sensitive preview (no bound values with PII).
     */
    function wuc_perf_sql_observe(float $ms, string $preview = ''): void
    {
        if (empty($GLOBALS['wuc_perf_sql'])) {
            $GLOBALS['wuc_perf_sql'] = ['count' => 0, 'slow' => []];
        }
        $GLOBALS['wuc_perf_sql']['count']++;
        if ($ms < 100) {
            return;
        }
        $clean = preg_replace('/\s+/', ' ', trim($preview)) ?? '';
        $clean = substr($clean, 0, 120);
        $GLOBALS['wuc_perf_sql']['slow'][] = [
            'ms' => (int)round($ms),
            'preview' => $clean,
        ];
    }
}

if (!function_exists('wuc_perf_wrap_mysqli')) {
    /**
     * Reserved hook — intentional no-op. Wrapping mysqli globally is fragile
     * under exception mode; callers should use wuc_perf_sql_observe() around
     * known hot queries when profiling. Boot still starts the request timer.
     */
    function wuc_perf_wrap_mysqli(mysqli $db): void
    {
        wuc_perf_boot();
    }
}

wuc_perf_boot();
