<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/header.php';

// Safe helper to count table rows if table exists
function count_rows_if_exists(mysqli $db, string $table): int {
    $exists = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    if ($exists && $exists->num_rows > 0) {
        $res = $db->query("SELECT COUNT(*) AS c FROM `$table`");
        if ($res) { $row = $res->fetch_assoc(); return (int)($row['c'] ?? 0); }
    }
    return 0;
}

$onlineApplicants = count_rows_if_exists($db, 'online_applicants');
$processedApplicants = count_rows_if_exists($db, 'processed_applicants');
$registrations = count_rows_if_exists($db, 'semester_registration');
$attendanceLogs = count_rows_if_exists($db, 'attendance_logs');

?>

<link rel="stylesheet" href="../css/admin-dashboard.css">

<div class="container-fluid px-4 portal-dashboard">
  <div class="dashboard-header admin-section mb-4">
    <div class="row align-items-center">
      <div class="col">
        <h1 class="dashboard-title"><i class="fas fa-users-cog me-2"></i>Student Management</h1>
        <p class="text-muted">Admissions, registration, records, certificates, and attendance</p>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-xl-3 col-md-6">
      <a href="admissions.php" class="text-decoration-none">
        <div class="stat-card h-100">
          <div class="d-flex align-items-center">
            <div class="stat-icon bg-primary"><i class="fas fa-user-check fa-2x text-white"></i></div>
            <div>
              <h3><?php echo (int)$onlineApplicants; ?></h3>
              <p class="text-muted mb-0">Online Applicants</p>
            </div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-xl-3 col-md-6">
      <a href="registration.php" class="text-decoration-none">
        <div class="stat-card h-100">
          <div class="d-flex align-items-center">
            <div class="stat-icon bg-success"><i class="fas fa-clipboard-list fa-2x text-white"></i></div>
            <div>
              <h3><?php echo (int)$registrations; ?></h3>
              <p class="text-muted mb-0">Registrations</p>
            </div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-xl-3 col-md-6">
      <a href="records.php" class="text-decoration-none">
        <div class="stat-card h-100">
          <div class="d-flex align-items-center">
            <div class="stat-icon bg-info"><i class="fas fa-folder-open fa-2x text-white"></i></div>
            <div>
              <h3><?php echo (int)$processedApplicants; ?></h3>
              <p class="text-muted mb-0">Processed Applicants</p>
            </div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-xl-3 col-md-6">
      <a href="attendance.php" class="text-decoration-none">
        <div class="stat-card h-100">
          <div class="d-flex align-items-center">
            <div class="stat-icon bg-warning"><i class="fas fa-fingerprint fa-2x text-white"></i></div>
            <div>
              <h3><?php echo (int)$attendanceLogs; ?></h3>
              <p class="text-muted mb-0">Attendance Logs</p>
            </div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <div class="row mt-4">
    <div class="col-lg-6">
      <div class="data-table-card h-100">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Quick Actions</h5>
          </div>
        </div>
        <div class="card-body">
          <div class="d-grid gap-2">
            <a class="btn btn-primary" href="admissions.php"><i class="fas fa-user-plus me-2"></i>Review Admissions & Eligibility</a>
            <a class="btn btn-outline-primary" href="registration.php"><i class="fas fa-clipboard-check me-2"></i>Register Students (ZQF Limits & Prereqs)</a>
            <a class="btn btn-outline-secondary" href="records.php"><i class="fas fa-file-certificate me-2"></i>Generate ZAQA Certificates / QMIS Export</a>
            <a class="btn btn-outline-warning" href="attendance.php"><i class="fas fa-user-clock me-2"></i>Manage Attendance & Alerts</a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="data-table-card h-100">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-bullhorn me-2"></i>Notes</h5>
          </div>
        </div>
        <div class="card-body">
          <ul class="mb-0">
            <li>Eligibility checks use O-level credits (>= 5) and merit score.</li>
            <li>Prerequisites and credit limits enforced using configured course rules.</li>
            <li>Attendance threshold is 80% to sit for exams; alerts are issued automatically.</li>
            <li>ZAQA-compliant certificates and QMIS CSV export available in Records.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>



