<?php
require_once __DIR__ . '/_common.php';

$staffId = repo_current_staff_id();
$materials = [];
if (repo_table_exists($db, 'repository_materials') && ($stmt = $db->prepare("SELECT * FROM repository_materials WHERE uploader_staff_id = ? ORDER BY created_at DESC, id DESC"))) {
    $stmt->bind_param('s', $staffId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $materials[] = $row;
    }
    $stmt->close();
}

repo_lecturer_header('My Repository Uploads', 'Track approval status, edit pending uploads, and review rejection reasons.');
?>
<section class="data-table-card">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Visibility</th><th>Feedback</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$materials): ?><tr><td colspan="6" class="text-center text-muted py-4">You have not uploaded repository materials yet.</td></tr><?php endif; ?>
            <?php foreach ($materials as $material): ?>
                <tr>
                    <td><?php echo repo_h($material['title']); ?><div class="small text-muted"><?php echo repo_h($material['created_at']); ?></div></td>
                    <td><?php echo repo_h(repo_material_types()[$material['material_type']] ?? $material['material_type']); ?></td>
                    <td><?php echo repo_badge((string)$material['status']); ?></td>
                    <td><?php echo repo_h(repo_visibility_levels()[$material['requested_visibility']] ?? $material['requested_visibility']); ?></td>
                    <td class="small"><?php echo repo_h($material['rejection_reason'] ?? ''); ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline-primary" href="/wucportal/lecturers/repository/view.php?id=<?php echo (int)$material['id']; ?>">View</a>
                        <?php if (in_array((string)$material['status'], ['pending', 'rejected'], true)): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="/wucportal/lecturers/repository/edit_upload.php?id=<?php echo (int)$material['id']; ?>">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php repo_lecturer_footer(); ?>
