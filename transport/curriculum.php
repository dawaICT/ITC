<?php
declare(strict_types=1);
/**
 * TEVETA Curriculum Management — define syllabus modules per transport program,
 * map them to TEVETA learning outcomes, and track theory/practical contact hours
 * against the program requirement.
 */
require_once __DIR__ . '/includes/transport.php';      // auth + $db
require_once __DIR__ . '/includes/teveta_helpers.php';

$page_title = 'Curriculum Management';

$selectedProgram = (int)($_GET['program_id'] ?? $_POST['program_id'] ?? 0);

// ----- POST handling (PRG) -----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!tev_verify_csrf()) {
        tev_flash_set('danger', 'Security token mismatch. Please refresh and try again.');
        tev_redirect_self();
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add_module') {
            $programId = (int)($_POST['program_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $outcomeId = (int)($_POST['outcome_id'] ?? 0);
            $deliveryType = (string)($_POST['delivery_type'] ?? 'theory');
            $hours = max(0, (float)($_POST['contact_hours'] ?? 0));
            $sequence = max(1, (int)($_POST['sequence'] ?? 1));
            $version = trim((string)($_POST['version'] ?? '1.0'));
            if ($version === '') { $version = '1.0'; }

            if ($programId <= 0 || $title === '') {
                throw new RuntimeException('Program and module title are required.');
            }
            if (!in_array($deliveryType, ['theory', 'practical', 'assessment'], true)) {
                $deliveryType = 'theory';
            }
            $oid = $outcomeId > 0 ? $outcomeId : null;
            $stmt = $db->prepare("INSERT INTO transport_curriculum_modules (program_id, outcome_id, title, delivery_type, contact_hours, sequence, version) VALUES (?,?,?,?,?,?,?)");
            // types: program_id i, outcome_id i, title s, delivery_type s, contact_hours d, sequence i, version s
            $stmt->bind_param('iissdis', $programId, $oid, $title, $deliveryType, $hours, $sequence, $version);
            $stmt->execute();
            $stmt->close();
            tev_flash_set('success', 'Curriculum module added.');
        } elseif ($action === 'delete_module') {
            $moduleId = (int)($_POST['module_id'] ?? 0);
            if ($moduleId > 0) {
                $stmt = $db->prepare("DELETE FROM transport_curriculum_modules WHERE id = ?");
                $stmt->bind_param('i', $moduleId);
                $stmt->execute();
                $stmt->close();
                tev_flash_set('success', 'Module removed.');
            }
        }
    } catch (Throwable $e) {
        error_log('curriculum.php: ' . $e->getMessage());
        tev_flash_set('danger', $e->getMessage());
    }
    tev_redirect_to('curriculum.php?program_id=' . (int)($_POST['program_id'] ?? 0));
}

// ----- Data -----
$programs = [];
$res = $db->query("SELECT id, program_code, program_name, program_type, required_contact_hours, teveta_regulated, rtsa_regulated FROM transport_programs ORDER BY program_name");
while ($r = $res->fetch_assoc()) { $programs[] = $r; }
if ($selectedProgram <= 0 && $programs) {
    $selectedProgram = (int)$programs[0]['id'];
}
$programInfo = null;
foreach ($programs as $p) { if ((int)$p['id'] === $selectedProgram) { $programInfo = $p; break; } }

$outcomes = [];
$res = $db->query("SELECT id, code, title FROM transport_curriculum_outcomes ORDER BY id");
while ($r = $res->fetch_assoc()) { $outcomes[] = $r; }

