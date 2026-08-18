<?php
declare(strict_types=1);

/**
 * Skills-to-Trade Hub — Reports + CSV export.
 */

$page_title = 'Reports — Skills-to-Trade';
require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/enterprise_hub/bootstrap.php';

eh_require($db, 'enterprise.reports.view');

$base = '/wucportal/admin/enterprise';

$reportOptions = [
    'by_category' => 'Items by category',
    'by_programme' => 'Items by programme',
    'products_vs_services' => 'Products vs services (item type)',
    'status_distribution' => 'Status distribution',
    'investment_by_category' => 'Investment by category (published)',
    'employment_by_category' => 'Employment potential by category',
    'interest_by_type' => 'Interest by type',
    'interest_followup' => 'Interest follow-up status',
    'most_viewed' => 'Most viewed published items',
    'conversion_outcomes' => 'Conversion outcomes',
];

$report = (string)($_GET['report'] ?? 'by_category');
if (!array_key_exists($report, $reportOptions)) {
    $report = 'by_category';
}

$rows = eh_report_rows($db, $report);

if (!empty($_GET['export']) && (string)$_GET['export'] === '1') {
    eh_export_csv(
        'enterprise_' . $report . '_' . date('Ymd_His') . '.csv',
        ['Label', 'Total'],
        $rows
    );
}

$flash = function_exists('wuc_get_flash') ? wuc_get_flash() : null;

require_once __DIR__ . '/../includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <h1 class="dashboard-title"><i class="fas fa-chart-bar me-2 text-primary"></i>Hub Reports</h1>
                <p class="text-muted mb-0">Operational analytics for the Skills-to-Trade and Investment Hub.</p>
            </div>
            <div class="col-auto">
                <a href="<?php echo eh_h($base); ?>/index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Hub home</a>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <?php $alertType = ($flash['type'] ?? '') === 'error' ? 'danger' : (string)$flash['type']; ?>
        <div class="alert alert-<?php echo eh_h($alertType); ?> alert-dismissible fade show" role="alert">
            <?php echo eh_h((string)($flash['message'] ?? '')); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <section class="data-table-card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="report">Report</label>
                    <select class="form-select" name="report" id="report">
                        <?php foreach ($reportOptions as $key => $label): ?>
                            <option value="<?php echo eh_h($key); ?>" <?php echo $report === $key ? 'selected' : ''; ?>>
                                <?php echo eh_h($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">View</button>
                    <a class="btn btn-outline-success" href="<?php echo eh_h($base); ?>/reports.php?report=<?php echo rawurlencode($report); ?>&amp;export=1">
                        <i class="fas fa-file-csv me-1"></i>Export CSV
                    </a>
                </div>
            </form>
        </div>
    </section>

    <section class="data-table-card">
        <div class="card-header">
            <h5 class="mb-0"><?php echo eh_h($reportOptions[$report]); ?></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Label</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="2" class="text-center text-muted py-5">No data for this report.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo eh_h((string)($row['label'] ?? '')); ?></td>
                                    <td class="text-end fw-semibold">
                                        <?php
                                        $total = $row['total'] ?? 0;
                                        if (is_numeric($total) && str_contains($report, 'investment')) {
                                            echo eh_h(eh_money($total));
                                        } else {
                                            echo eh_h(is_numeric($total) ? number_format((float)$total, is_float($total + 0) && floor((float)$total) != (float)$total ? 2 : 0) : (string)$total);
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
