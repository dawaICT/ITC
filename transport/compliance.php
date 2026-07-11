<?php
declare(strict_types=1);
/**
 * Compliance & Licensing — renewals-due dashboard (instructors, vehicles,
 * trainee licences), trainee licence/medical management, and per-cohort
 * TEVETA/RTSA compliance summary.
 */
require_once __DIR__ . '/includes/transport.php';
require_once __DIR__ . '/includes/teveta_helpers.php';

$page_title = 'Compliance & Licensing';
$LICENCE_TYPES = ['medical', 'provisional', 'full', 'psv', 'forklift', 'other'];
$LICENCE_STATUS = ['valid', 'expired', 'pending', 'revoked'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!tev_verify_csrf()) {
        tev_flash_set('danger', 'Security token mismatch. Please refresh and try again.');
        tev_redirect_self();
    }
    try {
        if (($_POST['action'] ?? '') === 'add_licence') {
            $traineeId = (int)($_POST['trainee_id'] ?? 0);
            $type = (string)($_POST['licence_type'] ?? 'provisional');
            $number = trim((string)($_POST['licence_number'] ?? ''));
            $issue = trim((string)($_POST['issue_date'] ?? ''));
            $expiry = trim((string)($_POST['expiry_date'] ?? ''));
            $status = (string)($_POST['status'] ?? 'valid');
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($traineeId <= 0) { throw new RuntimeException('Select a trainee.'); }
            if (!in_array($type, $LICENCE_TYPES, true)) { $type = 'other'; }
            if (!in_array($status, $LICENCE_STATUS, true)) { $status = 'valid'; }
            $issueVal = ($issue !== '' && DateTimeImmutable::createFromFormat('Y-m-d', $issue)) ? $issue : null;
            $expiryVal = ($expiry !== '' && DateTimeImmutable::createFromFormat('Y-m-d', $expiry)) ? $expiry : null;

            $chk = $db->prepare("SELECT 1 FROM transport_trainees WHERE id = ? LIMIT 1");
            $chk->bind_param('i', $traineeId);
            $chk->execute();
            if (!$chk->get_result()->fetch_row()) { $chk->close(); throw new RuntimeException('Trainee not found.'); }
            $chk->close();

            $stmt = $db->prepare("INSERT INTO transport_trainee_licences (trainee_id, licence_type, licence_number, issue_date, expiry_date, status, notes) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('issssss', $traineeId, $type, $number, $issueVal, $expiryVal, $status, $notes);
            $stmt->execute();
            $stmt->close();
            tev_flash_set('success', 'Trainee licence record saved.');
        }
    } catch (Throwable $e) {
        error_log('compliance.php: ' . $e->getMessage());
        tev_flash_set('danger', $e->getMessage());
    }
    tev_redirect_self();
}

