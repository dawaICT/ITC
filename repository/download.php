<?php
require_once __DIR__ . '/_common.php';

$material = repo_fetch_material($db, (int)($_GET['id'] ?? 0));
if (!$material || (string)$material['visibility'] !== 'public') {
    http_response_code(403);
    exit('Only public approved materials are available here.');
}
repo_serve_material($db, (int)$material['id'], 'auto');
