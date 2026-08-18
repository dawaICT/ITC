<?php
/**
 * Verify scalability fixes: risk cache, lookup cache, dashboard counts, health.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/db/connect.php';
require_once $root . '/includes/academic_risk_engine.php';
require_once $root . '/includes/lookup_cache.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

echo "=== Verify scalability fixes ===\n";

$sid = '';
$r = $db->query('SELECT SID FROM students LIMIT 1');
if ($row = $r->fetch_assoc()) {
    $sid = (string)$row['SID'];
}
echo "SID={$sid}\n";

// Ensure a persisted risk row exists
$t0 = hrtime(true);
$fresh = wuc_academic_risk_analyze_student($db, $sid, true);
$computeMs = (hrtime(true) - $t0) / 1e6;
echo sprintf("risk compute+persist: %.2f ms level=%s\n", $computeMs, $fresh['risk_level'] ?? '?');

$t0 = hrtime(true);
$cached = wuc_academic_risk_for_dashboard($db, $sid, 900);
$cacheMs = (hrtime(true) - $t0) / 1e6;
echo sprintf(
    "risk dashboard cached: %.2f ms from_cache=%s level=%s\n",
    $cacheMs,
    !empty($cached['from_cache']) ? 'yes' : 'no',
    $cached['risk_level'] ?? '?'
);

$t0 = hrtime(true);
$programs1 = wuc_lookup_programs($db);
$ms1 = (hrtime(true) - $t0) / 1e6;
$t0 = hrtime(true);
$programs2 = wuc_lookup_programs($db);
$ms2 = (hrtime(true) - $t0) / 1e6;
echo sprintf("lookup programs: first=%.2f ms second=%.2f ms count=%d\n", $ms1, $ms2, count($programs1));
echo 'lookup second faster: ' . ($ms2 <= $ms1 ? 'yes' : 'no') . "\n";
echo 'programs equal: ' . ($programs1 === $programs2 ? 'yes' : 'no') . "\n";

// Admin dashboard cache producer (inline same key)
$t0 = hrtime(true);
$c1 = wuc_cache_remember('admin_dashboard_counts_v1_test', static function () use ($db) {
    $r = $db->query('SELECT COUNT(*) c FROM students');
    return ['students' => (int)$r->fetch_assoc()['c']];
}, 60);
$msA = (hrtime(true) - $t0) / 1e6;
$t0 = hrtime(true);
$c2 = wuc_cache_remember('admin_dashboard_counts_v1_test', static function () use ($db) {
    throw new RuntimeException('producer should not run on cache hit');
}, 60);
$msB = (hrtime(true) - $t0) / 1e6;
echo sprintf("file/apcu cache: miss=%.2f ms hit=%.2f ms students=%d\n", $msA, $msB, $c2['students'] ?? 0);
wuc_cache_forget('admin_dashboard_counts_v1_test');

// Table exists memo
$t0 = hrtime(true);
for ($i = 0; $i < 20; $i++) {
    wuc_table_exists($db, 'students');
}
$msTbl = (hrtime(true) - $t0) / 1e6;
echo sprintf("wuc_table_exists x20: %.2f ms\n", $msTbl);

// Opportunity count helper
require_once $root . '/includes/enterprise_portal/opportunity_service.php';
if (function_exists('ep_count_opportunities_for_profile')) {
    $t0 = hrtime(true);
    $counts = ep_count_opportunities_for_profile($db, 1);
    echo sprintf("opp counts: %.2f ms total=%d\n", (hrtime(true) - $t0) / 1e6, $counts['total']);
}

echo "\n=== Concurrent simulated risk-dashboard hits ===\n";
$times = [];
for ($n = 0; $n < 10; $n++) {
    $t0 = hrtime(true);
    wuc_academic_risk_for_dashboard($db, $sid, 900);
    $times[] = (hrtime(true) - $t0) / 1e6;
}
echo sprintf(
    "10 sequential cached dashboard risk reads: avg=%.2f ms min=%.2f max=%.2f\n",
    array_sum($times) / count($times),
    min($times),
    max($times)
);

$speedup = $cacheMs > 0 ? ($computeMs / max(0.01, $cacheMs)) : 0;
echo sprintf("\nSPEEDUP risk compute→cache: %.1fx (%.0fms → %.0fms)\n", $speedup, $computeMs, $cacheMs);
echo "DONE\n";
