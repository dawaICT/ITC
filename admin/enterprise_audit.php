<?php
declare(strict_types=1);

$page_title = 'Enterprise Audit';
require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/role_helpers.php';
require_once dirname(__DIR__) . '/includes/enterprise_services.php';

if (!function_exists('canAccessSettings') || !canAccessSettings()) {
    $_SESSION['errorMessage'] = 'Access denied. Settings permission is required.';
    wuc_safe_redirect('/wucportal/admin/index.php');
}

require_once __DIR__ . '/includes/header.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

$scanNotice = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'run_integrity_scan') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $scanNotice = 'Security token mismatch. Please try again.';
    } elseif (!wuc_enterprise_ready($db)) {
        $scanNotice = 'Enterprise foundation tables are not installed. Run the 20260701 enterprise migration first.';
    } else {
        $detected = wuc_integrity_run_basic($db);
        $scanNotice = count($detected) === 0
            ? 'Integrity scan completed. No new exceptions were detected.'
            : 'Integrity scan completed. ' . count($detected) . ' exception(s) were recorded or refreshed.';
    }
}

$foundationTables = [
    'business_rules' => 'Business Rules',
    'academic_calendar_events' => 'Academic Calendar',
    'notifications' => 'Notifications',
    'approval_workflows' => 'Approval Workflows',
    'approval_requests' => 'Approval Requests',
    'approval_actions' => 'Approval Actions',
    'document_repository' => 'Document Repository',
    'data_integrity_exceptions' => 'Integrity Exceptions',
    'background_jobs' => 'Background Jobs',
    'academic_record_versions' => 'Academic Record Versions',
];

$tableStatus = [];
foreach ($foundationTables as $table => $label) {
    $tableStatus[$table] = wuc_ent_table_exists($db, $table);
}

$exceptions = [];
$exceptionCounts = ['open' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
if (wuc_ent_table_exists($db, 'data_integrity_exceptions')) {
    $res = $db->query(
        "SELECT id, exception_key, module_name, severity, entity_type, entity_id, message, status, detected_at
           FROM data_integrity_exceptions
          ORDER BY status = 'open' DESC, FIELD(severity, 'critical', 'high', 'medium', 'low') ASC, detected_at DESC
          LIMIT 100"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $exceptions[] = $row;
            $status = strtolower((string)$row['status']);
            $severity = strtolower((string)$row['severity']);
            if ($status === 'open') {
                $exceptionCounts['open']++;
            }
            if (isset($exceptionCounts[$severity])) {
                $exceptionCounts[$severity]++;
            }
        }
        $res->free();
    }
}
?>

<div class="container-fluid px-4 py-4 portal-dashboard">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <div>
            <h1 class="page-title mb-1"><i class="fas fa-shield-halved me-2 text-primary"></i>Enterprise Audit</h1>
            <p class="text-muted mb-0">Production readiness foundations, exception tracking, and integrity checks.</p>
        </div>
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="run_integrity_scan">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-rotate me-1"></i>Run Integrity Scan
            </button>
        </form>
    </div>

    <?php if ($scanNotice !== ''): ?>
        <div class="alert <?php echo stripos($scanNotice, 'mismatch') !== false ? 'alert-danger' : 'alert-info'; ?>">
            <?php echo htmlspecialchars($scanNotice, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Open Exceptions</div>
                    <div class="h3 mb-0"><?php echo number_format($exceptionCounts['open']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">High Severity</div>
                    <div class="h3 mb-0 text-danger"><?php echo number_format($exceptionCounts['high']); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Foundation Tables</div>
                    <div class="h3 mb-0"><?php echo number_format(count(array_filter($tableStatus))); ?>/<?php echo count($tableStatus); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Audit Mode</div>
                    <div class="h6 mb-0"><?php echo wuc_ent_column_exists($db, 'audit_logs', 'event_hash') ? 'Tamper-evident' : 'Basic'; ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <h5 class="mb-0"><i class="fas fa-layer-group me-2 text-primary"></i>Foundation Status</h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <?php foreach ($foundationTables as $table => $label): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <span><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="badge <?php echo $tableStatus[$table] ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                    <?php echo $tableStatus[$table] ? 'Ready' : 'Missing'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-triangle-exclamation me-2 text-primary"></i>Latest Integrity Exceptions</h5>
                    <span class="badge bg-light text-dark"><?php echo count($exceptions); ?> shown</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($exceptions)): ?>
                        <div class="empty-state p-5 text-center text-muted">
                            <i class="fas fa-check-circle fa-2x mb-2"></i>
                            <p class="mb-0">No integrity exceptions recorded.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Severity</th>
                                        <th>Module</th>
                                        <th>Entity</th>
                                        <th>Message</th>
                                        <th>Status</th>
                                        <th>Detected</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($exceptions as $exception): ?>
                                    <tr>
                                        <td><span class="badge bg-<?php echo strtolower((string)$exception['severity']) === 'high' ? 'danger' : 'secondary'; ?>"><?php echo htmlspecialchars((string)$exception['severity'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><?php echo htmlspecialchars((string)$exception['module_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <span class="d-block"><?php echo htmlspecialchars((string)$exception['entity_type'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <small class="text-muted"><?php echo htmlspecialchars((string)($exception['entity_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)$exception['message'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)$exception['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><small><?php echo htmlspecialchars((string)$exception['detected_at'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