// ---- Renewals due (expired or within 60 days) ----
$WINDOW = 60;
$instructorRenewals = $db->query("
    SELECT full_name, 'RTSA' AS kind, rtsa_expiry AS expiry FROM transport_instructors
      WHERE rtsa_expiry IS NOT NULL AND rtsa_expiry <= DATE_ADD(CURDATE(), INTERVAL {$WINDOW} DAY)
    UNION ALL
    SELECT full_name, 'TEVETA' AS kind, teveta_expiry AS expiry FROM transport_instructors
      WHERE teveta_expiry IS NOT NULL AND teveta_expiry <= DATE_ADD(CURDATE(), INTERVAL {$WINDOW} DAY)
    ORDER BY expiry
");
$vehicleRenewals = $db->query("
    SELECT registration_no, 'Fitness (COF)' AS kind, fitness_expiry AS expiry FROM transport_vehicles
      WHERE fitness_expiry IS NOT NULL AND fitness_expiry <= DATE_ADD(CURDATE(), INTERVAL {$WINDOW} DAY)
    UNION ALL
    SELECT registration_no, 'Insurance' AS kind, insurance_expiry AS expiry FROM transport_vehicles
      WHERE insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL {$WINDOW} DAY)
    ORDER BY expiry
");
$licenceRenewals = $db->query("
    SELECT CONCAT(t.first_name,' ',t.last_name) AS name, l.licence_type AS kind, l.expiry_date AS expiry, l.licence_number
    FROM transport_trainee_licences l
    INNER JOIN transport_trainees t ON t.id = l.trainee_id
    WHERE l.expiry_date IS NOT NULL AND l.status <> 'revoked' AND l.expiry_date <= DATE_ADD(CURDATE(), INTERVAL {$WINDOW} DAY)
    ORDER BY l.expiry_date
");

function tev_expiry_badge(?string $d): string
{
    if (!$d) { return ''; }
    $days = (int)((strtotime($d) - strtotime('today')) / 86400);
    if ($days < 0) { return '<span class="badge bg-danger">Expired ' . tev_h($d) . '</span>'; }
    if ($days <= 30) { return '<span class="badge bg-warning text-dark">Due ' . tev_h($d) . '</span>'; }
    return '<span class="badge bg-info text-dark">' . tev_h($d) . '</span>';
}

$trainees = [];
$res = $db->query("SELECT id, first_name, last_name, student_id FROM transport_trainees ORDER BY last_name, first_name");
while ($r = $res->fetch_assoc()) { $trainees[] = $r; }

$licences = $db->query("
    SELECT l.*, CONCAT(t.first_name,' ',t.last_name) AS trainee_name, t.student_id
    FROM transport_trainee_licences l
    INNER JOIN transport_trainees t ON t.id = l.trainee_id
    ORDER BY l.id DESC LIMIT 50
");

// ---- Per-cohort compliance summary ----
$cohortCompliance = $db->query("
    SELECT c.id, c.cohort_name, p.program_name, p.program_code, p.required_contact_hours,
           COALESCE(p.teveta_regulated,0) AS teveta_regulated, COALESCE(p.rtsa_regulated,0) AS rtsa_regulated,
           (SELECT COUNT(*) FROM transport_enrollments e WHERE e.cohort_id=c.id) AS enrolled,
           (SELECT COUNT(*) FROM transport_enrollments e WHERE e.cohort_id=c.id AND e.certificate_issued=1) AS certified,
           (SELECT COALESCE(SUM(s.contact_hours),0) FROM transport_sessions s WHERE s.cohort_id=c.id AND s.status='completed') AS delivered_hours,
           (SELECT COUNT(*) FROM transport_assessments a INNER JOIN transport_enrollments e ON e.id=a.enrollment_id WHERE e.cohort_id=c.id AND a.result='pass') AS passes
    FROM transport_cohorts c
    INNER JOIN transport_programs p ON p.id=c.program_id
    ORDER BY c.start_date DESC, c.id DESC
");

require_once __DIR__ . '/includes/nav.php';
?>
<div class="container-fluid py-3">
    <h3 class="mb-1"><i class="fas fa-shield-halved me-2"></i>Compliance &amp; Licensing</h3>
    <p class="text-muted">Renewals due, trainee licences/medicals, and per-cohort TEVETA/RTSA compliance.</p>
    <?php echo tev_flash_render(); ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-4"><div class="card h-100"><div class="card-header"><strong><i class="fas fa-id-card me-2"></i>Instructor renewals (60d)</strong></div>
            <div class="card-body p-0"><table class="table table-sm mb-0"><tbody>
                <?php if(!$instructorRenewals || !$instructorRenewals->num_rows): ?><tr><td class="text-muted text-center py-3">None due.</td></tr>
                <?php else: while($r=$instructorRenewals->fetch_assoc()): ?><tr><td><?php echo tev_h($r['full_name']); ?> <span class="text-muted small"><?php echo tev_h($r['kind']); ?></span><div><?php echo tev_expiry_badge($r['expiry']); ?></div></td></tr><?php endwhile; endif; ?>
            </tbody></table></div></div></div>
        <div class="col-lg-4"><div class="card h-100"><div class="card-header"><strong><i class="fas fa-truck me-2"></i>Vehicle renewals (60d)</strong></div>
            <div class="card-body p-0"><table class="table table-sm mb-0"><tbody>
                <?php if(!$vehicleRenewals || !$vehicleRenewals->num_rows): ?><tr><td class="text-muted text-center py-3">None due.</td></tr>
                <?php else: while($r=$vehicleRenewals->fetch_assoc()): ?><tr><td><?php echo tev_h($r['registration_no']); ?> <span class="text-muted small"><?php echo tev_h($r['kind']); ?></span><div><?php echo tev_expiry_badge($r['expiry']); ?></div></td></tr><?php endwhile; endif; ?>
            </tbody></table></div></div></div>
        <div class="col-lg-4"><div class="card h-100"><div class="card-header"><strong><i class="fas fa-id-badge me-2"></i>Trainee licence renewals (60d)</strong></div>
            <div class="card-body p-0"><table class="table table-sm mb-0"><tbody>
                <?php if(!$licenceRenewals || !$licenceRenewals->num_rows): ?><tr><td class="text-muted text-center py-3">None due.</td></tr>
                <?php else: while($r=$licenceRenewals->fetch_assoc()): ?><tr><td><?php echo tev_h($r['name']); ?> <span class="text-muted small text-capitalize"><?php echo tev_h($r['kind']); ?></span><div><?php echo tev_expiry_badge($r['expiry']); ?></div></td></tr><?php endwhile; endif; ?>
            </tbody></table></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><strong><i class="fas fa-plus me-2"></i>Record trainee licence / medical</strong></div>
                <div class="card-body">
                    <?php if(!$trainees): ?>
                        <div class="alert alert-info mb-0">No trainees yet. Enrol a trainee first.</div>
                    <?php else: ?>
                    <form method="post" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?php echo tev_h(tev_csrf_token()); ?>">
                        <input type="hidden" name="action" value="add_licence">
                        <div class="col-12"><label class="form-label">Trainee</label>
                            <select name="trainee_id" class="form-select" required>
                                <option value="">Select trainee</option>
                                <?php foreach($trainees as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo tev_h(trim($t['first_name'].' '.$t['last_name']).' ('.$t['student_id'].')'); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6"><label class="form-label">Type</label>
                            <select name="licence_type" class="form-select"><?php foreach($LICENCE_TYPES as $t): ?><option value="<?php echo $t; ?>"><?php echo tev_h(ucfirst($t)); ?></option><?php endforeach; ?></select>
                        </div>
                        <div class="col-6"><label class="form-label">Number</label><input class="form-control" name="licence_number" maxlength="80"></div>
                        <div class="col-6"><label class="form-label">Issue date</label><input type="date" class="form-control" name="issue_date"></div>
                        <div class="col-6"><label class="form-label">Expiry date</label><input type="date" class="form-control" name="expiry_date"></div>
                        <div class="col-6"><label class="form-label">Status</label>
                            <select name="status" class="form-select"><?php foreach($LICENCE_STATUS as $s): ?><option value="<?php echo $s; ?>"><?php echo tev_h(ucfirst($s)); ?></option><?php endforeach; ?></select>
                        </div>
                        <div class="col-12"><label class="form-label">Notes</label><input class="form-control" name="notes" maxlength="255"></div>
                        <div class="col-12 mt-2"><button class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>Save licence record</button></div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card mb-4">
                <div class="card-header"><strong>Recent trainee licences</strong></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Trainee</th><th>Type</th><th>Number</th><th>Expiry</th><th>Status</th></tr></thead><tbody>
                        <?php if(!$licences || !$licences->num_rows): ?><tr><td colspan="5" class="text-muted text-center py-3">No licence records yet.</td></tr>
                        <?php else: while($l=$licences->fetch_assoc()): ?>
                            <tr><td><?php echo tev_h($l['trainee_name']); ?></td><td class="text-capitalize"><?php echo tev_h($l['licence_type']); ?></td><td><?php echo tev_h($l['licence_number'] ?: '—'); ?></td><td><?php echo $l['expiry_date']?tev_expiry_badge($l['expiry_date']):'—'; ?></td><td class="text-capitalize"><?php echo tev_h($l['status']); ?></td></tr>
                        <?php endwhile; endif; ?>
                    </tbody></table></div></div>
            </div>
            <div class="card">
                <div class="card-header"><strong><i class="fas fa-clipboard-list me-2"></i>Cohort compliance summary</strong></div>
                <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Cohort</th><th>Program</th><th>Enrolled</th><th>Hours</th><th>Passes</th><th>Certified</th><th>Reg.</th></tr></thead><tbody>
                        <?php if(!$cohortCompliance || !$cohortCompliance->num_rows): ?><tr><td colspan="7" class="text-muted text-center py-3">No cohorts yet.</td></tr>
                        <?php else: while($c=$cohortCompliance->fetch_assoc()):
                            $req=(float)$c['required_contact_hours']; $del=(float)$c['delivered_hours'];
                            $hoursBadge = $req>0 ? ($del>=$req?'bg-success':'bg-warning text-dark') : 'bg-secondary'; ?>
                            <tr>
                                <td><?php echo tev_h($c['cohort_name']); ?></td>
                                <td class="small"><?php echo tev_h($c['program_code']); ?></td>
                                <td><?php echo (int)$c['enrolled']; ?></td>
                                <td><span class="badge <?php echo $hoursBadge; ?>"><?php echo number_format($del,1); ?><?php echo $req>0?'/'.number_format($req,1):''; ?>h</span></td>
                                <td><?php echo (int)$c['passes']; ?></td>
                                <td><?php echo (int)$c['certified']; ?>/<?php echo (int)$c['enrolled']; ?></td>
                                <td class="small"><?php echo ((int)$c['teveta_regulated']?'<span class="badge bg-info text-dark">TEVETA</span> ':'').((int)$c['rtsa_regulated']?'<span class="badge bg-primary">RTSA</span>':''); ?></td>
                            </tr>
                        <?php endwhile; endif; ?>
                    </tbody></table></div></div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
