<?php
/**
 * Lightweight lookup cache for stable reference data.
 *
 * Reference data — programmes, departments, roles, academic years/terms — is
 * read constantly (form <select>s, filters, labels) but changes rarely. Reading
 * it from the database on every request, sometimes several times per page, is
 * wasted work.
 *
 * wuc_cache_remember() provides a two-tier cache:
 *   1. Per-request static memo (always on): the producer runs at most once per
 *      request no matter how many times a page asks for the same lookup.
 *   2. Cross-request APCu cache (only when the APCu extension is enabled):
 *      the value survives between requests for $ttl seconds, so the query is
 *      skipped entirely on subsequent page loads.
 *
 * When APCu is unavailable (e.g. CLI, or a build without the extension) the
 * helper silently falls back to tier 1 only — still a net win, never a failure.
 *
 * Only static, parameter-free lookup queries belong here. Never cache
 * per-user or permission-scoped data through this helper (use the per-request
 * permission cache in permissions.php for that).
 */

if (!function_exists('wuc_cache_apcu_available')) {
    function wuc_cache_apcu_available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = function_exists('apcu_enabled') && function_exists('apcu_fetch') && @apcu_enabled();
        }
        return $ok;
    }
}

if (!function_exists('wuc_cache_remember')) {
    /**
     * Return a cached value, producing (and caching) it on a miss.
     *
     * @param string   $key      Stable cache key (namespaced internally).
     * @param callable $producer Zero-arg callable returning the value to cache.
     * @param int      $ttl      Cross-request TTL in seconds (APCu only).
     * @return mixed
     */
    function wuc_cache_remember(string $key, callable $producer, int $ttl = 300)
    {
        static $local = [];
        if (array_key_exists($key, $local)) {
            return $local[$key];
        }

        $apcuKey = 'wuc_lk_' . $key;
        if (wuc_cache_apcu_available()) {
            $found = false;
            $val = apcu_fetch($apcuKey, $found);
            if ($found) {
                return $local[$key] = $val;
            }
        }

        $val = $producer();

        if (wuc_cache_apcu_available()) {
            @apcu_store($apcuKey, $val, max(1, $ttl));
        }
        return $local[$key] = $val;
    }
}

if (!function_exists('wuc_cache_forget')) {
    /**
     * Invalidate a cross-request (APCu) cache entry. Call after an admin edits
     * the underlying reference data (e.g. adds a programme) so the next request
     * reloads it. The per-request static memo expires naturally at end of
     * request, so it needs no explicit clearing.
     */
    function wuc_cache_forget(string $key): void
    {
        if (wuc_cache_apcu_available()) {
            @apcu_delete('wuc_lk_' . $key);
        }
    }
}

/* -------------------------------------------------------------------------
 * Convenience getters (columns verified against the live schema).
 * Each returns a compact id => label map suitable for building <select>s.
 * ---------------------------------------------------------------------- */

if (!function_exists('wuc_lookup_programs')) {
    /** @return array<string,string> program_code => program_name (active only) */
    function wuc_lookup_programs(mysqli $db): array
    {
        return wuc_cache_remember('programs_active', static function () use ($db) {
            $out = [];
            if ($res = @$db->query(
                "SELECT program_code, program_name FROM programs
                 WHERE is_active = 1 ORDER BY program_name ASC"
            )) {
                while ($row = $res->fetch_assoc()) {
                    $out[(string)$row['program_code']] = (string)$row['program_name'];
                }
                $res->free();
            }
            return $out;
        }, 600);
    }
}

if (!function_exists('wuc_lookup_departments')) {
    /** @return array<int,string> id => department_name (active only) */
    function wuc_lookup_departments(mysqli $db): array
    {
        return wuc_cache_remember('departments_active', static function () use ($db) {
            $out = [];
            if ($res = @$db->query(
                "SELECT id, department_name FROM departments
                 WHERE status = 'active' OR status IS NULL
                 ORDER BY department_name ASC"
            )) {
                while ($row = $res->fetch_assoc()) {
                    $out[(int)$row['id']] = (string)$row['department_name'];
                }
                $res->free();
            }
            return $out;
        }, 600);
    }
}

if (!function_exists('wuc_lookup_roles')) {
    /** @return array<string,string> role_name => role_label (active only) */
    function wuc_lookup_roles(mysqli $db): array
    {
        return wuc_cache_remember('roles_active', static function () use ($db) {
            $out = [];
            if ($res = @$db->query(
                "SELECT role_name, role_label FROM roles
                 WHERE status = 'active' OR status IS NULL
                 ORDER BY role_label ASC"
            )) {
                while ($row = $res->fetch_assoc()) {
                    $out[(string)$row['role_name']] = (string)($row['role_label'] ?: $row['role_name']);
                }
                $res->free();
            }
            return $out;
        }, 900);
    }
}
