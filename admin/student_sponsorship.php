<?php
/**
 * Student Sponsorship — capture, approval workflow & funding profile.
 *
 * Records a student's sponsorship against the configurable framework, runs it
 * through approval, and shows the live fee split (programme fee → sponsor vs
 * student contribution → outstanding). Backed by includes/sponsorship_helpers.php.
 */
ob_start();
session_start();
if (!defined('IS_SCRIPT')) { define('IS_SCRIPT', true); }

require_once __DIR__ . '/includes/admin.php';
require_once dirname(__DIR__) . '/includes/finance_helpers.php';
require_once dirname(__DIR__) . '/includes/sponsorship_helpers.php';

if (!function_exists('sps_h')) {
    function sps_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function sps_money($v): string { return 'K' . number_format((float)$v, 2); }

$canManage = (function_exists('isSystemsAdmin') && isSystemsAdmin())
    || (function_exists('canAccessFinance') && canAccessFinance())
    || (function_exists('canAccessAdmissions') && canAccessAdmissions());

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf  = $_SESSION['csrf_token'];
$actor = (string)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 'admin');
$schemaReady = sponsorship_schema_ready($db);

/** Resolve a student's identity + current programme context. */
function sps_load_student(mysqli $db, string $sid): ?array
{
    $sql = "SELECT s.SID, s.Fname, s.Lname, s.program AS student_program,
                   sp.program_code AS sp_program, sp.academic_year, sp.year_of_study, sp.semester
            FROM students s
            LEFT JOIN student_program sp ON sp.id = (SELECT MAX(id) FROM student_program WHERE UPPER(TRIM(Sid)) = UPPER(TRIM(s.SID)))
            WHERE UPPER(TRIM(s.SID)) = UPPER(TRIM(?)) LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) { return null; }
    $stmt->bind_param('s', $sid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { return null; }
    $row['program_code'] = $row['sp_program'] ?: $row['student_program'];
    $row['name'] = trim(($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? '')) ?: 'Name not captured';
    return $row;
}

$sid = isset($_GET['sid']) ? trim((string)$_GET['sid']) : '';

