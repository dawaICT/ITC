<?php
require_once __DIR__ . '/_common.php';

$stats = [];
$queries = [
    'Total uploaded materials' => "SELECT COUNT(*) c FROM repository_materials",
    'Pending materials' => "SELECT COUNT(*) c FROM repository_materials WHERE status='pending'",
    'Approved materials' => "SELECT COUNT(*) c FROM repository_materials WHERE status='approved'",
    'Rejected materials' => "SELECT COUNT(*) c FROM repository_materials WHERE status='rejected'",
    'Archived materials' => "SELECT COUNT(*) c FROM repository_materials WHERE is_archived=1 OR status='archived'",
    'Public materials' => "SELECT COUNT(*) c FROM repository_materials WHERE visibility='public' AND status='approved'",
    'AI processed materials' => "SELECT COUNT(*) c FROM repository_ai_metadata WHERE status IN ('processed','fallback')",
    'AI failed processing logs' => "SELECT COUNT(*) c FROM repository_ai_metadata WHERE status='failed' OR error_message IS NOT NULL",
];
foreach ($queries as $label => $sql) {
    try {
        $res = repo_table_exists($db, strpos($sql, 'repository_ai_metadata') !== false ? 'repository_ai_metadata' : 'repository_materials')
            ? $db->query($sql)
            : false;
        $stats[$label] = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;
        if ($res) $res->free();
    } catch (Throwable $e) {
        $stats[$label] = 0;
    }
}

$mostViewed = repo_accessible_materials($db, ['most_viewed' => true], 10);
$mostDownloaded = repo_accessible_materials($db, ['most_downloaded' => true], 10);

repo_admin_header('Repository Reports', 'Upload, approval, access, and AI processing reports.');
?>
<div class="row g-4 mb-4">
    <?php foreach ($stats as $label => $value): ?>
        <div class="col-md-3">
            <section class="data-table-card h-100">
                <div class="card-body">
                    <div class="text-muted small text-uppercase"><?php echo repo_h($label); ?></div>
                    <div class="display-6 fw-bold"><?php echo (int)$value; ?></div>
                </div>
            </section>
        </div>
    <?php endforeach; ?>
</div>
<div class="row g-4">
    <div class="col-lg-6">
        <section class="data-table-card">
            <div class="card-header"><h5 class="mb-0">Most Viewed Materials</h5></div>
            <div class="card-body">
                <?php foreach ($mostViewed as $row): ?>
                    <div class="d-flex justify-content-between border-bottom py-2"><span><?php echo repo_h($row['title']); ?></span><strong><?php echo (int)$row['view_count']; ?></strong></div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="data-table-card">
            <div class="card-header"><h5 class="mb-0">Most Downloaded Materials</h5></div>
            <div class="card-body">
                <?php foreach ($mostDownloaded as $row): ?>
                    <div class="d-flex justify-content-between border-bottom py-2"><span><?php echo repo_h($row['title']); ?></span><strong><?php echo (int)$row['download_count']; ?></strong></div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<?php repo_admin_footer(); ?>
