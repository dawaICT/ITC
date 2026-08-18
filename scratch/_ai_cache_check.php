<?php
declare(strict_types=1);
require_once 'C:/xampp/htdocs/wucportal/db/connect.php';

$out = [];
$out[] = '=== CACHE + LIVE ASK CHECK ===';

// Check if old error is cached
$fail = 'The configured AI model is temporarily unavailable';
$stmt = $db->prepare("SELECT course_id, explanation_level, model_name, LEFT(answer,180) a, expires_at FROM ai_response_cache WHERE answer LIKE ? OR model_name='grounded-fallback' ORDER BY expires_at DESC LIMIT 20");
$like = '%' . $fail . '%';
$stmt->bind_param('s', $like);
$stmt->execute();
$res = $stmt->get_result();
$n = 0;
while ($row = $res->fetch_assoc()) {
    $n++;
    $out[] = 'cached_fail=' . json_encode($row, JSON_UNESCAPED_SLASHES);
}
$stmt->close();
$out[] = "cached_fail_count={$n}";

// Also any grounded-fallback still valid
$r = $db->query("SELECT course_id, model_name, LEFT(answer,120) a, expires_at FROM ai_response_cache WHERE expires_at>NOW() AND (model_name='grounded-fallback' OR answer LIKE '%no approved course source%') LIMIT 20");
$n2 = 0;
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $n2++;
        $out[] = 'active_bad_cache=' . json_encode($row, JSON_UNESCAPED_SLASHES);
    }
}
$out[] = "active_bad_cache_count={$n2}";

// Clear bad cache entries so UI doesn't keep showing old failure
$del = $db->query("DELETE FROM ai_response_cache WHERE model_name='grounded-fallback' OR answer LIKE '%temporarily unavailable%' OR answer LIKE '%no approved course source%'");
$out[] = 'deleted_bad_cache=' . ($del ? $db->affected_rows : 'fail');

require_once 'C:/xampp/htdocs/wucportal/services/ai/AIGateway.php';
$gateway = new AIGateway($db);
$resp = $gateway->askStudent('CSE26456789', [
    'course_id' => 'DCSE-101',
    'question' => 'Explain what Information Technology means in this course',
    'explanation_level' => 'simple',
]);
$ans = (string)($resp['answer'] ?? '');
$out[] = 'live_source_status=' . ($resp['source_status'] ?? '');
$out[] = 'live_is_old_error=' . (str_contains($ans, 'temporarily unavailable') ? 'yes' : 'no');
$out[] = 'live_answer=' . substr($ans, 0, 280);

$text = implode(PHP_EOL, $out) . PHP_EOL;
file_put_contents('C:/xampp/htdocs/wucportal/scratch/_ai_cache_check.txt', $text);
echo $text;
