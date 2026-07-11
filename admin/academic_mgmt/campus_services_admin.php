<?php
/**
 * Registrar & Campus Services Administration Panel
 */

require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/alumni_access_helpers.php';

$success_msg = '';
$error_msg = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_ok = $_SERVER['REQUEST_METHOD'] !== 'POST'
    || hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''));
if (!$csrf_ok) {
    $error_msg = 'Security token mismatch. Please refresh the page and try again.';
}

// Handle Support Request scheduling or resolution
if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_request') {
    $req_id = (int)$_POST['request_id'];
    $status = $_POST['status'] ?? 'Pending';
    $app_date = ($_POST['appointment_date'] ?? '') !== '' ? $_POST['appointment_date'] : null;
    $notes = trim($_POST['notes'] ?? '');

    $sql = "UPDATE student_campus_requests SET status = ?, appointment_date = ?, notes = ? WHERE id = ?";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('sssi', $status, $app_date, $notes, $req_id);
        if ($stmt->execute()) {
            $success_msg = 'Student request updated successfully.';
        } else {
            $error_msg = 'Failed to update request.';
        }
        $stmt->close();
    }
}

// Handle Administrative Clearance Toggle
if ($csrf_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_student') {
    $sid = trim($_POST['student_id'] ?? '');
    $admin_cleared = (int)($_POST['admin_cleared'] ?? 0);
    $notes = trim($_POST['admin_notes'] ?? '');
    $grad_status = $_POST['graduation_status'] ?? 'Not Eligible';
    $validStatuses = ['Not Eligible', 'Eligible', 'Applied', 'Approved', 'Graduated'];

    if ($sid === '' || !in_array($grad_status, $validStatuses, true)) {
        $error_msg = 'Invalid clearance submission.';
    } else {
        $sql = "INSERT INTO student_clearance (student_id, admin_cleared, admin_notes, graduation_status)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE admin_cleared = ?, admin_notes = ?, graduation_status = ?";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sississ', $sid, $admin_cleared, $notes, $grad_status, $admin_cleared, $notes, $grad_status);
            if ($stmt->execute()) {
                $success_msg = 'Student administrative clearance updated successfully.';
                // Approved/Graduated students get the Alumni Portal automatically;
                // regressions withdraw only the auto-grant, never manual ones.
                wuc_sync_alumni_portal_access($db, $sid, $grad_status);
            } else {
                $error_msg = 'Failed to update clearance record.';
            }
            $stmt->close();
        }
    }
}

// Fetch all support requests
$requests = [];
$req_res = $db->query("SELECT r.*, s.Fname, s.Lname FROM student_campus_requests r INNER JOIN students s ON r.student_id COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci ORDER BY r.created_at DESC");
while ($req_res && $row = $req_res->fetch_assoc()) {
    $requests[] = $row;
}

