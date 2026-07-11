<?php
$page_title = 'Audit Logs';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/role_helpers.php';
require_once __DIR__ . '/../includes/itc_audit_log_helpers.php';

$staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$canViewAudit = (function_exists('isAdmin') && isAdmin($staffId))
    || hasPermission($staffId, 'admin_all')
    || hasPermission($staffId, 'audit.view');

$filters = itc_audit_normalize_filters($_GET);
$audit = ['rows' => [], 'total' => 0, 'total_pages' => 1];
$stats = ['total' => 0, 'today' => 0, 'week' => 0, 'users' => 0];
$options = ['modules' => [], 'actions' => []];
$error = '';

function audit_logs_query_url(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    return '?' . http_build_query($params);
}

function audit_logs_export_csv(array $filters, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    if (!$out) {
        return;
    }
    fputcsv($out, ['Exported At', date('Y-m-d H:i:s')]);
    fputcsv($out, ['User Filter', $filters['user_id']]);
    fputcsv($out, ['Module Filter', $filters['module']]);
    fputcsv($out, ['Action Filter', $filters['action']]);
    fputcsv($out, []);
    fputcsv($out, ['ID', 'Timestamp', 'User ID', 'Module', 'Action', 'Record ID', 'IP Address', 'Old Value', 'New Value', 'User Agent']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'],
            $row['created_at'],
            $row['user_id'],
            $row['module'],
            $row['action'],
            $row['record_id'],
            $row['ip_address'],
            $row['old_value'],
            $row['new_value'],
            $row['user_agent'],
        ]);
    }
    fclose($out);
}

if (!$canViewAudit) {
    http_response_code(403);
    $error = 'You do not have permission to view audit logs.';
} elseif (!itc_audit_table_exists($db, 'audit_logs')) {
    $error = 'The canonical audit log table is not available.';
} else {
    try {
        if (($_GET['export'] ?? '') === 'csv') {
            audit_logs_export_csv($filters, itc_audit_fetch_export($db, $filters));
            exit;
        }
        $audit = itc_audit_fetch($db, $filters);
        $stats = itc_audit_stats($db);
        $options = itc_audit_options($db);
    } catch (Throwable $e) {
        error_log('audit_logs.php failed: ' . $e->getMessage());
        $error = 'Audit logs could not be loaded. Please adjust the filters and try again.';
    }
}

