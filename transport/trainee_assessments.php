<?php
declare(strict_types=1);
/**
 * Assessment & Evaluation — record written/practical/RTSA-readiness assessments
 * against a trainee's cohort enrolment (transport_assessments), and surface
 * certificate eligibility.
 */
require_once __DIR__ . '/includes/transport.php';
require_once __DIR__ . '/includes/teveta_helpers.php';

$page_title = 'Trainee Assessments';

$ASSESS_TYPES = ['theory', 'practical', 'simulator', 'rtsa_ready', 'corporate_suitability'];
$RESULTS = ['pending', 'pass', 'fail', 'deferred'];

$selectedEnrollment = (int)($_GET['enrollment_id'] ?? $_POST['enrollment_id'] ?? 0);

// resolve current staff's instructor profile id (assessor)
$assessorId = null;
$staffId = (string)($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? '');
if ($staffId !== '') {
    $st = $db->prepare("SELECT id FROM transport_instructors WHERE staff_id = ? LIMIT 1");
    $st->bind_param('s', $staffId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) { $assessorId = (int)$row['id']; }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!tev_verify_csrf()) {
        tev_flash_set('danger', 'Security token mismatch. Please refresh and try again.');
        tev_redirect_self();
    }
    try {
        if (($_POST['action'] ?? '') === 'add_assessment') {
            $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
            $type = (string)($_POST['assessment_type'] ?? 'theory');
            $date = trim((string)($_POST['assessment_date'] ?? date('Y-m-d')));
            $score = ($_POST['score'] === '' || !isset($_POST['score'])) ? null : max(0, min(100, (float)$_POST['score']));
            $result = (string)($_POST['result'] ?? 'pending');
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($enrollmentId <= 0) { throw new RuntimeException('Select a trainee enrolment.'); }
            if (!in_array($type, $ASSESS_TYPES, true)) { $type = 'theory'; }
            if (!in_array($result, $RESULTS, true)) { $result = 'pending'; }
            if (!DateTimeImmutable::createFromFormat('Y-m-d', $date)) { $date = date('Y-m-d'); }

            // verify enrolment exists
            $chk = $db->prepare("SELECT 1 FROM transport_enrollments WHERE id = ? LIMIT 1");
            $chk->bind_param('i', $enrollmentId);
            $chk->execute();
            if (!$chk->get_result()->fetch_row()) { $chk->close(); throw new RuntimeException('Enrolment not found.'); }
            $chk->close();

            $stmt = $db->prepare("INSERT INTO transport_assessments (enrollment_id, assessment_type, assessment_date, score, result, assessor_id, notes) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('issdsis', $enrollmentId, $type, $date, $score, $result, $assessorId, $notes);
            $stmt->execute();
            $stmt->close();
            tev_flash_set('success', 'Assessment recorded.');
            tev_redirect_to('trainee_assessments.php?enrollment_id=' . $enrollmentId);
        }
    } catch (Throwable $e) {
        error_log('trainee_assessments.php: ' . $e->getMessage());
        tev_flash_set('danger', $e->getMessage());
    }
    tev_redirect_self();
}