// Fetch all student clearance statuses
$clearances = [];
$clr_res = $db->query("SELECT c.*, s.Fname, s.Lname, p.program_name 
                       FROM student_clearance c 
                       INNER JOIN students s ON c.student_id COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci
                       INNER JOIN student_program sp ON sp.Sid COLLATE utf8mb4_unicode_ci = s.SID COLLATE utf8mb4_unicode_ci
                       INNER JOIN programs p ON sp.program_code = p.program_code
                       ORDER BY c.updated_at DESC");
while ($clr_res && $row = $clr_res->fetch_assoc()) {
    $clearances[] = $row;
}
?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-3">
    <h1 class="dashboard-title"><i class="fas fa-university me-2 text-primary"></i>Campus Services & Clearance Registry</h1>
    <p class="text-muted mb-0">Manage counselling, medical bookings, and student graduation clearance lifecycles.</p>
  </div>

  <?php if ($success_msg): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_msg) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
  <?php endif; ?>
  <?php if ($error_msg): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_msg) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
  <?php endif; ?>

  <div class="row g-4">
    
    <!-- Left Column: Clearance & Graduation Approvals -->
    <div class="col-lg-6">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
          <h5 class="card-title mb-0 fw-semibold text-purple"><i class="fas fa-user-graduate me-2"></i>Graduation Clearance Registry</h5>
          <span class="badge bg-purple"><?= count($clearances) ?> Student(s)</span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($clearances)): ?>
            <div class="p-4 text-center text-muted">
              <p class="mb-0">No student graduation applications or clearance records logged yet.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th class="text-center">Nodes (F/L/Ac)</th>
                    <th class="text-center">Admin Status</th>
                    <th class="text-center">Grad Status</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($clearances as $c): ?>
                    <tr>
                      <td><code><?= htmlspecialchars($c['student_id']) ?></code></td>
                      <td>
                        <strong><?= htmlspecialchars($c['Fname'] . ' ' . $c['Lname']) ?></strong>
                        <div class="small text-muted"><?= htmlspecialchars($c['program_name'] ?? '') ?></div>
                      </td>
                      <td class="text-center">
                        <span class="badge <?= $c['finance_cleared'] ? 'bg-success' : 'bg-danger' ?>" title="Finance">F</span>
                        <span class="badge <?= $c['library_cleared'] ? 'bg-success' : 'bg-danger' ?>" title="Library">L</span>
                        <span class="badge <?= $c['academic_cleared'] ? 'bg-success' : 'bg-danger' ?>" title="Academic">Ac</span>
                      </td>
                      <td class="text-center">
                        <span class="badge <?= $c['admin_cleared'] ? 'bg-success' : 'bg-warning text-dark' ?>">
                          <?= $c['admin_cleared'] ? 'Admin Cleared' : 'Pending Review' ?>
                        </span>
                      </td>
                      <td class="text-center">
                        <span class="badge bg-purple"><?= htmlspecialchars($c['graduation_status']) ?></span>
                      </td>
                      <td class="text-end">
                        <button type="button" class="btn btn-sm btn-purple" data-bs-toggle="modal" data-bs-target="#clearModal<?= $c['id'] ?>">
                          <i class="fas fa-edit"></i> Review
                        </button>
                      </td>
                    </tr>

                    <!-- Clearance Modal -->
                    <div class="modal fade" id="clearModal<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
                      <div class="modal-dialog">
                        <form action="" method="post" class="modal-content">
                          <input type="hidden" name="action" value="clear_student">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="student_id" value="<?= htmlspecialchars($c['student_id']) ?>">
                          <div class="modal-header">
                            <h5 class="modal-title">Review Student Clearance</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                          </div>
                          <div class="modal-body">
                            <div class="mb-3">
                              <label class="form-label font-weight-bold">Administrative Clearance Status</label>
                              <select name="admin_cleared" class="form-select">
                                <option value="0" <?= !$c['admin_cleared'] ? 'selected' : '' ?>>Pending Review (Hold)</option>
                                <option value="1" <?= $c['admin_cleared'] ? 'selected' : '' ?>>Cleared (Satisfactory)</option>
                              </select>
                            </div>
                            <div class="mb-3">
                              <label class="form-label font-weight-bold">Graduation Status</label>
                              <select name="graduation_status" class="form-select">
                                <option value="Not Eligible" <?= $c['graduation_status'] === 'Not Eligible' ? 'selected' : '' ?>>Not Eligible</option>
                                <option value="Eligible" <?= $c['graduation_status'] === 'Eligible' ? 'selected' : '' ?>>Eligible</option>
                                <option value="Applied" <?= $c['graduation_status'] === 'Applied' ? 'selected' : '' ?>>Applied (Awaiting Senate Approval)</option>
                                <option value="Approved" <?= $c['graduation_status'] === 'Approved' ? 'selected' : '' ?>>Approved for Graduation</option>
                                <option value="Graduated" <?= $c['graduation_status'] === 'Graduated' ? 'selected' : '' ?>>Graduated (Alumni)</option>
                              </select>
                            </div>
                            <div class="mb-3">
                              <label class="form-label font-weight-bold">Notes</label>
                              <textarea name="admin_notes" class="form-control" rows="3"><?= htmlspecialchars($c['admin_notes'] ?? '') ?></textarea>
                            </div>
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-purple">Save Clearance Status</button>
                          </div>
                        </form>
                      </div>
                    </div>

                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Right Column: Support Tickets & Appointment Scheduler -->
    <div class="col-lg-6">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
          <h5 class="card-title mb-0 fw-semibold text-primary"><i class="fas fa-ticket-alt me-2"></i>Support Requests & Appointments Queue</h5>
          <span class="badge bg-primary"><?= count($requests) ?> Request(s)</span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($requests)): ?>
            <div class="p-4 text-center text-muted">
              <p class="mb-0">No counseling, medical, or other campus service tickets open.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Student</th>
                    <th>Type</th>
                    <th>Request</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($requests as $r): ?>
                    <tr>
                      <td>
                        <strong><?= htmlspecialchars($r['Fname'] . ' ' . $r['Lname']) ?></strong>
                        <div class="small text-muted">ID: <?= htmlspecialchars($r['student_id']) ?></div>
                      </td>
                      <td><span class="badge bg-purple"><?= htmlspecialchars(ucfirst($r['service_type'])) ?></span></td>
                      <td>
                        <strong><?= htmlspecialchars($r['subject']) ?></strong>
                        <div class="small text-muted text-truncate" style="max-width: 200px;"><?= htmlspecialchars($r['message']) ?></div>
                      </td>
                      <td>
                        <span class="badge bg-secondary"><?= htmlspecialchars($r['status']) ?></span>
                      </td>
                      <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reqModal<?= $r['id'] ?>">
                          <i class="fas fa-calendar-check"></i> Manage
                        </button>
                      </td>
                    </tr>

                    <!-- Request Schedule Modal -->
                    <div class="modal fade" id="reqModal<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                      <div class="modal-dialog">
                        <form action="" method="post" class="modal-content">
                          <input type="hidden" name="action" value="update_request">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                          <div class="modal-header">
                            <h5 class="modal-title">Manage Request Ticket</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                          </div>
                          <div class="modal-body">
                            <div class="mb-3">
                              <label class="form-label font-weight-bold">Status</label>
                              <select name="status" class="form-select">
                                <option value="Pending" <?= $r['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="In Progress" <?= $r['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="Scheduled" <?= $r['status'] === 'Scheduled' ? 'selected' : '' ?>>Scheduled (Appointment Set)</option>
                                <option value="Resolved" <?= $r['status'] === 'Resolved' ? 'selected' : '' ?>>Resolved (Closed)</option>
                                <option value="Rejected" <?= $r['status'] === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                              </select>
                            </div>
                            <div class="mb-3">
                              <label class="form-label font-weight-bold">Schedule Appointment Date & Time</label>
                              <input type="datetime-local" name="appointment_date" class="form-control" value="<?= $r['appointment_date'] ? date('Y-m-d\TH:i', strtotime($r['appointment_date'])) : '' ?>">
                            </div>
                            <div class="mb-3">
                              <label class="form-label font-weight-bold">Coordinator Notes</label>
                              <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($r['notes'] ?? '') ?></textarea>
                            </div>
                          </div>
                          <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Updates</button>
                          </div>
                        </form>
                      </div>
                    </div>

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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