$modules = [];
$hours = ['theory' => 0.0, 'practical' => 0.0, 'assessment' => 0.0];
$coveredOutcomes = [];
if ($selectedProgram > 0) {
    $stmt = $db->prepare("
        SELECT m.*, o.code AS outcome_code, o.title AS outcome_title
        FROM transport_curriculum_modules m
        LEFT JOIN transport_curriculum_outcomes o ON o.id = m.outcome_id
        WHERE m.program_id = ? AND m.status = 'active'
        ORDER BY m.sequence, m.id
    ");
    $stmt->bind_param('i', $selectedProgram);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($r = $rs->fetch_assoc()) {
        $modules[] = $r;
        $t = $r['delivery_type'];
        if (isset($hours[$t])) { $hours[$t] += (float)$r['contact_hours']; }
        if (!empty($r['outcome_code'])) { $coveredOutcomes[$r['outcome_code']] = true; }
    }
    $stmt->close();
}
$totalHours = array_sum($hours);
$requiredHours = (float)($programInfo['required_contact_hours'] ?? 0);

require_once __DIR__ . '/includes/nav.php';
?>
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h3 class="mb-1"><i class="fas fa-book-open me-2"></i>Curriculum Management</h3>
            <p class="text-muted mb-0">Define TEVETA-aligned syllabus modules, outcomes and contact hours per program.</p>
        </div>
    </div>

    <?php echo tev_flash_render(); ?>

    <form method="get" class="row g-2 align-items-end mb-4" style="max-width:560px;">
        <div class="col-9">
            <label class="form-label">Program</label>
            <select name="program_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($programs as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>" <?php echo (int)$p['id'] === $selectedProgram ? 'selected' : ''; ?>>
                        <?php echo tev_h($p['program_code'] . ' — ' . $p['program_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-3"><button class="btn btn-outline-secondary w-100" type="submit">View</button></div>
    </form>

    <?php if ($programInfo): ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="fs-4 fw-bold"><?php echo number_format($hours['theory'],1); ?>h</div><div class="text-muted small">Theory</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="fs-4 fw-bold"><?php echo number_format($hours['practical'],1); ?>h</div><div class="text-muted small">Practical</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="fs-4 fw-bold"><?php echo number_format($totalHours,1); ?>h</div><div class="text-muted small">Total planned</div></div></div></div>
        <div class="col-6 col-md-3">
            <div class="card"><div class="card-body text-center">
                <div class="fs-4 fw-bold <?php echo ($requiredHours>0 && $totalHours>=$requiredHours)?'text-success':($requiredHours>0?'text-warning':''); ?>"><?php echo $requiredHours>0?number_format($requiredHours,1).'h':'—'; ?></div>
                <div class="text-muted small">RTSA required</div>
            </div></div>
        </div>
    </div>

    <div class="mb-3">
        <strong class="me-2">TEVETA outcome coverage:</strong>
        <?php foreach ($outcomes as $o): $ok = isset($coveredOutcomes[$o['code']]); ?>
            <span class="badge <?php echo $ok ? 'bg-success' : 'bg-secondary'; ?> me-1" title="<?php echo tev_h($o['title']); ?>">
                <i class="fas <?php echo $ok ? 'fa-check' : 'fa-xmark'; ?> me-1"></i><?php echo tev_h($o['code']); ?>
            </span>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><strong><i class="fas fa-list-ol me-2"></i>Syllabus modules</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead><tr><th>#</th><th>Module</th><th>Outcome</th><th>Type</th><th>Hours</th><th></th></tr></thead>
                            <tbody>
                                <?php if (!$modules): ?>
                                    <tr><td colspan="6" class="text-muted text-center py-4">No modules defined yet.</td></tr>
                                <?php else: foreach ($modules as $m): ?>
                                    <tr>
                                        <td><?php echo (int)$m['sequence']; ?></td>
                                        <td><?php echo tev_h($m['title']); ?><div class="text-muted small">v<?php echo tev_h($m['version']); ?></div></td>
                                        <td><?php echo $m['outcome_code'] ? '<span class="badge bg-info text-dark" title="'.tev_h($m['outcome_title']).'">'.tev_h($m['outcome_code']).'</span>' : '<span class="text-muted">—</span>'; ?></td>
                                        <td><span class="badge bg-light text-dark text-capitalize"><?php echo tev_h($m['delivery_type']); ?></span></td>
                                        <td><?php echo number_format((float)$m['contact_hours'],1); ?></td>
                                        <td>
                                            <form method="post" onsubmit="return confirm('Remove this module?');" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo tev_h(tev_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="delete_module">
                                                <input type="hidden" name="program_id" value="<?php echo $selectedProgram; ?>">
                                                <input type="hidden" name="module_id" value="<?php echo (int)$m['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><strong><i class="fas fa-plus me-2"></i>Add module</strong></div>
                <div class="card-body">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?php echo tev_h(tev_csrf_token()); ?>">
                        <input type="hidden" name="action" value="add_module">
                        <input type="hidden" name="program_id" value="<?php echo $selectedProgram; ?>">
                        <div class="col-12"><label class="form-label">Title</label><input class="form-control" name="title" required maxlength="200"></div>
                        <div class="col-7"><label class="form-label">TEVETA outcome</label>
                            <select name="outcome_id" class="form-select">
                                <option value="0">— none —</option>
                                <?php foreach ($outcomes as $o): ?><option value="<?php echo (int)$o['id']; ?>"><?php echo tev_h($o['code'].' — '.$o['title']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-5"><label class="form-label">Delivery</label>
                            <select name="delivery_type" class="form-select">
                                <option value="theory">Theory</option><option value="practical">Practical</option><option value="assessment">Assessment</option>
                            </select>
                        </div>
                        <div class="col-4"><label class="form-label">Hours</label><input type="number" step="0.5" min="0" class="form-control" name="contact_hours" value="1"></div>
                        <div class="col-4"><label class="form-label">Sequence</label><input type="number" min="1" class="form-control" name="sequence" value="<?php echo count($modules)+1; ?>"></div>
                        <div class="col-4"><label class="form-label">Version</label><input class="form-control" name="version" value="1.0" maxlength="20"></div>
                        <div class="col-12 mt-3"><button class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>Add module</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
