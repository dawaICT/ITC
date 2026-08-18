<?php
/**
 * Concurrent request simulation against local endpoints using curl_multi.
 * Tests 1 / 10 / 50 / 100 parallel hits to cheap + medium endpoints.
 */
declare(strict_types=1);

$base = getenv('WUC_PERF_BASE_URL') ?: 'http://127.0.0.1/wucportal';
$endpoints = [
    'health' => $base . '/api/health.php',
    'favicon_static' => $base . '/assets/css/dashboard.css',
];

function run_batch(string $url, int $concurrency): array
{
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $concurrency; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Connection: close'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }

    $t0 = hrtime(true);
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    $wallMs = (hrtime(true) - $t0) / 1e6;

    $codes = [];
    $errors = 0;
    $latencies = [];
    foreach ($handles as $ch) {
        $info = curl_getinfo($ch);
        $code = (int)($info['http_code'] ?? 0);
        $codes[$code] = ($codes[$code] ?? 0) + 1;
        if ($code >= 500 || $code === 0) {
            $errors++;
        }
        $latencies[] = ((float)($info['total_time'] ?? 0)) * 1000;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    sort($latencies);
    $p95 = $latencies[(int)floor(0.95 * (count($latencies) - 1))] ?? 0;

    return [
        'concurrency' => $concurrency,
        'wall_ms' => $wallMs,
        'errors' => $errors,
        'codes' => $codes,
        'avg_ms' => $latencies ? array_sum($latencies) / count($latencies) : 0,
        'p95_ms' => $p95,
        'max_ms' => $latencies ? max($latencies) : 0,
    ];
}

echo "Base URL: {$base}\n";
foreach ($endpoints as $name => $url) {
    echo "\n=== {$name} ({$url}) ===\n";
    foreach ([1, 10, 50, 100] as $n) {
        $r = run_batch($url, $n);
        echo sprintf(
            "n=%-3d wall=%.0fms avg=%.0fms p95=%.0fms max=%.0fms errors=%d codes=%s\n",
            $r['concurrency'],
            $r['wall_ms'],
            $r['avg_ms'],
            $r['p95_ms'],
            $r['max_ms'],
            $r['errors'],
            json_encode($r['codes'])
        );
    }
}

echo "\nDONE\n";
