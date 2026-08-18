<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';
require_once dirname(__DIR__, 2) . '/includes/role_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/student_year_progression.php';

requireRole([ROLE_SYSTEMS_ADMIN], '/wucportal/portal_selection.php');
$page_title = 'Progression & Graduation';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['progress_student'])) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
        $_SESSION['progression_flash'] = ['type' => 'danger', 'message' => 'Invalid request. Please refresh and try again.'];
    } else {
        $result = wuc_progress_student_year(
            $db,
            (string)($_POST['student_id'] ?? ''),
            (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'admin'),
            ROLE_SYSTEMS_ADMIN,
            (string)($_POST['decision_basis'] ?? ''),
            (string)($_POST['decision_reference'] ?? ''),
            (string)($_POST['decision_notes'] ?? '')
        );
        $_SESSION['progression_flash'] = ['type' => $result['ok'] ? 'success' : 'warning', 'message' => $result['message']];
    }
    header('Location: progression.php', true, 303);
    exit();
}

$evaluated = false;
$results = [];
$min_gpa = 2.0;
$min_credits = 12;
$max_fails = 2;
$evalError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evaluate_eligibility'])) {
    $min_gpa = (float)($_POST['min_gpa'] ?? 2.0);
    $min_credits = (int)($_POST['min_credits'] ?? 12);
    $max_fails = (int)($_POST['max_fails'] ?? 2);
}

if (isset($_GET['min_gpa']) || isset($_GET['min_credits']) || isset($_GET['max_fails']) || isset($_GET['evaluate'])) {
    $min_gpa = (float)($_GET['min_gpa'] ?? $min_gpa);
    $min_credits = (int)($_GET['min_credits'] ?? $min_credits);
    $max_fails = (int)($_GET['max_fails'] ?? $max_fails);
}

try {
    // Always evaluate every active student so clearance status is visible without an extra click.
    $results = wuc_progression_evaluate_all($db, $min_gpa, $min_credits, $max_fails);
    $evaluated = true;
} catch (Throwable $e) {
    error_log('[Progression evaluate] ' . $e->getMessage());
    $evalError = 'Unable to evaluate student progression against the live academic records.';
}

$yearCandidates = wuc_progression_candidates($db);
$flash = $_SESSION['progression_flash'] ?? null;
unset($_SESSION['progression_flash']);

