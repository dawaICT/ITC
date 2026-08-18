<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/ca_integrity_audit.php';

$json = in_array('--json', $argv ?? [], true);
$checks = ca_integrity_audit($db);
$summary = ca_integrity_summary($checks);

if ($json) {
    echo json_encode(['summary' => $summary, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} else {
    echo "CA / marks data integrity audit\n";
    foreach ($checks as $check) {
        $state = $check['ok'] ? 'OK' : strtoupper($check['severity']);
        echo sprintf("[%s] %s: %d\n", $state, $check['label'], $check['count']);
        if (!$check['ok'] && $check['details'] !== []) {
            foreach (array_slice($check['details'], 0, 10) as $detail) {
                echo '  - ', is_array($detail) ? json_encode($detail, JSON_UNESCAPED_SLASHES) : (string)$detail, "\n";
            }
        }
    }
    echo sprintf("Summary: %d failing rows, %d warnings, %d clean checks\n", $summary['fail'], $summary['warn'], $summary['ok']);
}

exit($summary['clean'] ? 0 : 1);