// ── POST (PRG) ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = false; $msg = '';
    $postSid = trim((string)($_POST['student_id'] ?? ''));
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $msg = 'Security validation failed. Please refresh and try again.';
    } elseif (!$canManage) {
        $msg = 'You do not have permission to manage sponsorships.';
    } elseif (!$schemaReady) {
        $msg = 'Sponsorship module is not installed.';
    } else {
        switch ((string)($_POST['action'] ?? '')) {
            case 'add_sponsorship':
                $r = create_student_sponsorship($db, [
                    'student_id' => $postSid,
                    'sponsor_type_id' => $_POST['sponsor_type_id'] ?? 0,
                    'sponsor_id' => $_POST['sponsor_id'] ?? 0,
                    'program_code' => $_POST['program_code'] ?? '',
                    'academic_year' => $_POST['academic_year'] ?? '',
                    'reference_number' => $_POST['reference_number'] ?? '',
                    'coverage_percent' => $_POST['coverage_percent'] ?? '',
                    'amount_approved' => $_POST['amount_approved'] ?? '',
                    'start_date' => $_POST['start_date'] ?? '',
                    'end_date' => $_POST['end_date'] ?? '',
                    'conditions' => $_POST['conditions'] ?? '',
                ], $actor);
                $ok = !empty($r['success']);
                $msg = $ok ? ('Sponsorship recorded (' . ($r['approval_status'] ?? 'pending') . ').')
                           : ($r['error'] ?? (isset($r['errors']) ? implode(' ', $r['errors']) : 'Failed to record sponsorship.'));
                break;
            case 'approve':
                $spsId = (int)($_POST['id'] ?? 0);
                $spsRecord = null;
                if ($stmt = $db->prepare("SELECT * FROM finance_student_sponsors WHERE id = ? LIMIT 1")) {
                    $stmt->bind_param('i', $spsId);
                    $stmt->execute();
                    $spsRecord = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }

                $r = approve_student_sponsorship($db, $spsId, $actor);
                $ok = !empty($r['success']); 
                $msg = $ok ? 'Sponsorship approved.' : ($r['error'] ?? 'Failed.');

                if ($ok && $spsRecord) {
                    $studentId = $spsRecord['student_id'];
                    $programCode = $spsRecord['program_code'];
                    $academicYear = $spsRecord['academic_year'];
                    
                    // Fetch student and check status
                    $stu = null;
                    if ($stStmt = $db->prepare("SELECT status, mode, intake FROM students WHERE SID = ? LIMIT 1")) {
                        $stStmt->bind_param('s', $studentId);
                        $stStmt->execute();
                        $stu = $stStmt->get_result()->fetch_assoc();
                        $stStmt->close();
                    }
                    
                    if ($stu) {
                        if ($stu['status'] === 'pending') {
                            // Activate student
                            $db->query("UPDATE students SET status = 'active' WHERE SID = '" . $db->real_escape_string($studentId) . "'");
                            $db->query("UPDATE student_program SET status = 'active' WHERE Sid = '" . $db->real_escape_string($studentId) . "' AND program_code = '" . $db->real_escape_string($programCode) . "'");
                        }
                        
                        // Generate billing account
                        require_once dirname(__DIR__) . '/includes/fees_helpers.php';
                        
                        // Resolve Program details
                        $program = null;
                        if ($prStmt = $db->prepare("SELECT program_name, default_fee FROM programs WHERE program_code = ? LIMIT 1")) {
                            $prStmt->bind_param('s', $programCode);
                            $prStmt->execute();
                            $program = $prStmt->get_result()->fetch_assoc();
                            $prStmt->close();
                        }
                        $programName = $program ? $program['program_name'] : '';
                        
                        // Resolve Course ID
                        $courseId = 0;
                        if ($courseStmt = $db->prepare("SELECT id FROM courses WHERE course_code = ? OR (course_name IS NOT NULL AND LOWER(TRIM(course_name)) = LOWER(TRIM(?))) LIMIT 1")) {
                            $courseStmt->bind_param('ss', $programCode, $programName);
                            $courseStmt->execute();
                            $course_res = $courseStmt->get_result();
                            if ($course_res && $course_row = $course_res->fetch_assoc()) {
                                $courseId = (int)$course_row['id'];
                            }
                            $courseStmt->close();
                        }
                        
                        // Resolve Training Mode ID
                        $trainingModeId = 0;
                        $mode = $stu['mode'] ?: 'Full-time';
                        if ($modeStmt = $db->prepare("SELECT id FROM training_modes WHERE LOWER(mode_name) = LOWER(?) LIMIT 1")) {
                            $modeStmt->bind_param('s', $mode);
                            $modeStmt->execute();
                            $mode_res = $modeStmt->get_result();
                            if ($mode_res && $mode_row = $mode_res->fetch_assoc()) {
                                $trainingModeId = (int)$mode_row['id'];
                            }
                            $modeStmt->close();
                        }
                        if ($trainingModeId === 0) {
                            $mode_fallback = $db->query("SELECT id FROM training_modes LIMIT 1");
                            if ($mode_fallback && $mode_fallback->num_rows > 0) {
                                $trainingModeId = (int)$mode_fallback->fetch_assoc()['id'];
                            }
                        }
                        
                        // Generate Fee Account
                        if ($courseId > 0 && $trainingModeId > 0) {
                            $intake = $stu['intake'] ?: '';
                            $feeAccountId = fees_generate_student_account($db, $studentId, $courseId, $trainingModeId, $academicYear, $intake);
                            if ($feeAccountId) {
                                fees_recalculate_student_balance($db, (int)$feeAccountId);
                            }
                        }
                        
                        // Create registration invoice for student contribution (if any)
                        require_once dirname(__DIR__) . '/admissions/includes/registration_handlers.php';
                        $totalFees = admissionsAssignCourses($db, $studentId, $programCode, '1', $academicYear);
                        if ($totalFees <= 0) {
                            $totalFees = $program ? (float)$program['default_fee'] : 0.0;
                        }
                        
                        $summary = student_sponsorship_summary($db, $studentId, $totalFees);
                        $studentContribution = $summary['student_contribution'];
                        if ($studentContribution > 0) {
                            $desc = $programName . ' registration - ' . $intake . ' (Student Portion)';
                            admissionsCreateInvoice($db, $studentId, $studentContribution, $desc);
                        }
                        
                        // Create Notification record
                        $notifMsg = "Your sponsorship for " . $programCode . " has been approved.";
                        $db->query("INSERT INTO el_student_notifications (student_id, course_code, type, title, body, url) VALUES ('" . $db->real_escape_string($studentId) . "', 'GENERAL', 'info', 'Sponsorship Approved', '" . $db->real_escape_string($notifMsg) . "', 'studentAccount.php')");
                    }
                }
                break;
            case 'reject':
                $r = reject_student_sponsorship($db, (int)($_POST['id'] ?? 0), $actor, (string)($_POST['reason'] ?? ''));
                $ok = !empty($r['success']); $msg = $ok ? 'Sponsorship rejected.' : ($r['error'] ?? 'Failed.');
                break;
            case 'cancel':
                $r = cancel_student_sponsorship($db, (int)($_POST['id'] ?? 0), $actor, (string)($_POST['reason'] ?? ''));
                $ok = !empty($r['success']); $msg = $ok ? 'Sponsorship cancelled.' : ($r['error'] ?? 'Failed.');
                break;
            default:
                $msg = 'Unknown action.';
        }
    }
    $_SESSION['sps_flash'] = ['ok' => $ok, 'msg' => $msg];
    header('Location: student_sponsorship.php?sid=' . urlencode($postSid !== '' ? $postSid : $sid));
    exit;
}

