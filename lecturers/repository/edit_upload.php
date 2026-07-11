<?php
require_once __DIR__ . '/_common.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$material = $id > 0 ? repo_fetch_material($db, $id) : null;
if (!$material || (string)$material['uploader_staff_id'] !== repo_current_staff_id() || !in_array((string)$material['status'], ['pending', 'rejected'], true)) {
    repo_lecturer_header('Edit Repository Upload');
    echo '<div class="alert alert-danger">Only your pending or rejected uploads can be edited.</div>';
    repo_lecturer_footer();
    exit;
}

repo_handle_upload($db, '/wucportal/lecturers/repository/my_uploads.php', $id);
$material = repo_fetch_material($db, $id) ?: $material;
repo_lecturer_header('Edit Repository Upload', 'Saving changes returns the material to pending approval.');
repo_render_upload_form($db, '/wucportal/lecturers/repository/edit_upload.php?id=' . $id, $material);
repo_lecturer_footer();