// Enrolment list with assessment + certificate status
$enrollments = tev_table_exists($db, 'transport_enrollments') ? $db->query("
    SELECT e.id, e.status, e.certificate_issued,
           t.first_name, t.last_name, t.student_id,
           c.cohort_name, p.program_name, p.program_code,
           (SELECT COUNT(*) FROM transport_assessments a WHERE a.enrollment_id = e.id) AS assessment_count,
           (SELECT COUNT(*) FROM transport_assessments a WHERE a.enrollment_id = e.id AND a.result='pass') AS passed_count
    FROM transport_enrollments e
    INNER JOIN transport_trainees t ON t.id = e.trainee_id
    INNER JOIN transport_cohorts c ON c.id = e.cohort_id
    INNER JOIN transport_programs p ON p.id = c.program_id
    ORDER BY e.id DESC
") : false;

$enrollmentRows = [];
if ($enrollments) { while ($r = $enrollments->fetch_assoc()) { $enrollmentRows[] = $r; } }

// Selected enrolment detail + assessment history
$selInfo = null;
$history = [];
if ($selectedEnrollment > 0) {
    foreach ($enrollmentRows as $er) { if ((int)$er['id'] === $selectedEnrollment) { $selInfo = $er; break; } }
    $h = $db->prepare("
        SELECT a.*, i.full_name AS assessor_name
        FROM transport_assessments a
        LEFT JOIN transport_instructors i ON i.id = a.assessor_id
        WHERE a.enrollment_id = ? ORDER BY a.assessment_date DESC, a.id DESC
    ");
    $h->bind_param('i', $selectedEnrollment);
    $h->execute();
    $hr = $h->get_result();
    while ($r = $hr->fetch_assoc()) { $history[] = $r; }
    $h->close();
}

require_once __DIR__ . '/includes/nav.php';
?>
<div class="container-fluid py-3">
    <h3 class="mb-1"><i class="fas fa-clipboard-check me-2"></i>Trainee Assessments</h3>
    <p class="text-muted">Record competency assessments and track certificate eligibility.</p>
    <?php echo tev_flash_render(); ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><strong>Enrolments</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead><tr><th>Trainee</th><th>Cohort / Program</th><th>Assess.</th><th>Cert.</th><th></th></tr></thead>
                            <tbody>
                                <?php if (!$enrollmentRows): ?>
                                    <tr><td colspan="5" class="text-muted text-center py-4">No trainee enrolments yet.</td></tr>
                                <?php else: foreach ($enrollmentRows as $er):
                                    $name = trim($er['first_name'].' '.$er['last_name']);
                                    $eligible = (int)$er['passed_count'] > 0; ?>
                                    <tr class="<?php echo (int)$er['id']===$selectedEnrollment?'table-active':''; ?>">
                                        <td><?php echo tev_h($name); ?><div class="text-muted small"><?php echo tev_h($er['student_id']); ?></div></td>
                                        <td class="small"><?php echo tev_h($er['cohort_name']); ?><div class="text-muted"><?php echo tev_h($er['program_code']); ?></div></td>
                                        <td><span class="badge bg-secondary"><?php echo (int)$er['passed_count']; ?>/<?php echo (int)$er['assessment_count']; ?></span></td>
                                        <td><?php echo (int)$er['certificate_issued']===1 ? '<span class="badge bg-success">Issued</span>' : ($eligible?'<span class="badge bg-warning text-dark">Eligible</span>':'<span class="badge bg-light text-dark">No</span>'); ?></td>
                                        <td><a class="btn btn-sm btn-outline-primary" href="trainee_assessments.php?enrollment_id=<?php echo (int)$er['id']; ?>">Open</a></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <?php if ($selInfo): $name = trim($selInfo['first_name'].' '.$selInfo['last_name']); $eligible=(int)$selInfo['passed_count']>0; ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Record assessment — <?php echo tev_h($name); ?></strong>
                    <?php if ((int)$selInfo['certificate_issued']===1): ?>
                        <span class="badge bg-success">Certificate issued</span>
                    <?php elseif ($eligible): ?>
                        <a class="btn btn-sm btn-success" href="certificate.php?enrollment_id=<?php echo $selectedEnrollment; ?>" target="_blank"><i class="fas fa-certificate me-1"></i>Generate certificate</a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?php echo tev_h(tev_csrf_token()); ?>">
                        <input type="hidden" name="action" value="add_assessment">
                        <input type="hidden" name="enrollment_id" value="<?php echo $selectedEnrollment; ?>">
                        <div class="col-6"><label class="form-label">Type</label>
                            <select name="assessment_type" class="form-select">
                                <?php foreach ($ASSESS_TYPES as $t): ?><option value="<?php echo $t; ?>"><?php echo tev_h(ucwords(str_replace('_',' ',$t))); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6"><label class="form-label">Date</label><input type="date" name="assessment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
                        <div class="col-6"><label class="form-label">Score (%)</label><input type="number" step="0.01" min="0" max="100" name="score" class="form-control" placeholder="optional"></div>
                        <div class="col-6"><label class="form-label">Result</label>
                            <select name="result" class="form-select">
                                <?php foreach ($RESULTS as $r): ?><option value="<?php echo $r; ?>" <?php echo $r==='pass'?'':''; ?>><?php echo tev_h(ucfirst($r)); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label">Notes / feedback</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                        <div class="col-12 mt-2"><button class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>Record assessment</button></div>
                    </form>
                    <div class="form-text mt-2">Assessor: <?php echo $assessorId ? 'your instructor profile' : 'no instructor profile linked to your staff ID'; ?>.</div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><strong>Assessment history</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>Date</th><th>Type</th><th>Score</th><th>Result</th><th>Assessor</th></tr></thead>
                            <tbody>
                                <?php if (!$history): ?>
                                    <tr><td colspan="5" class="text-muted text-center py-3">No assessments recorded.</td></tr>
                                <?php else: foreach ($history as $a):
                                    $rb = ['pass'=>'success','fail'=>'danger','deferred'=>'warning text-dark','pending'=>'secondary'][$a['result']] ?? 'secondary'; ?>
                                    <tr>
                                        <td><?php echo tev_h($a['assessment_date']); ?></td>
                                        <td class="text-capitalize"><?php echo tev_h(str_replace('_',' ',$a['assessment_type'])); ?></td>
                                        <td><?php echo $a['score']!==null?tev_h(number_format((float)$a['score'],1)):'—'; ?></td>
                                        <td><span class="badge bg-<?php echo $rb; ?>"><?php echo tev_h(ucfirst($a['result'])); ?></span></td>
                                        <td class="small"><?php echo tev_h($a['assessor_name'] ?: '—'); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <div class="card"><div class="card-body text-muted text-center py-5"><i class="fas fa-hand-pointer fa-2x mb-2 opacity-50"></i><p class="mb-0">Select an enrolment to record assessments.</p></div></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