$flash = $_SESSION['sps_flash'] ?? null;
unset($_SESSION['sps_flash']);

$student = ($sid !== '') ? sps_load_student($db, $sid) : null;
$sponsorTypes = $schemaReady ? get_sponsor_types($db, true) : [];
$sponsorOrgs = [];
if ($schemaReady && ($res = @$db->query("SELECT id, name FROM finance_sponsors WHERE COALESCE(status,'active')='active' ORDER BY name"))) {
    while ($r = $res->fetch_assoc()) { $sponsorOrgs[] = $r; }
    $res->free();
}

$programmeFee = 0.0; $summary = null; $sponsorships = [];
if ($schemaReady && $student) {
    $programmeFee = sponsorship_programme_fee($db, (string)$student['program_code'],
        $student['year_of_study'] !== null ? (int)$student['year_of_study'] : null,
        $student['semester'] !== null ? (int)$student['semester'] : null);
    $summary = student_sponsorship_summary($db, (string)$student['SID'], $programmeFee);
    $sponsorships = $summary['sponsorships'];
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 fw-bold text-primary mb-1"><i class="fas fa-user-shield me-2"></i>Student Sponsorship</h1>
            <p class="text-muted mb-0">Capture sponsorships, run approvals, and view the funding split.</p>
        </div>
        <a href="sponsorship_management.php" class="btn btn-outline-primary rounded-pill"><i class="fas fa-gear me-1"></i>Configure Types & Eligibility</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['ok'] ? 'success' : 'danger'; ?> alert-dismissible fade show">
            <i class="fas fa-<?php echo $flash['ok'] ? 'circle-check' : 'triangle-exclamation'; ?> me-2"></i><?php echo sps_h($flash['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$schemaReady): ?>
        <div class="alert alert-warning">Sponsorship module not installed. Apply <code>migrations/2026_sponsorship_management.sql</code>.</div>
    <?php else: ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Student ID</label>
                    <input class="form-control" name="sid" value="<?php echo sps_h($sid); ?>" placeholder="Enter student ID (e.g. CSE26456789)" required>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-primary"><i class="fas fa-magnifying-glass me-1"></i>Load Student</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($sid !== '' && !$student): ?>
        <div class="alert alert-info">No student found with ID <strong><?php echo sps_h($sid); ?></strong>.</div>
    <?php elseif ($student): ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <!-- Funding summary -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="fw-bold mb-0"><?php echo sps_h($student['name']); ?></h5>
                            <span class="text-muted small">ID: <?php echo sps_h($student['SID']); ?> &middot; Programme: <strong><?php echo sps_h($student['program_code'] ?: '—'); ?></strong></span>
                        </div>
                        <?php if ($summary['is_sponsored']): ?>
                            <span class="badge bg-success-subtle text-success border rounded-pill px-3 py-2">Sponsored</span>
                        <?php elseif ($summary['has_pending']): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis border rounded-pill px-3 py-2">Pending approval</span>
                        <?php else: ?>
                            <span class="badge bg-light text-secondary border rounded-pill px-3 py-2">Self / Unsponsored</span>
                        <?php endif; ?>
                    </div>
                    <div class="row text-center g-3">
                        <div class="col-4"><div class="p-3 bg-light rounded-3"><div class="text-muted small">Programme Fee</div><div class="h5 fw-bold mb-0"><?php echo sps_money($summary['total_fee']); ?></div></div></div>
                        <div class="col-4"><div class="p-3 bg-success-subtle rounded-3"><div class="text-muted small">Sponsor Pays</div><div class="h5 fw-bold mb-0 text-success"><?php echo sps_money($summary['sponsor_contribution']); ?></div></div></div>
                        <div class="col-4"><div class="p-3 bg-primary-subtle rounded-3"><div class="text-muted small">Student Pays</div><div class="h5 fw-bold mb-0 text-primary"><?php echo sps_money($summary['student_contribution']); ?></div></div></div>
                    </div>
                    <?php if ($programmeFee <= 0): ?>
                        <div class="small text-muted mt-3"><i class="fas fa-circle-info me-1"></i>No fee structure found for this programme; split shows once fees are configured.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Existing sponsorships -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3">Sponsorship Records</h6>
                    <?php if (!$sponsorships): ?>
                        <p class="text-muted mb-0">No sponsorship records yet.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light"><tr><th>Type / Sponsor</th><th>Cover</th><th>Approved</th><th>Outstanding</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($sponsorships as $s):
                                $badge = $s['approval_status'] === 'approved' ? 'success' : ($s['approval_status'] === 'pending' ? 'warning text-dark' : ($s['approval_status'] === 'rejected' ? 'danger' : 'secondary')); ?>
                                <tr>
                                    <td><strong><?php echo sps_h($s['sponsor_type_name'] ?: '—'); ?></strong><?php if (!empty($s['sponsor_name'])): ?><br><small class="text-muted"><?php echo sps_h($s['sponsor_name']); ?></small><?php endif; ?><?php if (!empty($s['reference_number'])): ?><br><small class="text-muted">Ref: <?php echo sps_h($s['reference_number']); ?></small><?php endif; ?></td>
                                    <td><?php echo $s['coverage_percent'] !== null ? rtrim(rtrim(number_format((float)$s['coverage_percent'],2),'0'),'.').'%' : '—'; ?></td>
                                    <td><?php echo $s['amount_approved'] !== null ? sps_money($s['amount_approved']) : '—'; ?></td>
                                    <td><?php echo sps_money($s['outstanding']); ?></td>
                                    <td><span class="badge bg-<?php echo $badge; ?>"><?php echo sps_h(ucfirst($s['approval_status'])); ?></span></td>
                                    <td class="text-end">
                                        <?php if ($s['approval_status'] === 'pending'): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo sps_h($csrf); ?>"><input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="student_id" value="<?php echo sps_h($student['SID']); ?>">
                                                <button class="btn btn-sm btn-success"><i class="fas fa-check"></i></button>
                                            </form>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Reject this sponsorship?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo sps_h($csrf); ?>"><input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="student_id" value="<?php echo sps_h($student['SID']); ?>">
                                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-xmark"></i></button>
                                            </form>
                                        <?php elseif ($s['approval_status'] === 'approved'): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Cancel this sponsorship?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo sps_h($csrf); ?>"><input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>"><input type="hidden" name="student_id" value="<?php echo sps_h($student['SID']); ?>">
                                                <button class="btn btn-sm btn-outline-secondary">Cancel</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small"><?php echo !empty($s['rejection_reason']) ? sps_h($s['rejection_reason']) : '—'; ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Add sponsorship -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3">Record a Sponsorship</h6>
                    <?php $eligible = get_programme_sponsor_options($db, (string)$student['program_code'], true); ?>
                    <?php if ($student['program_code'] && !$eligible): ?>
                        <div class="alert alert-info small">No sponsor types are configured as eligible for <code><?php echo sps_h($student['program_code']); ?></code> yet (self-funded is always allowed). Configure eligibility on the
                        <a href="sponsorship_management.php?tab=eligibility&prog=<?php echo urlencode((string)$student['program_code']); ?>">Programme Eligibility</a> tab.</div>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo sps_h($csrf); ?>">
                        <input type="hidden" name="action" value="add_sponsorship">
                        <input type="hidden" name="student_id" value="<?php echo sps_h($student['SID']); ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Sponsor Type</label>
                            <select class="form-select" name="sponsor_type_id" required>
                                <option value="">— select —</option>
                                <?php foreach ($sponsorTypes as $t): ?>
                                    <option value="<?php echo (int)$t['id']; ?>"><?php echo sps_h($t['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($sponsorOrgs): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Sponsor Organisation <span class="text-muted small">(optional)</span></label>
                            <select class="form-select" name="sponsor_id">
                                <option value="">— none —</option>
                                <?php foreach ($sponsorOrgs as $o): ?>
                                    <option value="<?php echo (int)$o['id']; ?>"><?php echo sps_h($o['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="row g-2">
                            <div class="col-7 mb-3">
                                <label class="form-label fw-semibold">Programme</label>
                                <input class="form-control" name="program_code" value="<?php echo sps_h($student['program_code']); ?>">
                            </div>
                            <div class="col-5 mb-3">
                                <label class="form-label fw-semibold">Academic Year</label>
                                <input class="form-control" name="academic_year" value="<?php echo sps_h($student['academic_year'] ?: date('Y')); ?>">
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-semibold">% Sponsored</label>
                                <input class="form-control" type="number" step="0.01" min="0" max="100" name="coverage_percent" placeholder="e.g. 75">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label fw-semibold">Or Fixed Amount</label>
                                <input class="form-control" type="number" step="0.01" min="0" name="amount_approved" placeholder="overrides %">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Reference Number</label>
                            <input class="form-control" name="reference_number" placeholder="Sponsor approval / voucher ref">
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-3"><label class="form-label fw-semibold">Start Date</label><input class="form-control" type="date" name="start_date"></div>
                            <div class="col-6 mb-3"><label class="form-label fw-semibold">End Date</label><input class="form-control" type="date" name="end_date"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Conditions</label>
                            <textarea class="form-control" name="conditions" rows="2" placeholder="e.g. maintain GPA, full-time enrolment"></textarea>
                        </div>
                        <button class="btn btn-primary w-100"><i class="fas fa-plus me-1"></i>Record Sponsorship</button>
                        <p class="text-muted small mt-2 mb-0">Self-funded types are auto-approved; others start as <em>pending</em> for approval.</p>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php endif; /* student */ ?>
    <?php endif; /* schema */ ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
