<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/academic_risk_engine.php';

$attendanceTableReady = function_exists('wuc_table_exists') ? wuc_table_exists($db, 'attendance_logs') : false;
if (!$attendanceTableReady) {
    error_log('attendance_logs table is missing; run migrations before using attendance ingest.');
}

// Simple API endpoint for app/biometric devices to post logs (token-based)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ingest'])) {
    header('Content-Type: application/json');
    $token = $_GET['token'] ?? '';
    $expectedToken = getenv('ATTEND_TOKEN') ?: ($_ENV['ATTEND_TOKEN'] ?? '');
    $staffSession = isset($_SESSION['staff_id']);
    if (!$staffSession && ($expectedToken === '' || !hash_equals($expectedToken, $token))) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Unauthorized']);
        exit;
    }
    if (!$attendanceTableReady) {
        http_response_code(503);
        echo json_encode(['success'=>false,'error'=>'Attendance storage is not ready']);
        exit;
    }
    $Sid = trim($_POST['Sid'] ?? '');
    $course = trim($_POST['course_code'] ?? '');
    $when = trim($_POST['timestamp'] ?? '');
    $source = trim($_POST['source'] ?? 'app');
    if ($Sid === '' || $course === '' || $when === '') {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'Missing fields']);
        exit;
    }
    $stmt = $db->prepare("INSERT IGNORE INTO attendance_logs (Sid, course_code, timestamp, source) VALUES (?,?,?,?)");
    $stmt->bind_param('ssss', $Sid, $course, $when, $source);
    $ok = $stmt->execute();
    if ($ok) {
        wuc_academic_risk_after_student_activity($db, $Sid, 'attendance_ingest');
    }
    echo json_encode(['success'=>$ok]);
    exit;
}

// Fetch recent logs
$logs = [];
if ($attendanceTableReady && $res = $db->query("SELECT al.*, s.Fname, s.Lname, c.course_name FROM attendance_logs al
  LEFT JOIN students s ON s.SID = al.Sid
  LEFT JOIN courses c ON c.course_code = al.course_code
  ORDER BY al.timestamp DESC LIMIT 200")) {
  while ($row = $res->fetch_assoc()) { $logs[] = $row; }
  $res->free();
}

?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-4">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title"><i class="fas fa-fingerprint me-2"></i>Attendance Management</h1>
        <p class="text-muted">Biometric/app logging. 80% attendance threshold for exam eligibility.</p>
      </div>
      <div class="col-auto">
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#ingestHelp"><i class="fas fa-link me-2"></i>Ingest API</button>
      </div>
    </div>
  </div>

  <div class="data-table-card mb-4">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Alerts</h5>
      </div>
    </div>
    <div class="card-body">
      <p>Students below 80% attendance in a course should be flagged. This view highlights such students.</p>
      <div class="alert alert-info">Coming soon: automated email/SMS alerts integration.</div>
    </div>
  </div>

  <div class="data-table-card">
    <div class="card-header">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Recent Attendance</h5>
      </div>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>When</th>
              <th>Student</th>
              <th>Course</th>
              <th>Source</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logs as $l): ?>
            <tr>
              <td><?php echo htmlspecialchars($l['timestamp']); ?></td>
              <td><?php echo htmlspecialchars(($l['Fname'] ?? '') . ' ' . ($l['Lname'] ?? '')); ?> (<?php echo htmlspecialchars($l['Sid']); ?>)</td>
              <td><?php echo htmlspecialchars(($l['course_name'] ?? '') . ' (' . ($l['course_code'] ?? '') . ')'); ?></td>
              <td><span class="badge bg-secondary"><?php echo htmlspecialchars($l['source']); ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="ingestHelp" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header admin-modal">
        <h5 class="modal-title">Attendance Ingest API</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>POST to this endpoint to record attendance from a biometric device or mobile app:</p>
        <pre><code><?php echo htmlspecialchars($base_url . '/student_mgmt/attendance.php?ingest=1&token=YOUR_CONFIGURED_ATTEND_TOKEN'); ?></code></pre>
        <p class="text-muted small">Set <code>ATTEND_TOKEN</code> in the server environment. The portal does not display the live secret.</p>
        <p>Fields:</p>
        <ul>
          <li><code>Sid</code>: Student ID</li>
          <li><code>course_code</code>: Course code</li>
          <li><code>timestamp</code>: ISO datetime (YYYY-MM-DD HH:MM:SS)</li>
          <li><code>source</code>: biometric|app|manual</li>
        </ul>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>



