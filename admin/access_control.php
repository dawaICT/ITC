<?php
declare(strict_types=1);

/**
 * Access Control hub — navigation and integrity overview for RBAC pages.
 */
$page_title = 'Access Control Overview';
require_once dirname(__DIR__) . '/config/auth_check.php';
checkAdminAuth();

require_once dirname(__DIR__) . '/includes/access_control.php';
require_once dirname(__DIR__) . '/includes/audit.php';

wuc_require_access_control_admin();

require 'includes/nav.php';

$pages = wuc_access_control_pages();
$integrityIssues = wuc_access_control_integrity_issues($db);
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1"><i class="fas fa-shield-halved me-2"></i>Access Control Overview</h1>
        <p class="text-muted mb-0">Each tool below has a single responsibility. Use the correct page for roles, portal access, permission scope, legacy positions, or department leadership.</p>
    </div>

    <?php if ($integrityIssues): ?>
        <div class="alert alert-warning">
            <h6 class="alert-heading mb-2"><i class="fas fa-triangle-exclamation me-1"></i>Data integrity warnings (<?php echo count($integrityIssues); ?>)</h6>
            <ul class="mb-0 small">
                <?php foreach (array_slice($integrityIssues, 0, 6) as $issue): ?>
                    <li><?php echo wuc_access_control_h($issue['message'] ?? ''); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($integrityIssues) > 6): ?>
                <p class="mb-0 mt-2 small text-muted"><?php echo count($integrityIssues) - 6; ?> more issue(s) not shown.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach ($pages as $key => $meta): ?>
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h5 class="card-title"><?php echo wuc_access_control_h($meta['title']); ?></h5>
                        <p class="card-text small mb-2"><?php echo wuc_access_control_h($meta['purpose']); ?></p>
                        <p class="card-text small text-muted mb-3"><strong>Does not:</strong> <?php echo wuc_access_control_h($meta['does_not']); ?></p>
                        <a href="<?php echo wuc_access_control_h(basename($meta['path'])); ?>" class="btn btn-primary btn-sm">
                            Open <?php echo wuc_access_control_h($meta['title']); ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
