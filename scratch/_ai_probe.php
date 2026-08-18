<?php
declare(strict_types=1);
$out = [];
$out[] = '=== OLLAMA / LEARNING ASSISTANT PROBE ===';
$out[] = 'time=' . date('c');

require_once 'C:/xampp/htdocs/wucportal/ai/ollama.php';
$out[] = 'host=' . OLLAMA_HOST;
$out[] = 'chat_model=' . AI_CHAT_MODEL;
$out[] = 'available=' . (ollama_available() ? 'yes' : 'no');
if (ollama_available()) {
    foreach (ollama_installed_models() as $m) {
        $out[] = 'installed=' . $m;
    }
    try {
        $out[] = 'resolved=' . ai_resolve_chat_model();
    } catch (Throwable $e) {
        $out[] = 'resolve_error=' . $e->getMessage();
    }
    try {
        $ans = ollama_chat([
            ['role' => 'system', 'content' => 'Reply with exactly: OK'],
            ['role' => 'user', 'content' => 'ping'],
        ]);
        $out[] = 'chat_ping=' . substr(trim($ans), 0, 120);
    } catch (Throwable $e) {
        $out[] = 'chat_error=' . $e->getMessage();
    }
}

require_once 'C:/xampp/htdocs/wucportal/db/connect.php';
require_once 'C:/xampp/htdocs/wucportal/includes/elearning_access.php';
require_once 'C:/xampp/htdocs/wucportal/services/ai/AIKnowledgeRetriever.php';

$sid = 'CSE26456789';
$courses = [];
try {
    $courses = array_values(array_filter(array_map('strval', getStudentEnrolledCourses($db, $sid))));
} catch (Throwable $e) {
    $out[] = 'enroll_error=' . $e->getMessage();
}
$out[] = 'courses=' . implode(',', $courses);

$retriever = new AIKnowledgeRetriever($db);
$q = 'Explain the main concepts of this course';
foreach ($courses as $code) {
    $sources = $retriever->retrieve($code, $q);
    $out[] = "course={$code} sources=" . count($sources);
    foreach (array_slice($sources, 0, 3) as $s) {
        $out[] = '  - ' . $s['source_type'] . ' | score=' . $s['score'] . ' | ' . substr($s['title'], 0, 80);
    }

    // raw table counts
    foreach ([
        'knowledge_chunks' => "SELECT COUNT(*) c FROM knowledge_chunks WHERE course_id=?",
        'knowledge_docs' => "SELECT COUNT(*) c FROM knowledge_documents kd INNER JOIN knowledge_document_versions kv ON kv.document_id=kd.id INNER JOIN knowledge_chunks kc ON kc.document_version_id=kv.id WHERE kc.course_id=? AND kd.status IN ('approved','published')",
        'syllabus' => "SELECT COUNT(*) c FROM syllabus_topics st INNER JOIN syllabus_versions sv ON sv.id=st.syllabus_version_id WHERE sv.course_code=? AND sv.status='approved'",
        'repo' => "SELECT COUNT(*) c FROM repository_materials WHERE course_code=? AND status='approved' AND is_published=1 AND is_archived=0",
    ] as $label => $sql) {
        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                $out[] = "  count_{$label}=prepare_fail";
                continue;
            }
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            $out[] = "  count_{$label}={$c}";
        } catch (Throwable $e) {
            $out[] = "  count_{$label}=err:" . $e->getMessage();
        }
    }
}

// Recent AIGateway errors from common log paths
foreach ([
    'C:/xampp/wucportal-var/logs/wucportal-error.log',
    'C:/xampp/htdocs/wucportal/logs/error.log',
    'C:/xampp/php/logs/php_error_log',
    'C:/xampp/apache/logs/error.log',
] as $log) {
    if (!is_file($log)) {
        $out[] = "log_missing={$log}";
        continue;
    }
    $lines = @file($log, FILE_IGNORE_NEW_LINES) ?: [];
    $hits = array_values(array_filter($lines, static fn($l) => stripos($l, 'AIGateway') !== false || stripos($l, 'Ollama') !== false || stripos($l, 'AI model') !== false));
    $out[] = "log={$log} hits=" . count($hits);
    foreach (array_slice($hits, -8) as $h) {
        $out[] = '  ' . substr($h, 0, 240);
    }
}

$text = implode(PHP_EOL, $out) . PHP_EOL;
file_put_contents('C:/xampp/htdocs/wucportal/scratch/_ai_probe.txt', $text);
echo $text;
