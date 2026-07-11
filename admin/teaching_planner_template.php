<?php
declare(strict_types=1);

define('IS_SCRIPT', true);
require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/teaching_planner/init.php';

if (!function_exists('isSystemsAdmin') || !isSystemsAdmin()) {
    http_response_code(403); exit('Access denied.');
}
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$mode = (string)($_GET['mode'] ?? 'download');
if (!$id || !in_array($mode, ['download', 'preview'], true)) {
    http_response_code(400); exit('Invalid template reference.');
}
try {
    if ($mode === 'preview') {
        $result = (new TeachingPlannerExporter($db))->previewTemplate($id, tp_actor_id());
        $path = $result['path']; $filename = $result['filename'];
        register_shutdown_function(static function () use ($path): void { if (is_file($path)) @unlink($path); });
    } else {
        $stmt = $db->prepare('SELECT storage_path, original_filename, checksum_sha256 FROM document_template_versions WHERE id = ?');
        $stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) { throw new RuntimeException('Template version not found.'); }
        $path = tp_safe_storage_path((string)$row['storage_path']); $filename = basename((string)$row['original_filename']);
        if (!is_file($path) || !hash_equals((string)$row['checksum_sha256'], (string)hash_file('sha256', $path))) { throw new RuntimeException('Template integrity check failed.'); }
        tp_audit($db, null, 'template.downloaded', 'document_template_version', (string)$id, []);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    readfile($path); exit;
} catch (Throwable $e) {
    error_log('Teaching Planner template download failed: ' . $e->getMessage());
    http_response_code(404); exit('The requested template file is not available.');
}

