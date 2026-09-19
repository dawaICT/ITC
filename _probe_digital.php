<?php
// Temporary CLI probe: run digital_api.php end-to-end with a real student session
// and the 'list' action, then inspect the returned recommendation scores.
declare(strict_types=1);

require __DIR__ . '/db/connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['Sid'] = $argv[1] ?? 'CSE26456789';
$_GET['action'] = 'list';
$_GET['q'] = '';
$_GET['type'] = '';

ob_start();
try {
    include __DIR__ . '/students/portal-js/digital_api.php';
} catch (Throwable $e) {
    echo 'PROBE_ERROR: ' . $e->getMessage() . PHP_EOL;
}
$out = ob_get_clean();
$payload = json_decode($out, true);
if (!is_array($payload)) {
    echo "RAW: {$out}\n";
    exit(2);
}
echo 'learning_context_courses=' . count($payload['learning_context'] ?? []) . PHP_EOL;
echo 'programme_context=' . json_encode($payload['programme_context'] ?? []) . PHP_EOL;
$results = $payload['results'] ?? [];
$z = null;
$bookCards = 0;
$progCards = 0;
foreach ($results as $r) {
    if (strpos((string)($r['url'] ?? ''), 'z-library.biz') !== false) {
        $z = $r;
    }
    if (($r['source'] ?? '') === 'suggested_book') {
        $bookCards++;
        if (($r['id'] ?? '') === '') { }
        if (strpos((string)($r['id'] ?? ''), 'prog') !== false) {
            $progCards++;
        }
    }
}
if ($z) {
    echo 'Z_ENTRY=' . json_encode([
        'title' => $z['title'],
        'score' => (int)($z['recommendation_score'] ?? 0),
        'recommended' => $z['recommended'],
        'reason' => $z['match_reason'] ?? '',
    ]) . PHP_EOL;
} else {
    echo 'Z_ENTRY=MISSING' . PHP_EOL;
}
echo 'suggested_book_cards=' . $bookCards . PHP_EOL;
echo 'programme_book_cards=' . $progCards . PHP_EOL;
exit(0);