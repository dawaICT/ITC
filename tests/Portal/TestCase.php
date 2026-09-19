<?php

declare(strict_types=1);

namespace Tests\Portal;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Plain-PHP userland TestCase for the WUC portal.
 *
 * Unlike Tests\TestCase (which boots the Laravel application), this base class
 * connects to the *live* wucportal MySQL database exactly the way the served
 * pages do — via includes/portal_config.php (which auto-selects local XAMPP
 * credentials when running under CLI) — and exposes the mysqli handle on
 * $this->db. It is deliberately independent of the Laravel container so the
 * legacy, schema-drift-prone portal queries can be exercised directly.
 */
abstract class TestCase extends BaseTestCase
{
    protected ?\mysqli $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = self::connect();
    }

    protected function tearDown(): void
    {
        if ($this->db instanceof \mysqli) {
            @$this->db->close();
            $this->db = null;
        }
        parent::tearDown();
    }

    /**
     * Open a mysqli handle to the live wucportal database, honouring the same
     * env resolution the application uses. Throws (rather than exit()ing) so a
     * failing connection surfaces as a PHPUnit error instead of killing the run.
     *
     * @return \mysqli
     */
    protected static function connect(): \mysqli
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/includes/portal_config.php';

        $host = wuc_portal_env('WUC_DB_HOST', '127.0.0.1') ?: '127.0.0.1';
        $port = (int) (wuc_portal_env('WUC_DB_PORT') ?: 3306);
        $user = wuc_portal_env('WUC_DB_USER') ?? 'root';
        $password = wuc_portal_env('WUC_DB_PASSWORD') ?? '';
        $name = wuc_portal_env('WUC_DB_NAME') ?: 'wucportal';

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new \mysqli($host, $user, $password, $name, $port);
        if ($db->connect_errno) {
            throw new \RuntimeException(
                'Portal test DB connection failed: ' . $db->connect_error
            );
        }
        $db->set_charset('utf8mb4');
        $db->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

        return $db;
    }

    protected function assertTableExists(string $table, string $message = ''): void
    {
        if (!$this->db) {
            throw new \LogicException('Portal test DB is not connected.');
        }
        $escaped = $this->db->real_escape_string($table);
        $res = $this->db->query("SHOW TABLES LIKE '{$escaped}'");
        $this->assertTrue(
            $res !== false && $res->num_rows > 0,
            $message !== '' ? $message : "Table `{$table}` should exist in wucportal."
        );
    }

    /** @return list<string> */
    protected function tableColumns(string $table): array
    {
        if (!$this->db) {
            throw new \LogicException('Portal test DB is not connected.');
        }
        $escaped = $this->db->real_escape_string($table);
        $columns = [];
        $res = $this->db->query("SHOW COLUMNS FROM `{$escaped}`");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $columns[] = (string) $row['Field'];
            }
            $res->free();
        }
        return $columns;
    }

    protected function assertColumnExists(string $table, string $column, string $message = ''): void
    {
        $columns = $this->tableColumns($table);
        $this->assertContains(
            $column,
            $columns,
            $message !== '' ? $message : "Table `{$table}` should include column `{$column}`."
        );
    }
}