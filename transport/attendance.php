<?php
declare(strict_types=1);
/**
 * Session attendance marking — records which trainees actually reported for a
 * training session (transport_session_attendance), powering the "how many
 * reported" metric in cohort reports.
 */
require_once __DIR__ . '/includes/transport.php';
require_once __DIR__ . '/includes/teveta_helpers.php';

$page_title = 'Session Attendance';
$STATUSES = ['present', 'absent', 'late', 'excused'];
$marker = (string)($_SESSION['user_name'] ?? $_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? 'staff');
$selectedSession = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!tev_verify_csrf()) {
        tev_flash_set('danger', 'Security token mismatch. Please refresh and try again.');
        tev_redirect_self();
    }
    try {
        if (($_POST['action'] ?? '') === 'save_attendance') {
            $sessionId = (int)($_POST['session_id'] ?? 0);
            $marks = $_POST['status'] ?? [];   // [enrollment_id => status]
            if ($sessionId <= 0 || !is_array($marks)) {
                throw new RuntimeException('Invalid attendance submission.');
            }
            // resolve cohort to validate enrolments belong to this session's cohort
            $cs = $db->prepare("SELECT cohort_id, status FROM transport_sessions WHERE id = ? LIMIT 1");
            $cs->bind_param('i', $sessionId);
            $cs->execute();
            $crow = $cs->get_result()->fetch_assoc();
            $cs->close();
            if (!$crow) { throw new RuntimeException('Session not found.'); }
            if ((string)$crow['status'] === 'cancelled') {
                throw new RuntimeException('Attendance cannot be marked for a cancelled session.');
            }
            $cohortId = (int)$crow['cohort_id'];

            $up = $db->prepare("INSERT INTO transport_session_attendance (session_id, enrollment_id, trainee_id, status, marked_by)
                                VALUES (?,?,?,?,?)
                                ON DUPLICATE KEY UPDATE status=VALUES(status), trainee_id=VALUES(trainee_id), marked_by=VALUES(marked_by)");
            $look = $db->prepare("SELECT trainee_id FROM transport_enrollments WHERE id = ? AND cohort_id = ? AND booking_status = 'booked' AND status IN ('enrolled','active','completed') LIMIT 1");
            $saved = 0;
            foreach ($marks as $enrollmentId => $status) {
                $enrollmentId = (int)$enrollmentId;
                $status = in_array($status, $STATUSES, true) ? (string)$status : 'present';
                $look->bind_param('ii', $enrollmentId, $cohortId);
                $look->execute();
                $lr = $look->get_result()->fetch_assoc();
                if (!$lr) { continue; } // enrolment not in this cohort — skip
                $traineeId = (int)$lr['trainee_id'];
                $up->bind_param('iiiss', $sessionId, $enrollmentId, $traineeId, $status, $marker);
                $up->execute();
                $saved++;
            }
            $look->close();
            $up->close();
            tev_flash_set('success', "Attendance saved for {$saved} trainee(s).");
            tev_redirect_to('attendance.php?session_id=' . $sessionId);
        }
    } catch (Throwable $e) {
        error_log('attendance.php: ' . $e->getMessage());
        tev_flash_set('danger', $e->getMessage());
    }
    tev_redirect_self();
}

