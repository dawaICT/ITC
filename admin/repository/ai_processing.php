<?php
require_once __DIR__ . '/_common.php';

repo_handle_admin_action($db, '/wucportal/admin/repository/ai_processing.php');
$materials = repo_accessible_materials($db, ['status' => 'approved'], 300);

repo_admin_header('Repository AI Processing', 'Trigger or re-run AI summaries, keywords, study guides, and revision questions for approved materials.');
?>
<section class="data-table-card">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Material</th><th>Scope</th><th>AI Status</th><th>Generated</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($materials as $material): ?>
                <?php
                $ai = null;
                $stmt = repo_table_exists($db, 'repository_ai_metadata')
                    ? $db->prepare("SELECT status, generated_at, error_message FROM repository_ai_metadata WHERE material_id=? LIMIT 1")
                    : false;
                if ($stmt instanceof mysqli_stmt) {
                    $mid = (int)$material['id'];
                    $stmt->bind_param('i', $mid);
                    $stmt->execute();
                    $ai = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }
                ?>
                <tr>
                    <td><?php echo repo_h($material['title']); ?><div class="small text-muted"><?php echo repo_h(repo_material_types()[$material['material_type']] ?? $material['material_type']); ?></div></td>
                    <td><?php echo repo_h($material['course_code'] ?: $material['programme_code'] ?: $material['department_id'] ?: 'General'); ?></td>
                    <td><?php echo repo_h($ai['status'] ?? $material['ai_status']); ?><?php if (!empty($ai['error_message'])): ?><div class="small text-danger"><?php echo repo_h($ai['error_message']); ?></div><?php endif; ?></td>
                    <td><?php echo repo_h($ai['generated_at'] ?? 'Not processed'); ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo repo_h($_SESSION['csrf_token'] ?? ''); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$material['id']; ?>">
                            <button class="btn btn-sm btn-primary" name="action" value="ai">Run AI</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php repo_admin_footer(); ?>
