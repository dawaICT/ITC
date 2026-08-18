<?php
declare(strict_types=1);

$page_title = 'Student Progression';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/hod_schema_helpers.php';
require_once dirname(__DIR__) . '/includes/student_year_progression.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$staffId = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
$deptContext = hod_resolve_department($db, $staffId);
$programCodes = hod_section_program_codes($db, $deptContext);
$students = wuc_progression_candidates($db, $programCodes);
$flash = $_SESSION['progression_flash'] ?? null;
unset($_SESSION['progression_flash']);
?>

<div class="container-fluid px-4 py-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-4">
        <h1 class="dashboard-title"><i class="fas fa-arrow-up-right-dots me-2 text-primary"></i>Student Academic Progression</h1>
        <p class="text-muted mb-0">Review internal CA evidence for students in your section, then record the external-results or examination-board decision that authorizes the next academic year.</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars((string)$flash['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars((string)$flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($programCodes === []): ?>
        <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-2"></i>Your HOS section is not linked to any academic programmes, so no progression decisions are available.</div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card shadow-sm border-0 h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="stat-icon bg-primary-subtle text-primary"><i class="fas fa-users"></i></div><div><div class="text-muted small">Section students for review</div><div class="fs-4 fw-bold"><?php echo count($students); ?></div></div></div></div></div>
        <div class="col-md-4"><div class="card shadow-sm border-0 h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="stat-icon bg-info-subtle text-info"><i class="fas fa-chart-column"></i></div><div><div class="text-muted small">Internal evidence</div><div class="fw-bold">Continuous Assessment</div></div></div></div></div>
        <div class="col-md-4"><div class="card shadow-sm border-0 h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="stat-icon bg-success-subtle text-success"><i class="fas fa-shield-halved"></i></div><div><div class="text-muted small">Scope</div><div class="fw-bold"><?php echo htmlspecialchars((string)($deptContext['section_name'] ?? $deptContext['name'] ?? 'Assigned section')); ?></div></div></div></div></div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center"><h5 class="mb-0"><i class="fas fa-list-check me-2 text-primary"></i>Progression Review</h5><span class="badge bg-primary"><?php echo count($students); ?></span></div>
        <div class="card-body p-0">
            <?php if ($students === []): ?>
                <div class="text-center text-muted py-5"><i class="fas fa-circle-check fa-2x mb-3"></i><p class="mb-0">No students in your section currently require year progression.</p></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Student</th><th>Current year</th><th>CA evidence</th><th>Next year</th><th class="text-end">Decision</th></tr></thead>
                    <tbody>
                    <?php foreach ($students as $student): $ca = $student['ca_evidence']; ?>
                        <tr>
                            <td><div class="fw-semibold"><?php echo htmlspecialchars(trim((string)$student['Fname'] . ' ' . (string)$student['Lname'])); ?></div><code><?php echo htmlspecialchars((string)$student['SID']); ?></code></td>
                            <td><div><?php echo htmlspecialchars((string)$student['program_name']); ?></div><span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)$student['program_code']); ?> · Year <?php echo (int)$student['year_of_study']; ?></span></td>
                            <td><div class="small fw-semibold"><?php echo (int)$ca['recorded']; ?> / <?php echo (int)$ca['required']; ?> recorded</div><div class="small text-muted"><?php echo (int)$ca['approved']; ?> approved · Average <?php echo $ca['average_ca'] === null ? '—' : number_format((float)$ca['average_ca'], 1); ?></div></td>
                            <td><span class="badge bg-success-subtle text-success border"><?php echo htmlspecialchars((string)$student['target_program_code']); ?> · Year <?php echo (int)$student['target_year_of_study']; ?></span><div class="small text-muted"><?php echo htmlspecialchars((string)$student['next_academic_year']); ?></div></td>
                            <td class="text-end"><button type="button" class="btn btn-sm btn-primary progression-open" data-bs-toggle="modal" data-bs-target="#hosProgressionModal" data-student-id="<?php echo htmlspecialchars((string)$student['SID'], ENT_QUOTES, 'UTF-8'); ?>" data-student-name="<?php echo htmlspecialchars(trim((string)$student['Fname'] . ' ' . (string)$student['Lname']), ENT_QUOTES, 'UTF-8'); ?>" data-target="<?php echo htmlspecialchars((string)$student['target_program_code'] . ' · Year ' . (int)$student['target_year_of_study'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-arrow-right me-1"></i>Review</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="hosProgressionModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-file-signature me-2"></i>HOS Progression Decision</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <form method="post" action="progress_student.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="student_id" id="hosProgressionStudentId">
        <div class="modal-body"><div class="alert alert-info"><strong id="hosProgressionStudentName"></strong> → <strong id="hosProgressionTarget"></strong>. Confirm the authoritative decision; CA is supporting evidence only.</div><div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Decision basis</label><select class="form-select" name="decision_basis" required><option value="external_results">External examination results</option><option value="examination_board">Examination board decision</option><option value="internal_ca_review">Internal CA review</option><option value="administrative">Administrative approval</option></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Decision / results reference</label><input class="form-control" name="decision_reference" maxlength="120" placeholder="e.g. TEVETA Results Sheet 2026-014" required></div>
            <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea class="form-control" name="decision_notes" rows="3" maxlength="2000" placeholder="Record carried modules or board conditions."></textarea></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" onclick="return confirm('Confirm this HOS progression decision?')"><i class="fas fa-check me-1"></i>Approve Progression</button></div>
    </form>
</div></div></div>

<script>
document.querySelectorAll('.progression-open').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('hosProgressionStudentId').value = button.dataset.studentId || '';
        document.getElementById('hosProgressionStudentName').textContent = button.dataset.studentName || '';
        document.getElementById('hosProgressionTarget').textContent = button.dataset.target || '';
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