// Recent sessions to choose from
$sessions = [];
$rs = $db->query("
    SELECT s.id, s.session_date, s.start_time, s.status, s.session_type,
           c.cohort_name, p.program_code, i.full_name AS instructor,
           (SELECT COUNT(*)
              FROM transport_session_attendance a
              INNER JOIN transport_enrollments e2 ON e2.id = a.enrollment_id
              WHERE a.session_id=s.id
                AND e2.booking_status='booked'
                AND e2.status IN ('enrolled','active','completed')) AS marked
    FROM transport_sessions s
    INNER JOIN transport_cohorts c ON c.id=s.cohort_id
    INNER JOIN transport_programs p ON p.id=c.program_id
    INNER JOIN transport_instructors i ON i.id=s.instructor_id
    ORDER BY s.session_date DESC, s.id DESC
    LIMIT 60
");
if ($rs) { while ($r = $rs->fetch_assoc()) { $sessions[] = $r; } }

// Selected session detail + roster
$sessionInfo = null;
$roster = [];
if ($selectedSession > 0) {
    foreach ($sessions as $s) { if ((int)$s['id'] === $selectedSession) { $sessionInfo = $s; break; } }
    if (!$sessionInfo) {
        $q = $db->prepare("SELECT s.id, s.session_date, s.cohort_id, s.status, c.cohort_name, p.program_code, i.full_name AS instructor
                           FROM transport_sessions s
                           INNER JOIN transport_cohorts c ON c.id=s.cohort_id
                           INNER JOIN transport_programs p ON p.id=c.program_id
                           INNER JOIN transport_instructors i ON i.id=s.instructor_id
                           WHERE s.id=? LIMIT 1");
        $q->bind_param('i', $selectedSession);
        $q->execute();
        $sessionInfo = $q->get_result()->fetch_assoc() ?: null;
        $q->close();
    }
    if ($sessionInfo) {
        $cohortId = (int)($sessionInfo['cohort_id'] ?? 0);
        if ($cohortId === 0) {
            $cq = $db->prepare("SELECT cohort_id FROM transport_sessions WHERE id=? LIMIT 1");
            $cq->bind_param('i', $selectedSession); $cq->execute();
            $cohortId = (int)($cq->get_result()->fetch_assoc()['cohort_id'] ?? 0); $cq->close();
        }
        $rq = $db->prepare("
            SELECT e.id AS enrollment_id, t.first_name, t.last_name, t.student_id,
                   a.status AS att_status
            FROM transport_enrollments e
            INNER JOIN transport_trainees t ON t.id=e.trainee_id
            LEFT JOIN transport_session_attendance a ON a.enrollment_id=e.id AND a.session_id=?
            WHERE e.cohort_id=?
              AND e.booking_status='booked'
              AND e.status IN ('enrolled','active','completed')
            ORDER BY t.last_name, t.first_name");
        $rq->bind_param('ii', $selectedSession, $cohortId);
        $rq->execute();
        $rr = $rq->get_result();
        while ($r = $rr->fetch_assoc()) { $roster[] = $r; }
        $rq->close();
    }
}

require_once __DIR__ . '/includes/nav.php';
?>
<div class="container-fluid py-3">
    <h3 class="mb-1"><i class="fas fa-user-check me-2"></i>Session Attendance</h3>
    <p class="text-muted">Mark who reported for each training session — feeds the cohort attendance reports.</p>
    <?php echo tev_flash_render(); ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><strong>Sessions</strong></div>
                <div class="card-body p-0" style="max-height:70vh;overflow:auto;">
                    <table class="table table-hover table-sm mb-0 align-middle">
                        <thead><tr><th>Date</th><th>Cohort</th><th>Instructor</th><th>Marked</th><th></th></tr></thead>
                        <tbody>
                            <?php if (!$sessions): ?><tr><td colspan="5" class="text-muted text-center py-4">No sessions scheduled yet.</td></tr>
                            <?php else: foreach ($sessions as $s): ?>
                                <tr class="<?php echo (int)$s['id']===$selectedSession?'table-active':''; ?>">
                                    <td class="small"><?php echo tev_h($s['session_date']); ?></td>
                                    <td class="small"><?php echo tev_h($s['cohort_name']); ?><div class="text-muted"><?php echo tev_h($s['program_code']); ?></div></td>
                                    <td class="small"><?php echo tev_h($s['instructor']); ?></td>
                                    <td><span class="badge bg-<?php echo (int)$s['marked']>0?'success':'secondary'; ?>"><?php echo (int)$s['marked']; ?></span></td>
                                    <td><a class="btn btn-sm btn-outline-primary" href="attendance.php?session_id=<?php echo (int)$s['id']; ?>">Mark</a></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <?php if ($sessionInfo): ?>
            <div class="card">
                <div class="card-header"><strong>Roster — <?php echo tev_h($sessionInfo['cohort_name'] ?? ''); ?> · <?php echo tev_h($sessionInfo['session_date'] ?? ''); ?></strong></div>
                <div class="card-body">
                    <?php if (!$roster): ?>
                        <div class="alert alert-info mb-0">No booked, active trainees are available for this session.</div>
                    <?php else: ?>
                    <?php if (($sessionInfo['status'] ?? '') === 'cancelled'): ?>
                        <div class="alert alert-warning"><i class="fas fa-ban me-2"></i>This session is cancelled. Attendance is read-only.</div>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo tev_h(tev_csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_attendance">
                        <input type="hidden" name="session_id" value="<?php echo $selectedSession; ?>">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Trainee</th><th style="width:160px;">Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($roster as $r): $cur = $r['att_status'] ?: 'present'; ?>
                                    <tr>
                                        <td><?php echo tev_h(trim($r['first_name'].' '.$r['last_name'])); ?><div class="text-muted small"><?php echo tev_h($r['student_id']); ?></div></td>
                                        <td>
                                            <select name="status[<?php echo (int)$r['enrollment_id']; ?>]" class="form-select form-select-sm" <?php echo (($sessionInfo['status'] ?? '') === 'cancelled') ? 'disabled' : ''; ?>>
                                                <?php foreach ($STATUSES as $st): ?>
                                                    <option value="<?php echo $st; ?>" <?php echo $cur===$st?'selected':''; ?>><?php echo tev_h(ucfirst($st)); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button class="btn btn-primary" <?php echo (($sessionInfo['status'] ?? '') === 'cancelled') ? 'disabled' : ''; ?>><i class="fas fa-save me-1"></i>Save attendance</button>
                        <span class="text-muted small ms-2">Defaults to "Present" — change absentees then save.</span>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
                <div class="card"><div class="card-body text-muted text-center py-5"><i class="fas fa-hand-pointer fa-2x mb-2 opacity-50"></i><p class="mb-0">Pick a session to mark attendance.</p></div></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
