<?php
require_once __DIR__ . '/_common.php';

$material = repo_fetch_material($db, (int)($_GET['id'] ?? 0));
repo_public_header('Public Repository Material', 'Public approved learning resource.');
if (!$material || !repo_can_access_material($db, $material, 'view') || (string)$material['visibility'] !== 'public') {
    echo '<div class="alert alert-danger">This public material is not available.</div>';
} else {
    repo_render_material_view($db, $material, '/wucportal/repository/download.php');
}
repo_public_footer();
