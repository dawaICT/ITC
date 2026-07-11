<?php
/**
 * Runtime schema guard for the least-privilege application database user.
 *
 * The portal connects as `wucportal_app`, which holds only DML privileges
 * (SELECT/INSERT/UPDATE/DELETE/EXECUTE). Schema is owned by `wucportal_migrator`
 * and applied through the files in /migrations. Several pages, however, still
 * call `CREATE TABLE IF NOT EXISTS ...` at request time to "self-heal" their
 * schema. That pattern is unsafe for the app user: with mysqli in exception mode
 * (see includes/error_bootstrap.php) MySQL raises "CREATE command denied" — and
 * it does so even when the table already exists, because the privilege is checked
 * *before* `IF NOT EXISTS` is evaluated. The result is an uncaught
 * mysqli_sql_exception and the generic "We could not load this page" error.
 *
 * These helpers make that pattern safe: a CREATE is attempted only when the
 * table is genuinely missing, and a DDL-privilege failure is logged rather than
 * thrown — so a not-yet-applied migration degrades gracefully instead of taking
 * the page down.
 */

if (!function_exists('wuc_table_exists')) {
    /**
     * Whether $table exists in the connection's current database. Uses
     * information_schema, which the DML-only app user can read.
     */
    function wuc_table_exists(mysqli $db, string $table): bool
    {
        try {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $res = $stmt->get_result();
            $exists = $res && $res->num_rows > 0;
            $stmt->close();
            return $exists;
        } catch (Throwable $e) {
            // If we cannot even introspect, assume missing and let the caller
            // decide; wuc_ensure_tables() will swallow any resulting DDL error.
            return false;
        }
    }
}

if (!function_exists('wuc_ensure_tables')) {
    /**
     * Best-effort execution of `CREATE TABLE [IF NOT EXISTS] <name> ...`
     * statements. Each CREATE runs only when its table is missing, and any
     * privilege/DDL failure is logged instead of thrown so the request survives.
     *
     * @param string[] $createStatements CREATE TABLE statements.
     */
    function wuc_ensure_tables(mysqli $db, array $createStatements): void
    {
        foreach ($createStatements as $sql) {
            if (!preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
                continue;
            }
            $table = $m[1];

            // Never issue DDL as the app user when the table already exists:
            // the privilege check would still reject it.
            if (wuc_table_exists($db, $table)) {
                continue;
            }

            try {
                $db->query($sql);
            } catch (Throwable $e) {
                error_log(sprintf(
                    'Schema guard: table "%s" is missing and could not be created at runtime '
                    . '(apply migrations as wucportal_migrator): %s',
                    $table,
                    $e->getMessage()
                ));
            }
        }
    }
}
