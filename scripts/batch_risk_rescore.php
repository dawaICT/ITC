<?php
declare(strict_types=1);

/**
 * Batch academic-risk rescore (zero-cost AI, Sprint 2).
 *
 * Recalculates the rule-based risk score for every active student, persists
 * results to student_risk_summary, mirrors Medium/High into portal_alerts,
 * and records the run in ai_decision_logs.
 *
 * Usage (XAMPP PHP CLI; schedule via Windows Task Scheduler or cron):
 *   C:\xampp\php\php.exe scripts\batch_risk_rescore.php [--limit=500] [--sid=WUC900] [--dry-run]
 *
 * Options:
 *   --limit=N   Max students to process this run (default 500).
 *   --sid=X     Rescore a single student ID only.
 *   --dry-run   Analyze and report, but persist nothing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script can only be run from the command line.';
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/db/connect.php'; // provides $db (mysqli)
require_once $root . '/includes/academic_risk_engine.php';
require_once $root . '/includes/portal_alerts.php';

$options = getopt('', ['limit::', 'sid::', 'dry-run']);
$limit = isset($options['limit']) ? max(1, min(5000, (int)$options['limit'])) : 500;
$singleSid = isset($options['sid']) ? trim((string)$options['sid']) : '';
$dryRun = array_key_exists('dry-run', $options);
$persist = !$dryRun;

$studentIds = [];
if ($singleSid !== '') {
    if (!preg_match('/^[A-Za-z0-9\/\-_]+$/', $singleSid)) {
        fwrite(STDERR, "Invalid --sid value.\n");
        exit(2);
    }
    $studentIds[] = $singleSid;
} else {
    $sql = "SELECT SID FROM students
            WHERE COALESCE(status, 'Active') NOT IN ('Inactive', 'Withdrawn', 'Suspended', 'Deleted')
            ORDER BY SID
            LIMIT ?";
    if (!$stmt = $db->prepare($sql)) {
        fwrite(STDERR, "Could not query students table: {$db->error}\n");
        exit(3);
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $sid = trim((string)($row['SID'] ?? ''));
        if ($sid !== '' && preg_match('/^[A-Za-z0-9\/\-_]+$/', $sid)) {
            $studentIds[] = $sid;
        }
    }
    $stmt->close();
}

if (!$studentIds) {
    echo "No students found to rescore.\n";
    exit(0);
}

$startedAt = microtime(true);
$counts = ['checked' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'errors' => 0];

foreach ($studentIds as $sid) {
    try {
        $risk = wuc_academic_risk_analyze_student($db, $sid, $persist);
        $counts['checked']++;
        $levelKey = strtolower((string)$risk['risk_level']);
        if (isset($counts[$levelKey])) {
            $counts[$levelKey]++;
        }
        if ($risk['risk_level'] !== 'Low') {
            echo sprintf(
                "%-14s %-6s score=%-3d %s\n",
                $sid,
                $risk['risk_level'],
                (int)$risk['risk_score'],
                implode(' | ', array_slice($risk['risk_reasons'], 0, 2))
            );
        }
    } catch (Throwable $e) {
        $counts['errors']++;
        fwrite(STDERR, "Error rescoring {$sid}: {$e->getMessage()}\n");
    }
}

$elapsed = round(microtime(true) - $startedAt, 1);
$summary = sprintf(
    'Rescored %d student(s) in %ss: %d High, %d Medium, %d Low, %d error(s)%s',
    $counts['checked'],
    $elapsed,
    $counts['high'],
    $counts['medium'],
    $counts['low'],
    $counts['errors'],
    $dryRun ? ' [dry run — nothing persisted]' : ''
);
echo $summary . "\n";

if ($persist) {
    wuc_ai_decision_log($db, [
        'feature' => 'academic_risk_engine',
        'decision_type' => 'batch_rescore',
        'entity_type' => 'batch',
        'entity_id' => date('Ymd-His'),
        'input_summary' => $singleSid !== '' ? "single sid {$singleSid}" : "limit {$limit}",
        'outcome' => $summary,
        'user_id' => 'cli',
    ]);
}

exit($counts['errors'] > 0 ? 1 : 0);
