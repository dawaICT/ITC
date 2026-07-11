<?php
require_once __DIR__ . '/_common.php';

repo_serve_material($db, (int)($_GET['id'] ?? 0), 'auto');
