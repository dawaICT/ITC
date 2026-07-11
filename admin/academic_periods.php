<?php
require_once __DIR__ . '/includes/admin.php';

$page_title = 'Academic Period Release Windows';
$flash = '';
$flashType = 'success';

function ap_column_exists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $ok = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();
    return $ok;
}

$hasReleaseCols = ap_column_exists($db, 'academic_periods', 'registration_open')
    && ap_column_exists($db, 'academic_periods', 'docket_open')
    && ap_column_exists($db, 'academic_periods', 'exam_slip_open');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['period_id']) && $hasReleaseCols) {
    $periodId = (int)$_POST['period_id'];
    $regOpen = isset($_POST['registration_open']) ? 1 : 0;
    $docketOpen = isset($_POST['docket_open']) ? 1 : 0;
    $examOpen = isset($_POST['exam_slip_open']) ? 1 : 0;
    $setCurrent = isset($_POST['is_current']) ? 1 : 0;

    if ($setCurrent && ($stmt = $db->prepare('SELECT period_type FROM academic_periods WHERE id = ? LIMIT 1'))) {
        $stmt->bind_param('i', $periodId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $ptype = (string)$row['period_type'];
            $db->query("UPDATE academic_periods SET is_current = 0 WHERE period_type = '" . $db->real_escape_string($ptype) . "'");
        }
    }

    if ($stmt = $db->prepare(
        'UPDATE academic_periods SET registration_open = ?, docket_open = ?, exam_slip_open = ?, is_current = ?
         WHERE id = ?'
    )) {
        $stmt->bind_param('iiiii', $regOpen, $docketOpen, $examOpen, $setCurrent, $periodId);
        if ($stmt->execute()) {
            $flash = 'Academic period updated.';
            $flashType = 'success';
        } else {
            $flash = 'Update failed.';
            $flashType = 'danger';
        }
        $stmt->close();
    }
}

$periods = [];
if ($res = $db->query(
    'SELECT id, academic_year, period_type, period_name, period_number, semester_term,
            is_current, status, registration_open, docket_open, exam_slip_open,
            start_date, end_date
     FROM academic_periods
     ORDER BY academic_year DESC, period_type, period_number'
)) {
    while ($row = $res->fetch_assoc()) {
        $periods[] = $row;
    }
    $res->free();
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid px-4 portal-dashboard">
    <div class="page-header mb-4 mt-2">
        <h5 class="page-title mb-0"><i class="fas fa-calendar-alt me-2 text-primary"></i>Academic Period Release Windows</h5>
        <p class="page-subtitle mb-0">Control registration, test docket, and exam slip availability per period. Fee rules: 50% for Term/Semester 1, 100% for later periods.</p>
    </div>

    <?php if (!$hasReleaseCols): ?>
        <div class="alert alert-warning">Run <code>scripts/run_student_academic_workflow_migration.php</code> to add release-window columns.</div>
    <?php endif; ?>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Year</th>
                        <th>Period</th>
                        <th>Status</th>
                        <th>Registration</th>
                        <th>Test Docket</th>
                        <th>Exam Slip</th>
                        <th>Current</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($periods as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$p['academic_year'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(ucfirst((string)$p['period_type']) . ' ' . (string)($p['period_name'] ?? $p['semester_term'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$p['status'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td colspan="4">
                            <form method="post" class="row g-2 align-items-center">
                                <input type="hidden" name="period_id" value="<?= (int)$p['id'] ?>">
                                <div class="col-auto form-check">
                                    <input class="form-check-input" type="checkbox" name="registration_open" id="reg_<?= (int)$p['id'] ?>" <?= !empty($p['registration_open']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="reg_<?= (int)$p['id'] ?>">Open</label>
                                </div>
                                <div class="col-auto form-check">
                                    <input class="form-check-input" type="checkbox" name="docket_open" id="dock_<?= (int)$p['id'] ?>" <?= !empty($p['docket_open']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="dock_<?= (int)$p['id'] ?>">Docket</label>
                                </div>
                                <div class="col-auto form-check">
                                    <input class="form-check-input" type="checkbox" name="exam_slip_open" id="exam_<?= (int)$p['id'] ?>" <?= !empty($p['exam_slip_open']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="exam_<?= (int)$p['id'] ?>">Exam slip</label>
                                </div>
                                <div class="col-auto form-check">
                                    <input class="form-check-input" type="checkbox" name="is_current" id="cur_<?= (int)$p['id'] ?>" <?= !empty($p['is_current']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cur_<?= (int)$p['id'] ?>">Current</label>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
