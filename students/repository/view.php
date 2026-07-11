<?php
require_once __DIR__ . '/_common.php';

$material = repo_fetch_material($db, (int)($_GET['id'] ?? 0));
repo_student_header('Repository Material', 'Read approved material details and AI study support.');
if (!$material || !repo_can_access_material($db, $material, 'view')) {
    echo '<div class="alert alert-danger">This material is not available to your account.</div>';
} else {
    repo_render_material_view($db, $material, '/wucportal/students/repository/download.php');
}
repo_student_footer();
