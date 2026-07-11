<?php
/**
 * Academic Structure Integrity  (Multi-Portal Redesign — Phase 5/10)
 *
 * Admin-only, read-only view of wuc_academic_structure_audit(): a whole-database
 * health check of the Section → Department → Programme → Course → Lecturer →
 * Registration chain. Surfaces orphaned / misconfigured rows so they can be fixed
 * before they break registration, CA, results or eLearning. Performs no writes.
 */

declare(strict_types=1);

$page_title = 'Academic Structure Integrity';
require_once __DIR__ . '/includes/header.php';            // session, $db, admin guard, chrome
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/academic_structure_audit.php';

$staffId = $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? '';
$isAdminUser = (isset($isAdmin) && $isAdmin) || hasPermission($staffId, 'admin_all');
if (!$isAdminUser) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-3">Access denied. Academic structure integrity is restricted to systems administrators.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$results = wuc_academic_structure_audit($db);
$summary = wuc_academic_structure_audit_summary($results);

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$sevBadge = static function (array $r): string {
    if (!empty($r['skipped'])) { return '<span class="badge bg-secondary">Skipped</span>'; }
    if ($r['ok'])              { return '<span class="badge bg-success">OK</span>'; }
    return $r['severity'] === 'fail'
        ? '<span class="badge bg-danger">Fail</span>'
        : '<span class="badge bg-warning text-dark">Warning</span>';
};
?>
<div class="container-fluid px-4">
    <h1 class="mt-4">Academic Structure Integrity</h1>
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Academic Structure Integrity</li>
    </ol>

    <?php if ($summary['clean']): ?>
        <div class="alert alert-success d-flex align-items-center">
            <i class="fas fa-circle-check fa-lg me-2"></i>
            <div>All academic structure checks passed. Section → Department → Programme → Course →
            Lecturer → Registration relationships are intact.</div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning d-flex align-items-center">
            <i class="fas fa-triangle-exclamation fa-lg me-2"></i>
            <div><strong><?php echo (int)$summary['fail']; ?></strong> critical and
                 <strong><?php echo (int)$summary['warn']; ?></strong> warning issue(s) found.
                 Review the rows flagged below.</div>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php
        $tiles = [
            ['Critical', $summary['fail'], 'danger'],
            ['Warnings', $summary['warn'], 'warning'],
            ['Passing', $summary['ok'], 'success'],
            ['Skipped', $summary['skipped'], 'secondary'],
        ];
        foreach ($tiles as [$label, $val, $color]): ?>
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small text-uppercase"><?php echo $h($label); ?></div>
                            <div class="h3 mb-0"><?php echo (int)$val; ?></div>
                        </div>
                        <span class="badge bg-<?php echo $color; ?> rounded-circle p-3">&nbsp;</span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-sitemap me-1"></i> Relationship checks</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" style="width:110px">Status</th>
                            <th scope="col">Check</th>
                            <th scope="col" class="text-center" style="width:90px">Rows</th>
                            <th scope="col">How to fix</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr class="<?php echo (!$r['ok'] && empty($r['skipped'])) ? ($r['severity'] === 'fail' ? 'table-danger' : 'table-warning') : ''; ?>">
                                <td><?php echo $sevBadge($r); ?></td>
                                <td><?php echo $h($r['label']); ?></td>
                                <td class="text-center fw-bold"><?php echo $r['sql_ok'] ? (int)$r['count'] : '&mdash;'; ?></td>
                                <td class="small text-muted"><?php echo (!$r['ok'] && empty($r['skipped'])) ? $h($r['hint']) : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mb-0"><i class="fas fa-circle-info me-1"></i>
               This page is read-only. Skipped checks indicate a table that is not present in this
               database.</p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
