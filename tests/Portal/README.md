# WUC Portal — PHPUnit test harness

The portal is a mix of a PHP app and pre-existing ad-hoc regression harnesses.
This `tests/Portal` suite wires both into PHPUnit so the whole portal can be
exercised from one command and produce a CI-consumable report.

## Why this exists

Previously the portal's regression checks were plain PHP CLI scripts
(`tests/<area>/run.php`) that opened the live DB and printed `PASS`/`FAIL`
lines. PHPUnit was installed (`phpunit/phpunit ^11`) but ran **zero** tests
because `tests/Unit` and `tests/Feature` were empty. This suite:

- gives an instant, deterministic DB-connectivity + schema smoke test
  (`PortalDatabaseTest`), and
- re-hosts every existing `tests/<area>/run.php` as an isolated PHPUnit test
  (`LegacyHarnessTest`), asserting its CLi exit code and echoing its own
  PASS/FAIL output.

## Running

```powershell
# helper commands (defined in composer.json)
composer harness          # == composer test:portal
composer test:portal      # the whole Portal suite
composer test:legacy      # only the re-hosted legacy harnesses
composer test             # everything (Unit + Feature + Portal)

# or directly:
C:\xampp\php\php.exe vendor\bin\phpunit --testsuite Portal
```

Filter to a single harness (the filter matches the directory name):

```powershell
C:\xampp\php\php.exe vendor\bin\phpunit --testsuite Portal --filter teaching_planner
```

## Configuration

- **PHP binary**: the harness re-runs each legacy script with the same PHP that
  is running PHPUnit (`PHP_BINARY`). Override with the `WUC_TEST_PHP`
  environment variable if you need a specific interpreter.
- **Database**: `TestCase::connect()` reuses `includes/portal_config.php`
  (which auto-selects local XAMPP credentials under CLI) so the tests hit the
  same `wucportal` database the pages use. Set `WUC_DB_*` to point elsewhere.
- **Bootstrap**: `tests/Portal` tests extend `Tests\Portal\TestCase`
  (plain `PHPUnit\Framework\TestCase`), **not** `Tests\TestCase` (Laravel).
  They deliberately do not boot the Laravel container.

## Writing a new portal test

```php
namespace Tests\Portal;

final class MyPortalTest extends TestCase
{
    public function testSomethingAgainstLiveDb(): void
    {
        $this->assertTableExists('payments');
        $this->assertColumnExists('payments', 'amount');
        $res = $this->db->query('SELECT COUNT(*) c FROM payments');
        $this->assertNotFalse($res);
    }
}
```

Prefer using the live service/helper classes over re-implementing oracles —
mirror the style of `tests/student_module/run_core.php`. Verify every query
against the real schema first (mysqli throws fatals on drift; see the
`wuc-schema-debug` skill).

## Interpreting results

A failing legacy harness means that harness's own assertions failed (its
`RESULT=FAIL` line is echoed before PHPUnit's failure message). Some harnesses
depend on a running Apache on `http://localhost/wucportal/`, seeded accounts
(`scripts/seed_test_accounts_all_roles.php`), or other live state — run them
directly (`C:\xampp\php\php.exe tests/<area>/run.php`) to inspect.