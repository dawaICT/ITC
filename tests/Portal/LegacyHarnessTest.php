<?php

declare(strict_types=1);

namespace Tests\Portal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Re-hosts the portal's pre-existing ad-hoc regression harnesses under PHPUnit.
 *
 * Every tests/<area>/run.php is a self-contained CLI script that opens the live
 * wucportal DB, prints PASS/FAIL per assertion, and exits 0 only when it all
 * passed. Rather than discard them, each is executed as an isolated PHP
 * subprocess (the same way you'd run it by hand) and its exit code is asserted,
 * so the whole fleet of legacy harnesses now surfaces through one PHPUnit run
 * and produces a CI-consumable report. Output from a harness is echoed so it
 * shows up with PHPUnit's test progress.
 *
 * Run everything, or a single suite:
 *   vendor\bin\phpunit --testsuite Portal
 *   vendor\bin\phpunit --testsuite Portal --filter bank_transfer_integrity
 */
final class LegacyHarnessTest extends TestCase
{
    /**
     * Resolve the PHP binary: honour WUC_TEST_PHP override, else use the same
     * PHP that is executing PHPUnit.
     */
    private static function phpExecutable(): string
    {
        $override = getenv('WUC_TEST_PHP');
        return is_string($override) && $override !== '' ? $override : PHP_BINARY;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function harnessProvider(): array
    {
        $root = dirname(__DIR__, 2);
        $cases = [];
        foreach (glob($root . '/tests/*/run.php') ?: [] as $file) {
            $key = basename(dirname((string) $file));
            $cases[$key] = [(string) $file];
        }
        ksort($cases);

        return $cases;
    }

    #[DataProvider('harnessProvider')]
    public function testHarness(string $file): void
    {
        $command = escapeshellarg(self::phpExecutable()) . ' ' . escapeshellarg($file);
        $output = [];
        $code = 0;
        // 2>&1 merges stderr so php warnings/fatals land in the captured output.
        exec($command . ' 2>&1', $output, $code);

        $text = trim(implode("\n", $output));
        if ($text !== '') {
            // Make the harness's own PASS/FAIL lines visible in the test output.
            fwrite(STDOUT, $text . "\n");
        }

        $this->assertSame(
            0,
            $code,
            vsprintf(
                'Legacy harness failed with exit code %d.%s%s',
                [
                    $code,
                    $text !== '' ? "\n\n" . $text : " (no output)\n",
                    "\nFix or re-run directly: " . $command,
                ]
            )
        );
    }

    public function testPHPUnitCanLocatePortalHarnesses(): void
    {
        $root = dirname(__DIR__, 2);
        $count = count(glob($root . '/tests/*/run.php') ?: []);
        $this->assertGreaterThan(0, $count, 'No tests/*/run.php harnesses discovered.');
        fwrite(STDOUT, 'Discovered ' . $count . " legacy portal harnesses.\n");
    }
}