$clearedCount = 0;
foreach ($results as $row) {
    if (!empty($row['eligible'])) {
        $clearedCount++;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <h1 class="dashboard-title"><i class="fas fa-user-graduate me-2 text-primary"></i>Progression &amp; Graduation</h1>
        <p class="text-muted mb-0">
            Evaluate clearance for all active students, then approve audited academic-year progression where a next stage exists.
        </p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars((string)$flash['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars((string)$flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($evalError): ?>
        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($evalError); ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-semibold"><i class="fas fa-sliders-h me-2 text-secondary"></i>Eligibility Evaluation Parameters</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="" class="row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Min Cumulative GPA</label>
                    <input type="number" step="0.1" min="0" max="4" value="<?php echo htmlspecialchars((string)$min_gpa); ?>" class="form-control" name="min_gpa" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Min Earned Credits</label>
                    <input type="number" min="0" max="200" value="<?php echo htmlspecialchars((string)$min_credits); ?>" class="form-control" name="min_credits" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Max Fails Allowed</label>
                    <input type="number" min="0" max="10" value="<?php echo htmlspecialchars((string)$max_fails); ?>" class="form-control" name="max_fails" required>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary w-100 py-2" type="submit" name="evaluate_eligibility" value="1">
                        <i class="fas fa-calculator me-2"></i>Re-evaluate All Students
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($evaluated): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100"><div class="card-body d-flex gap-3 align-items-center">
                    <div class="stat-icon bg-primary-subtle text-primary"><i class="fas fa-users"></i></div>
                    <div><div class="text-muted small">Students evaluated</div><div class="fs-4 fw-bold"><?php echo count($results); ?></div></div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100"><div class="card-body d-flex gap-3 align-items-center">
                    <div class="stat-icon bg-success-subtle text-success"><i class="fas fa-check-circle"></i></div>
                    <div><div class="text-muted small">Cleared</div><div class="fs-4 fw-bold"><?php echo $clearedCount; ?></div></div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 h-100"><div class="card-body d-flex gap-3 align-items-center">
                    <div class="stat-icon bg-danger-subtle text-danger"><i class="fas fa-times-circle"></i></div>
                    <div><div class="text-muted small">Ineligible</div><div class="fs-4 fw-bold"><?php echo count($results) - $clearedCount; ?></div></div>
                </div></div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-semibold"><i class="fas fa-table me-2 text-secondary"></i>All-Student Progression Results</h5>
                <span class="badge bg-primary"><?php echo count($results); ?> student(s)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Student ID</th>
                                <th>Full Name</th>
                                <th>Program</th>
                                <th class="text-center">Modules</th>
                                <th class="text-center">Cumulative GPA</th>
                                <th class="text-center">Earned Credits</th>
                                <th class="text-center">Fails</th>
                                <th class="text-center">Clearance Status</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($results === []): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No active students found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($results as $r): ?>
                                <tr>
                                    <td class="fw-semibold"><code><?php echo htmlspecialchars((string)$r['SID']); ?></code></td>
                                    <td><?php echo htmlspecialchars((string)$r['name']); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)$r['program_code']); ?></span></td>
                                    <td class="text-center">
                                        <?php echo (int)$r['modules']; ?>
                                        <?php if ((int)($r['awaiting_exam'] ?? 0) > 0): ?>
                                            <div class="small text-muted"><?php echo (int)$r['awaiting_exam']; ?> awaiting exam</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-bold"><?php echo number_format((float)$r['gpa'], 2); ?></td>
                                    <td class="text-center"><?php echo (int)$r['earned_credits']; ?></td>
                                    <td class="text-center">
                                        <?php if ((int)$r['fails'] > 0): ?>
                                            <span class="badge bg-danger"><?php echo (int)$r['fails']; ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($r['eligible'])): ?>
                                            <span class="badge bg-success py-2 px-3"><i class="fas fa-check-circle me-1"></i>Cleared</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger py-2 px-3"><i class="fas fa-times-circle me-1"></i>Ineligible</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?php echo htmlspecialchars((string)($r['reason'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100"><div class="card-body d-flex gap-3 align-items-center">
                <div class="stat-icon bg-primary-subtle text-primary"><i class="fas fa-arrow-up-right-dots"></i></div>
                <div><div class="text-muted small">Eligible for year progression</div><div class="fs-4 fw-bold"><?php echo count($yearCandidates); ?></div></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100"><div class="card-body d-flex gap-3 align-items-center">
                <div class="stat-icon bg-info-subtle text-info"><i class="fas fa-chart-column"></i></div>
                <div><div class="text-muted small">Evidence source</div><div class="fw-bold">Internal CA</div></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100"><div class="card-body d-flex gap-3 align-items-center">
                <div class="stat-icon bg-success-subtle text-success"><i class="fas fa-file-signature"></i></div>
                <div><div class="text-muted small">Decision authority</div><div class="fw-bold">HOS / Systems Admin</div></div>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-semibold"><i class="fas fa-list-check me-2 text-primary"></i>Students Awaiting Year Progression</h5>
            <span class="badge bg-primary"><?php echo count($yearCandidates); ?> student(s)</span>
        </div>
        <div class="card-body p-0">
            <?php if ($yearCandidates === []): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-circle-check fa-2x mb-3"></i>
                    <p class="mb-0">No students currently require academic-year progression.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr>
                            <th>Student</th><th>Current programme/year</th><th>CA evidence</th>
                            <th>Next stage</th><th>Exam model</th><th class="text-end">Decision</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($yearCandidates as $student): $ca = $student['ca_evidence']; ?>
                            <tr>
                                <td><div class="fw-semibold"><?php echo htmlspecialchars(trim((string)$student['Fname'] . ' ' . (string)$student['Lname'])); ?></div><code><?php echo htmlspecialchars((string)$student['SID']); ?></code></td>
                                <td><div class="fw-semibold"><?php echo htmlspecialchars((string)$student['program_name']); ?></div><span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)$student['program_code']); ?> · Year <?php echo (int)$student['year_of_study']; ?> · <?php echo htmlspecialchars((string)$student['academic_year']); ?></span></td>
                                <td>
                                    <div class="small fw-semibold"><?php echo (int)$ca['recorded']; ?> / <?php echo (int)$ca['required']; ?> modules recorded</div>
                                    <div class="small text-muted"><?php echo (int)$ca['approved']; ?> approved · Average CA <?php echo $ca['average_ca'] === null ? '—' : number_format((float)$ca['average_ca'], 1); ?></div>
                                </td>
                                <td><span class="badge bg-success-subtle text-success border border-success-subtle"><?php echo htmlspecialchars((string)$student['target_program_code']); ?> · Year <?php echo (int)$student['target_year_of_study']; ?></span><div class="small text-muted mt-1"><?php echo htmlspecialchars((string)$student['next_academic_year']); ?></div></td>
                                <td><span class="badge bg-<?php echo strtolower((string)$student['examination_type']) === 'external' ? 'warning text-dark' : 'info'; ?>"><?php echo htmlspecialchars(ucfirst((string)$student['examination_type'])); ?></span></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-primary progression-open"
                                            data-bs-toggle="modal" data-bs-target="#progressionModal"
                                            data-student-id="<?php echo htmlspecialchars((string)$student['SID'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-student-name="<?php echo htmlspecialchars(trim((string)$student['Fname'] . ' ' . (string)$student['Lname']), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-target="<?php echo htmlspecialchars((string)$student['target_program_code'] . ' · Year ' . (int)$student['target_year_of_study'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas fa-arrow-right me-1"></i>Review &amp; Progress
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-semibold"><i class="fas fa-graduation-cap me-2 text-secondary"></i>Related Clearance Tools</h5>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
                <a href="../student_progression_report.php" class="btn btn-outline-primary py-2 px-3"><i class="fas fa-triangle-exclamation me-2"></i>Progression Alerts</a>
                <a href="../admittedStud_report.php" class="btn btn-outline-secondary py-2 px-3"><i class="fas fa-user-check me-2"></i>Check Academic Status</a>
                <a href="../../accounts/fees_student_accounts.php" class="btn btn-outline-secondary py-2 px-3"><i class="fas fa-wallet me-2"></i>Verify Financial Accounts</a>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="progressionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-file-signature me-2"></i>Approve Academic Progression</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="student_id" id="progressionStudentId">
            <div class="modal-body">
                <div class="alert alert-info"><strong id="progressionStudentName"></strong> will progress to <strong id="progressionTarget"></strong>. Internal CA is evidence only; confirm the authoritative progression decision below.</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-semibold">Decision basis</label><select class="form-select" name="decision_basis" required><option value="external_results">External examination results</option><option value="examination_board">Examination board decision</option><option value="internal_ca_review">Internal CA review</option><option value="administrative">Administrative approval</option></select></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Decision / results reference</label><input class="form-control" name="decision_reference" maxlength="120" placeholder="e.g. TEVETA Results Sheet 2026-014" required></div>
                    <div class="col-12"><label class="form-label fw-semibold">Decision notes</label><textarea class="form-control" name="decision_notes" rows="3" maxlength="2000" placeholder="Record board conditions, carried modules, or other relevant notes."></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" name="progress_student" value="1" class="btn btn-primary" onclick="return confirm('Confirm this academic progression decision?')"><i class="fas fa-check me-1"></i>Approve Progression</button></div>
        </form>
    </div></div>
</div>

<script>
document.querySelectorAll('.progression-open').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('progressionStudentId').value = button.dataset.studentId || '';
        document.getElementById('progressionStudentName').textContent = button.dataset.studentName || '';
        document.getElementById('progressionTarget').textContent = button.dataset.target || '';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