require __DIR__ . '/includes/nav.php';
?>

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <div>
            <h1 class="dashboard-title">
                <i class="fas fa-shield-alt me-2"></i>Audit Logs
            </h1>
            <p class="text-muted mb-0">Canonical academic, access, report, and system audit trail.</p>
        </div>
        <div class="header-actions">
            <?php if ($canViewAudit && empty($error)): ?>
                <a class="btn btn-outline-primary rounded-pill px-3" href="<?php echo itc_audit_h(audit_logs_query_url(['export' => 'csv'])); ?>">
                    <i class="fas fa-file-csv me-1"></i>Export CSV
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo itc_audit_h($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($canViewAudit && $error === ''): ?>
        <div class="row g-3 mb-4">
            <?php
            $cards = [
                ['label' => 'Total Events', 'value' => $stats['total'] ?? 0, 'icon' => 'fa-database'],
                ['label' => 'Today', 'value' => $stats['today'] ?? 0, 'icon' => 'fa-calendar-day'],
                ['label' => 'Last 7 Days', 'value' => $stats['week'] ?? 0, 'icon' => 'fa-calendar-week'],
                ['label' => 'Users', 'value' => $stats['users'] ?? 0, 'icon' => 'fa-users'],
            ];
            ?>
            <?php foreach ($cards as $card): ?>
                <div class="col-xl-3 col-sm-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-body d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:44px;height:44px;">
                                <i class="fas <?php echo itc_audit_h($card['icon']); ?>"></i>
                            </div>
                            <div>
                                <div class="text-muted small"><?php echo itc_audit_h($card['label']); ?></div>
                                <div class="fs-4 fw-bold"><?php echo number_format((int)$card['value']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <form method="get" class="row g-3 align-items-end" id="auditFilterForm" data-trim-empty="1">
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">User ID</label>
                        <input class="form-control" name="user_id" value="<?php echo itc_audit_h($filters['user_id']); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Module</label>
                        <select class="form-select" name="module">
                            <option value="">All Modules</option>
                            <?php foreach ($options['modules'] as $row): ?>
                                <option value="<?php echo itc_audit_h($row['value']); ?>" <?php echo $filters['module'] === $row['value'] ? 'selected' : ''; ?>>
                                    <?php echo itc_audit_h($row['value']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Action</label>
                        <select class="form-select" name="action">
                            <option value="">All Actions</option>
                            <?php foreach ($options['actions'] as $row): ?>
                                <option value="<?php echo itc_audit_h($row['value']); ?>" <?php echo $filters['action'] === $row['value'] ? 'selected' : ''; ?>>
                                    <?php echo itc_audit_h($row['value']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Record ID</label>
                        <input class="form-control" name="record_id" value="<?php echo itc_audit_h($filters['record_id']); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">From</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo itc_audit_h($filters['date_from']); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">To</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo itc_audit_h($filters['date_to']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Search Payload</label>
                        <input class="form-control" name="q" value="<?php echo itc_audit_h($filters['q']); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Rows</label>
                        <select class="form-select" name="per_page">
                            <?php foreach ([20, 50, 100] as $size): ?>
                                <option value="<?php echo $size; ?>" <?php echo (int)$filters['per_page'] === $size ? 'selected' : ''; ?>><?php echo $size; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary rounded-pill w-100">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a class="btn btn-outline-secondary rounded-pill w-100" href="audit_logs.php">
                            <i class="fas fa-rotate-left me-1"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-clock-rotate-left me-2"></i>Events</strong>
                <span class="text-muted small"><?php echo number_format((int)$audit['total']); ?> matching rows</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User</th>
                            <th>Module</th>
                            <th>Action</th>
                            <th>Record</th>
                            <th>IP</th>
                            <th>Payload</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($audit['rows'] as $row): ?>
                        <tr>
                            <td class="text-nowrap"><?php echo itc_audit_h($row['created_at']); ?></td>
                            <td>
                                <strong><?php echo itc_audit_h($row['user_id']); ?></strong>
                                <?php if (trim((string)$row['staff_name']) !== ''): ?>
                                    <div class="text-muted small"><?php echo itc_audit_h($row['staff_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo itc_audit_h($row['module']); ?></span></td>
                            <td><?php echo itc_audit_h($row['action']); ?></td>
                            <td><?php echo itc_audit_h($row['record_id']); ?></td>
                            <td class="text-nowrap"><?php echo itc_audit_h($row['ip_address']); ?></td>
                            <td style="max-width:420px;">
                                <?php $old = itc_audit_preview($row['old_value']); $new = itc_audit_preview($row['new_value']); ?>
                                <?php if ($old !== ''): ?><div class="small text-muted">Old: <?php echo itc_audit_h($old); ?></div><?php endif; ?>
                                <?php if ($new !== ''): ?><div class="small">New: <?php echo itc_audit_h($new); ?></div><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$audit['rows']): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-shield-alt me-2"></i>No audit events match the selected filters.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($audit['total_pages'] > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                    <a class="btn btn-sm btn-outline-secondary rounded-pill <?php echo $filters['page'] <= 1 ? 'disabled' : ''; ?>" href="<?php echo itc_audit_h(audit_logs_query_url(['page' => max(1, $filters['page'] - 1)])); ?>">
                        <i class="fas fa-chevron-left me-1"></i>Previous
                    </a>
                    <span class="text-muted small">Page <?php echo (int)$filters['page']; ?> of <?php echo (int)$audit['total_pages']; ?></span>
                    <a class="btn btn-sm btn-outline-secondary rounded-pill <?php echo $filters['page'] >= $audit['total_pages'] ? 'disabled' : ''; ?>" href="<?php echo itc_audit_h(audit_logs_query_url(['page' => min($audit['total_pages'], $filters['page'] + 1)])); ?>">
                        Next<i class="fas fa-chevron-right ms-1"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Task 6 — send only required fields: strip empty inputs from any
// [data-trim-empty] filter form before submit so the request/URL carries only
// the filters the user actually set (server defaults missing keys anyway).
document.querySelectorAll('form[data-trim-empty]').forEach(function (form) {
    form.addEventListener('submit', function () {
        form.querySelectorAll('input, select').forEach(function (field) {
            if (field.name && field.value === '' && !field.disabled) {
                field.disabled = true; // disabled fields are not submitted
            }
        });
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
